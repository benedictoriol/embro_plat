<?php
session_start();
require_once '../config/db.php';
require_once 'partials.php';
require_role('sys_admin');

$actorId = isset($_SESSION['user']['id']) ? (int) $_SESSION['user']['id'] : null;

$settingSchema = [
    'platform' => [
        'timezone' => ['type' => 'string', 'allowed' => timezone_identifiers_list(), 'label' => 'Timezone'],
        'theme' => ['type' => 'string', 'allowed' => ['light', 'dark'], 'label' => 'Theme'],
    ],
    'order_workflow' => [
        'default_digest_frequency' => ['type' => 'string', 'allowed' => ['daily', 'weekly', 'monthly'], 'label' => 'Digest Frequency'],
        'require_design_approval' => ['type' => 'bool', 'label' => 'Require design approval before production'],
    ],
    'payment' => [
        'default_gateway' => ['type' => 'string', 'allowed' => ['pesopay'], 'label' => 'Default payment gateway'],
        'allow_cod' => ['type' => 'bool', 'label' => 'Allow Cash on Delivery'],
    ],
    'notification' => [
        'critical_alerts_enabled' => ['type' => 'bool', 'label' => 'Enable critical alerts'],
        'weekly_summary_enabled' => ['type' => 'bool', 'label' => 'Weekly summary email'],
    ],
    'moderation' => [
        'auto_hide_flagged_content' => ['type' => 'bool', 'label' => 'Auto-hide flagged content'],
    ],
    'business_rules' => [
        'min_order_quantity' => ['type' => 'int', 'min' => 1, 'max' => 10000, 'label' => 'Minimum order quantity'],
        'max_order_quantity' => ['type' => 'int', 'min' => 1, 'max' => 10000, 'label' => 'Maximum order quantity'],
    ],
];

function sanitize_setting_value(mixed $rawValue, array $schema): mixed {
    $type = $schema['type'] ?? 'string';

    if ($type === 'bool') {
        return (bool) $rawValue;
    }

    if ($type === 'int') {
        $value = (int) $rawValue;
        $value = max((int) ($schema['min'] ?? $value), $value);
        $value = min((int) ($schema['max'] ?? $value), $value);
        return $value;
    }

    $value = sanitize((string) $rawValue);
    if (isset($schema['allowed']) && is_array($schema['allowed']) && !in_array($value, $schema['allowed'], true)) {
        return $schema['allowed'][0] ?? '';
    }

    return $value;
}

$currentSettings = [];
foreach ($settingSchema as $group => $fields) {
    $currentSettings[$group] = system_settings_get_group($pdo, $group);
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $previousSettings = $currentSettings;

    foreach ($settingSchema as $group => $fields) {
        foreach ($fields as $key => $schema) {
            $inputName = $group . '__' . $key;
            $rawValue = $schema['type'] === 'bool'
                ? isset($_POST[$inputName])
                : ($_POST[$inputName] ?? ($currentSettings[$group][$key] ?? null));

            $value = sanitize_setting_value($rawValue, $schema);
            system_setting_set($pdo, $group, $key, $value, $schema['type'], $actorId);
        }
    }

    $updatedSettings = [];
    foreach ($settingSchema as $group => $fields) {
        $updatedSettings[$group] = system_settings_get_group($pdo, $group);
    }

    $minQty = (int) ($updatedSettings['business_rules']['min_order_quantity'] ?? 1);
    $maxQty = (int) ($updatedSettings['business_rules']['max_order_quantity'] ?? 1000);
    if ($minQty > $maxQty) {
        system_setting_set($pdo, 'business_rules', 'max_order_quantity', $minQty, 'int', $actorId);
        $updatedSettings['business_rules']['max_order_quantity'] = $minQty;
    }

    log_audit(
        $pdo,
        $actorId,
        'sys_admin',
        'update_system_settings',
        'system_settings',
        null,
        $previousSettings,
        $updatedSettings
    );
    
    $currentSettings = $updatedSettings;
    $message = 'System settings updated successfully.';
}

$zones = ['Asia/Manila', 'Asia/Singapore', 'UTC', 'America/New_York'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings - System Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .settings-grid { display: grid; grid-template-columns: repeat(12, 1fr); gap: 1.5rem; margin: 2rem 0; }
        .settings-card { grid-column: span 8; }
        .tips-card { grid-column: span 4; }
        .setting-group + .setting-group { margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid var(--gray-200); }
        .toggle-item { border: 1px solid var(--gray-200); border-radius: var(--radius); padding: 0.75rem 1rem; }
    </style>
</head>
<body>
    <?php sys_admin_nav('settings'); ?>

    <div class="container">
        <div class="dashboard-header fade-in">
            <div class="d-flex justify-between align-center">
                <div>
                    <h2>System Settings</h2>
                    <p class="text-muted">Persistent platform-wide configuration grouped by function.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-cog"></i> Live Config</span>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>

        <div class="settings-grid">
            <div class="card settings-card">
                <div class="card-header">
                    <h3><i class="fas fa-sliders-h text-primary"></i> Global Settings</h3>
                    <p class="text-muted">Changes apply system-wide and persist in the database.</p>
                </div>
                <form method="POST">
                    <?php echo csrf_field(); ?>

                    <div class="setting-group">
                        <h4>Platform</h4>
                        <div class="form-group">
                            <label>Timezone</label>
                            <select name="platform__timezone" class="form-control">
                                <?php foreach ($zones as $zone): ?>
                                    <option value="<?php echo $zone; ?>" <?php echo ($currentSettings['platform']['timezone'] ?? '') === $zone ? 'selected' : ''; ?>><?php echo $zone; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Theme</label>
                            <select name="platform__theme" class="form-control">
                                <option value="light" <?php echo ($currentSettings['platform']['theme'] ?? '') === 'light' ? 'selected' : ''; ?>>Light</option>
                                <option value="dark" <?php echo ($currentSettings['platform']['theme'] ?? '') === 'dark' ? 'selected' : ''; ?>>Dark</option>
                            </select>
                        </div>
                    </div>

                        <div class="setting-group">
                        <h4>Order Workflow</h4>
                        <div class="form-group">
                            <label>Digest Frequency</label>
                            <select name="order_workflow__default_digest_frequency" class="form-control">
                                <?php foreach (['daily', 'weekly', 'monthly'] as $freq): ?>
                                    <option value="<?php echo $freq; ?>" <?php echo ($currentSettings['order_workflow']['default_digest_frequency'] ?? '') === $freq ? 'selected' : ''; ?>><?php echo ucfirst($freq); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="toggle-item">
                            <label><input type="checkbox" name="order_workflow__require_design_approval" <?php echo !empty($currentSettings['order_workflow']['require_design_approval']) ? 'checked' : ''; ?>> Require design approval before production</label>
                        </div>
                    </div>

                    <div class="setting-group">
                        <h4>Payment</h4>
                        <div class="form-group">
                            <label>Default Payment Gateway</label>
                            <select name="payment__default_gateway" class="form-control">
                                <option value="pesopay" <?php echo ($currentSettings['payment']['default_gateway'] ?? '') === 'pesopay' ? 'selected' : ''; ?>>PesoPay</option>
                            </select>
                        </div>
                        <div class="toggle-item">
                            <label><input type="checkbox" name="payment__allow_cod" <?php echo !empty($currentSettings['payment']['allow_cod']) ? 'checked' : ''; ?>> Allow Cash on Delivery</label>
                        </div>
                    </div>

                    <div class="setting-group">
                        <h4>Notification &amp; Moderation</h4>
                        <div class="toggle-item"><label><input type="checkbox" name="notification__critical_alerts_enabled" <?php echo !empty($currentSettings['notification']['critical_alerts_enabled']) ? 'checked' : ''; ?>> Enable critical alerts</label></div>
                        <div class="toggle-item mt-2"><label><input type="checkbox" name="notification__weekly_summary_enabled" <?php echo !empty($currentSettings['notification']['weekly_summary_enabled']) ? 'checked' : ''; ?>> Weekly summary email</label></div>
                        <div class="toggle-item mt-2"><label><input type="checkbox" name="moderation__auto_hide_flagged_content" <?php echo !empty($currentSettings['moderation']['auto_hide_flagged_content']) ? 'checked' : ''; ?>> Auto-hide flagged content</label></div>
                    </div>

                    <div class="setting-group">
                        <h4>Business Rules</h4>
                        <div class="form-group">
                            <label>Minimum Order Quantity</label>
                            <input type="number" name="business_rules__min_order_quantity" class="form-control" min="1" max="10000" value="<?php echo (int) ($currentSettings['business_rules']['min_order_quantity'] ?? 1); ?>">
                        </div>
                        <div class="form-group">
                            <label>Maximum Order Quantity</label>
                            <input type="number" name="business_rules__max_order_quantity" class="form-control" min="1" max="10000" value="<?php echo (int) ($currentSettings['business_rules']['max_order_quantity'] ?? 1000); ?>">
                        </div>
                    </div>

                    <div class="text-right mt-3">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Settings</button>
                    </div>
                </form>
            </div>

            <div class="card tips-card">
                <div class="card-header">
                    <h3><i class="fas fa-lightbulb text-warning"></i> Recommendations</h3>
                    <p class="text-muted">Operational best practices while tuning global controls.</p>
                </div>
                <ul class="list-unstyled" style="display: flex; flex-direction: column; gap: 1rem;">
                    <li><strong>Review order workflow rules daily.</strong><p class="text-muted mb-0">Especially design-approval and quantity thresholds.</p></li>
                    <li><strong>Align gateway defaults with payment operations.</strong><p class="text-muted mb-0">Ensure fallback behavior remains consistent.</p></li>
                    <li><strong>Enable alerts for incidents.</strong><p class="text-muted mb-0">Critical alerts should stay on in production.</p></li>
                </ul>
            </div>
        </div>
    </div>

    <?php sys_admin_footer(); ?>
</body>
</html>
