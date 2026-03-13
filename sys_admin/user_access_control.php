<?php
session_start();
require_once '../config/db.php';
require_once 'partials.php';
require_role('sys_admin');

$requiredRoleOrder = ['Owner', 'HR', 'Staff', 'Client'];
$defaultAccessByRole = [
    'Owner' => [
        'description' => 'Manage embroidery business operations.',
        'modules' => [
            'Authentication' => ['Login / Logout', 'Account settings'],
            'Shop Profile Management' => ['Edit shop information', 'Upload shop logo', 'Manage shop description'],
            'Service Catalog Management' => ['Add embroidery services', 'Edit product types', 'Manage design options'],
            'Pricing Management' => ['Set base prices', 'Edit service prices', 'Quotation adjustments'],
            'Order Management' => ['View client orders', 'Accept / reject orders', 'Update order status'],
            'Production Monitoring' => ['Track embroidery progress', 'Assign staff'],
            'Quality Control' => ['Verify completed products', 'Approve final output'],
            'Payment Release Control' => ['Confirm completed orders', 'Release payments'],
            'Supplier Management' => ['Manage materials suppliers', 'Track material costs'],
            'Customer Feedback' => ['View client ratings', 'Respond to reviews'],
            'Dispute Handling' => ['Resolve order complaints'],
            'Analytics' => ['Sales reports', 'Order statistics', 'Monthly revenue'],
            'Community Posts' => ['Post shop updates', 'Communicate with clients'],
            'Notification System' => ['Receive order alerts', 'Staff notifications'],
        ],
    ],
    'HR' => [
        'description' => 'Manage shop workforce and employee assignments.',
        'modules' => [
            'Authentication' => ['Login / Logout'],
            'Employee Management' => ['Create employee accounts', 'Edit employee information', 'Remove employees'],
            'Staff Assignment' => ['Assign employees to orders'],
            'Work Monitoring' => ['Track employee tasks', 'Monitor production progress'],
            'Employee Messaging' => ['Communication with staff'],
            'Performance Monitoring' => ['View staff productivity'],
            'Notifications' => ['Receive staff updates'],
        ],
    ],
    'Staff' => [
        'description' => 'Execute embroidery production tasks.',
        'modules' => [
            'Authentication' => ['Login / Logout'],
            'Assigned Orders' => ['View assigned tasks', 'Accept assignments'],
            'Production Updates' => ['Update order progress', 'Upload work progress'],
            'Task Management' => ['Mark job stages', 'Report completion'],
            'Internal Messaging' => ['Communicate with HR / Owner'],
            'Notifications' => ['Task alerts'],
        ],
    ],
    'Client' => [
        'description' => 'Order embroidery services and interact with shops.',
        'modules' => [
            'Authentication' => ['Register account', 'Login / Logout'],
            'Profile Management' => ['Edit personal details'],
            'Shop Search' => ['Browse embroidery shops', 'DSS recommended shops'],
            'Design Customization' => ['Upload embroidery designs', 'Use 3D design editor'],
            'Order Placement' => ['Request quotations', 'Submit orders'],
            'Order Tracking' => ['View order progress', 'Receive updates'],
            'Payment' => ['Pay for orders', 'View transaction history'],
            'Messaging' => ['Communicate with shop owners'],
            'Ratings & Feedback' => ['Rate completed services', 'Leave reviews'],
            'Community Interaction' => ['Comment on shop posts'],
            'Notifications' => ['Order updates', 'Payment confirmations'],
        ],
    ],
];

$flattenedOptionsByRole = [];
foreach ($defaultAccessByRole as $role => $data) {
    $flattenedOptionsByRole[$role] = [];
    foreach ($data['modules'] as $module => $items) {
        foreach ($items as $item) {
            $flattenedOptionsByRole[$role][] = $module . ': ' . $item;
        }
    }
}

$normalizePermission = static fn($permission): string => trim(strip_tags((string) $permission));
$accessControlSettingKey = 'user_access_control_matrix';

$settingsStmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
$settingsStmt->execute([$accessControlSettingKey]);
$storedMatrixRaw = $settingsStmt->fetchColumn();
$hasStoredMatrix = is_string($storedMatrixRaw) && $storedMatrixRaw !== '';
if ($hasStoredMatrix) {
    $decodedMatrix = json_decode($storedMatrixRaw, true);
    if (is_array($decodedMatrix)) {
        $sessionMatrix = $decodedMatrix;
    }
}
$sessionMatrix = $_SESSION['sys_admin_user_access_control'] ?? [];
$sessionByRole = [];
foreach ($sessionMatrix as $entry) {
    $roleName = $entry['role_name'] ?? '';
    if (is_string($roleName) && isset($defaultAccessByRole[$roleName])) {
        $sessionByRole[$roleName] = $entry;
    }
}

$accessMatrix = [];
foreach ($requiredRoleOrder as $roleName) {
    $saved = $sessionByRole[$roleName] ?? [];
    $default = $defaultAccessByRole[$roleName];

    $savedPermissions = $saved['permissions'] ?? $flattenedOptionsByRole[$roleName];
    if (!is_array($savedPermissions)) {
        $savedPermissions = $flattenedOptionsByRole[$roleName];
    }

    $normalizedPermissions = array_values(array_intersect(
        $flattenedOptionsByRole[$roleName],
        array_map($normalizePermission, $savedPermissions)
    ));

    $accessMatrix[] = [
        'role_name' => $roleName,
        'description' => $default['description'],
        'modules' => $default['modules'],
        'permissions' => $normalizedPermissions,
        'manage' => isset($saved['manage']) ? (bool) $saved['manage'] : true,
    ];
}

$_SESSION['sys_admin_user_access_control'] = $accessMatrix;
$message = '';

$settingsUpsertStmt = $pdo->prepare("
    INSERT INTO system_settings (setting_key, setting_value, updated_by)
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)
");
$currentUserId = $_SESSION['user']['id'] ?? null;
if (!$hasStoredMatrix) {
    $settingsUpsertStmt->execute([$accessControlSettingKey, json_encode($accessMatrix), $currentUserId]);
}

$editingIndex = isset($_GET['edit']) ? (int) $_GET['edit'] : -1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $previousMatrix = $accessMatrix;
    $managedIndex = isset($_POST['manage_role']) ? (int) $_POST['manage_role'] : -1;

    if (isset($accessMatrix[$managedIndex])) {
        $roleName = $accessMatrix[$managedIndex]['role_name'];
        $allowed = $flattenedOptionsByRole[$roleName] ?? [];
        $submittedPermissions = $_POST['permissions'][$managedIndex] ?? [];
        if (!is_array($submittedPermissions)) {
            $submittedPermissions = [];
        }

        $sanitizedPermissions = array_map($normalizePermission, $submittedPermissions);   
        $accessMatrix[$managedIndex]['permissions'] = array_values(array_intersect($allowed, $sanitizedPermissions));
        $accessMatrix[$managedIndex]['manage'] = (($_POST['manage_state'][$managedIndex] ?? 'enabled') === 'enabled');

        $_SESSION['sys_admin_user_access_control'] = $accessMatrix;
        $settingsUpsertStmt->execute([$accessControlSettingKey, json_encode($accessMatrix), $currentUserId]);
        $message = sprintf('%s role access updated successfully.', $roleName);
        $editingIndex = $managedIndex;

        $actorId = $_SESSION['user']['id'] ?? null;
        $actorRole = $_SESSION['user']['role'] ?? 'sys_admin';
        log_audit(
            $pdo,
            $actorId ? (int) $actorId : null,
            $actorRole,
            'update_user_access_control',
            'system_settings',
            null,
            $previousMatrix,
            $accessMatrix
        );
        
        if (isset($_POST['realtime_update'])) {
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => true,
                'message' => sprintf('%s access updated.', $roleName),
                'permission_count' => count($accessMatrix[$managedIndex]['permissions']),
                'manage' => $accessMatrix[$managedIndex]['manage'],
            ]);
            exit;
        }
    } elseif (isset($_POST['realtime_update'])) {
        header('Content-Type: application/json');
        http_response_code(422);
        echo json_encode([
            'ok' => false,
            'message' => 'Unable to update access for the selected role.',
        ]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Access Control - System Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .access-control-wrapper { margin: 2rem 0; }
        .access-table { width: 100%; border-collapse: collapse; }
        .access-table th, .access-table td { padding: 1rem; border-bottom: 1px solid var(--gray-200); vertical-align: top; }
        .access-table th { background: var(--gray-50); }
        .permission-summary { font-size: 0.9rem; color: var(--gray-700); }
        .manage-panel { padding: 1rem; border: 1px solid var(--gray-200); border-radius: var(--radius); background: white; }
        .module-block { margin-bottom: 1rem; }
        .module-title { font-weight: 600; margin-bottom: 0.5rem; color: var(--gray-800); }
        .permission-grid { display: grid; grid-template-columns: repeat(2, minmax(220px, 1fr)); gap: 0.5rem 1rem; }
        .permission-item { display: flex; align-items: center; gap: 0.5rem; font-size: 0.92rem; }
    </style>
</head>
<body>
<?php sys_admin_nav('user_access_control'); ?>
<div class="container">
    <div class="dashboard-header fade-in">
        <div class="d-flex justify-between align-center">
            <div>
                <h2>User Access Control</h2>
                <p class="text-muted">Role access controls arranged per user type inside each Manage panel.</p>
            </div>
            <span class="badge badge-primary"><i class="fas fa-user-shield"></i> Access Matrix</span>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="card access-control-wrapper">
        <div class="card-header">
            <h3><i class="fas fa-lock text-primary"></i> Role Access List</h3>
            <p class="text-muted">Click Manage to configure the arranged module permissions for each role.</p>
        </div>

        <form method="POST" id="access-control-form">
            <?php echo csrf_field(); ?>
            <div class="table-responsive">
                <table class="access-table">
                    <thead>
                    <tr>
                        <th>Role Name</th>
                        <th>Scope</th>
                        <th>Description</th>
                        <th>Manage</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($accessMatrix as $index => $access): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($access['role_name']); ?></strong><br>
                                <small class="<?php echo $access['manage'] ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo $access['manage'] ? 'Enabled' : 'Disabled'; ?>
                                </small>
                            </td>
                            <td class="permission-summary">
                                <span data-permission-count="<?php echo $index; ?>"><?php echo count($access['permissions']); ?></span> permissions selected
                            </td>
                            <td class="text-muted"><?php echo htmlspecialchars($access['description']); ?></td>
                            <td>
                                <a href="user_access_control.php?edit=<?php echo $index; ?>" class="btn btn-outline-primary btn-sm">
                                    <i class="fas fa-pen"></i> Manage
                                </a>
                            </td>
                        </tr>
                        <?php if ($editingIndex === $index): ?>
                            <tr>
                                <td colspan="4">
                                    <div class="manage-panel" data-manage-index="<?php echo $index; ?>">
                                        <?php foreach ($access['modules'] as $module => $items): ?>
                                            <div class="module-block">
                                                <div class="module-title"><?php echo htmlspecialchars($module); ?></div>
                                                <div class="permission-grid">
                                                    <?php foreach ($items as $item): ?>
                                                        <?php $permissionValue = $module . ': ' . $item; ?>
                                                        <label class="permission-item">
                                                            <input
                                                                type="checkbox"
                                                                name="permissions[<?php echo $index; ?>][]"
                                                                value="<?php echo htmlspecialchars($permissionValue); ?>"
                                                                <?php echo in_array($permissionValue, $access['permissions'], true) ? 'checked' : ''; ?>
                                                            >
                                                            <?php echo htmlspecialchars($item); ?>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>

                                        <div class="form-group mt-2">
                                            <label>Role Access State</label>
                                            <select name="manage_state[<?php echo $index; ?>]" class="form-control" data-manage-state="<?php echo $index; ?>">
                                                <option value="enabled" <?php echo $access['manage'] ? 'selected' : ''; ?>>Enabled</option>
                                                <option value="disabled" <?php echo !$access['manage'] ? 'selected' : ''; ?>>Disabled</option>
                                            </select>
                                        </div>

                                        <div class="d-flex gap-2 mt-2">
                                            <button type="submit" class="btn btn-primary btn-sm" name="manage_role" value="<?php echo $index; ?>">
                                                <i class="fas fa-save"></i> Save <?php echo htmlspecialchars($access['role_name']); ?> Access
                                            </button>
                                            <a href="user_access_control.php" class="btn btn-outline-secondary btn-sm">Close</a>
                                            <small class="text-muted" data-realtime-status></small>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </form>
    </div>
</div>

<script>
(() => {
    const form = document.getElementById('access-control-form');
    if (!form) return;

    const panel = document.querySelector('.manage-panel[data-manage-index]');
    if (!panel) return;

    const index = panel.getAttribute('data-manage-index');
    const statusNode = panel.querySelector('[data-realtime-status]');
    const countNode = document.querySelector(`[data-permission-count="${index}"]`);
    let pendingTimer = null;

    const setStatus = (text, kind = 'muted') => {
        if (!statusNode) return;
        statusNode.textContent = text;
        statusNode.className = kind === 'error' ? 'text-danger' : (kind === 'success' ? 'text-success' : 'text-muted');
    };

    const sendRealtimeUpdate = async () => {
        const formData = new FormData();
        const csrf = form.querySelector('input[name="csrf_token"]')?.value || '';
        formData.append('csrf_token', csrf);
        formData.append('manage_role', index);
        formData.append('realtime_update', '1');

        const manageState = panel.querySelector(`[data-manage-state="${index}"]`);
        if (manageState) {
            formData.append(`manage_state[${index}]`, manageState.value);
        }

        panel.querySelectorAll(`input[type="checkbox"][name="permissions[${index}][]"]:checked`).forEach((checkbox) => {
            formData.append(`permissions[${index}][]`, checkbox.value);
        });

        setStatus('Saving...', 'muted');
        try {
            const response = await fetch('user_access_control.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const payload = await response.json();
            if (!response.ok || !payload.ok) {
                throw new Error(payload.message || 'Save failed');
            }

            if (countNode && typeof payload.permission_count === 'number') {
                countNode.textContent = String(payload.permission_count);
            }
            setStatus('Saved', 'success');
        } catch (error) {
            setStatus('Unable to save in real-time.', 'error');
        }
    };

    const queueUpdate = () => {
        if (pendingTimer) {
            window.clearTimeout(pendingTimer);
        }
        pendingTimer = window.setTimeout(sendRealtimeUpdate, 250);
    };

    panel.querySelectorAll(`input[type="checkbox"][name="permissions[${index}][]"]`).forEach((checkbox) => {
        checkbox.addEventListener('change', queueUpdate);
    });

    const manageState = panel.querySelector(`[data-manage-state="${index}"]`);
    if (manageState) {
        manageState.addEventListener('change', queueUpdate);
    }
})();
</script>

<?php sys_admin_footer(); ?>
</body>
</html>
