<?php
session_start();
require_once '../config/db.php';
require_role(['hr', 'staff']);
require_staff_position(['hr_staff']);

$hr_id = $_SESSION['user']['id'];
$success = '';

$notification_options = [
    'order_status' => 'Order status updates',
    'payment' => 'Payment updates',
    'info' => 'General information',
    'success' => 'Success confirmations',
    'warning' => 'Warnings & issues',
    'danger' => 'Critical alerts',
    'security_alert' => 'Security alerts'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $enabled_keys = $_POST['preferences'] ?? [];
    foreach ($notification_options as $event_key => $label) {
        $enabled = in_array($event_key, $enabled_keys, true) ? 1 : 0;
        $pref_stmt = $pdo->prepare("SELECT id FROM notification_preferences WHERE user_id = ? AND event_key = ? AND channel = 'in_app' LIMIT 1");
        $pref_stmt->execute([$hr_id, $event_key]);
        $pref_id = $pref_stmt->fetchColumn();

        if ($pref_id) {
            $update_stmt = $pdo->prepare("UPDATE notification_preferences SET enabled = ? WHERE id = ?");
            $update_stmt->execute([$enabled, $pref_id]);
        } else {
            $insert_stmt = $pdo->prepare("INSERT INTO notification_preferences (user_id, event_key, channel, enabled) VALUES (?, ?, 'in_app', ?)");
            $insert_stmt->execute([$hr_id, $event_key, $enabled]);
        }
    }
    $success = 'Notification preferences updated.';
}

$preferences = array_fill_keys(array_keys($notification_options), 1);
$pref_stmt = $pdo->prepare("SELECT event_key, enabled FROM notification_preferences WHERE user_id = ? AND channel = 'in_app'");
$pref_stmt->execute([$hr_id]);
foreach ($pref_stmt->fetchAll() as $row) {
    if (array_key_exists($row['event_key'], $preferences)) {
        $preferences[$row['event_key']] = (int) $row['enabled'];
    }
}
$active_page = 'preferences';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification Preferences</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .preference-card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 18px;
            background: #fff;
            margin-bottom: 14px;
        }
        .toggle {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 26px;
        }
        .toggle input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background-color: #cbd5f5;
            transition: 0.2s;
            border-radius: 30px;
        }
        .slider:before {
            position: absolute;
            content: '';
            height: 20px;
            width: 20px;
            left: 4px;
            bottom: 3px;
            background-color: #fff;
            transition: 0.2s;
            border-radius: 50%;
        }
        .toggle input:checked + .slider {
            background-color: #4f46e5;
        }
        .toggle input:checked + .slider:before {
            transform: translateX(22px);
        }
    </style>
</head>
<body>
    <?php
$current_page = basename($_SERVER['PHP_SELF'] ?? 'dashboard.php');
$active_page = $active_page ?? match ($current_page) {
    'dashboard.php' => 'dashboard',
    'hiring_management.php' => 'hiring',
    'create_staff.php' => 'create_staff',
    'attendance_management.php' => 'attendance',
    'staff_productivity_performance.php' => 'productivity',
    'payroll_compensation.php' => 'payroll',
    'analytics_reporting.php' => 'analytics',
    'notification_preferences.php' => 'preferences',
    default => '',
};

$hr_name = htmlspecialchars($_SESSION['user']['fullname'] ?? 'HR Manager');
$shop_name = htmlspecialchars($shop_name ?? 'HR Portal');
$items = [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'fas fa-chart-line', 'href' => 'dashboard.php'],
    ['key' => 'hiring', 'label' => 'Hiring', 'icon' => 'fas fa-user-plus', 'href' => 'hiring_management.php'],
    ['key' => 'create_staff', 'label' => 'Create Staff', 'icon' => 'fas fa-id-card', 'href' => 'create_staff.php'],
    ['key' => 'attendance', 'label' => 'Attendance', 'icon' => 'fas fa-user-check', 'href' => 'attendance_management.php'],
    ['key' => 'productivity', 'label' => 'Productivity', 'icon' => 'fas fa-chart-column', 'href' => 'staff_productivity_performance.php'],
    ['key' => 'payroll', 'label' => 'Payroll', 'icon' => 'fas fa-money-check-dollar', 'href' => 'payroll_compensation.php'],
    ['key' => 'analytics', 'label' => 'Analytics', 'icon' => 'fas fa-chart-pie', 'href' => 'analytics_reporting.php'],
    ['key' => 'preferences', 'label' => 'Preferences', 'icon' => 'fas fa-bell', 'href' => 'notification_preferences.php'],
];
?>
<nav class="navbar navbar--compact">
    <div class="container d-flex justify-between align-center">
        <div class="d-flex align-center gap-4">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-user-gear"></i>
                <span>HR Portal</span>
            </a>
            <div class="d-flex gap-2">
                <span class="badge badge-primary">Online</span>
                <span class="text-muted"><?php echo $shop_name; ?></span>
            </div>
        </div>

        <ul class="navbar-nav">
            <?php foreach ($items as $item): ?>
                <li>
                    <a href="<?php echo $item['href']; ?>" class="nav-link <?php echo $active_page === $item['key'] ? 'active' : ''; ?>">
                        <i class="<?php echo $item['icon']; ?>"></i> <?php echo $item['label']; ?>
                    </a>
                </li>
            <?php endforeach; ?>
            <li>
                <a href="../auth/logout.php" class="nav-link">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </li>
        </ul>
    </div>
</nav>


    <div class="container">
        <div class="dashboard-header">
            <h2>Notification Preferences</h2>
            <p class="text-muted">Choose which HR notifications should surface in your dashboard.</p>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
        <?php endif; ?>

        <form method="POST">
            <?php echo csrf_field(); ?>
            <?php foreach ($notification_options as $event_key => $label): ?>
                <div class="preference-card d-flex justify-between align-center">
                    <div>
                        <strong><?php echo htmlspecialchars($label); ?></strong>
                        <p class="text-muted mb-0">Control whether <?php echo htmlspecialchars(strtolower($label)); ?> are shown.</p>
                    </div>
                    <label class="toggle">
                        <input type="checkbox" name="preferences[]" value="<?php echo htmlspecialchars($event_key); ?>" <?php echo $preferences[$event_key] ? 'checked' : ''; ?>>
                        <span class="slider"></span>
                    </label>
                </div>
            <?php endforeach; ?>
            <button type="submit" class="btn btn-primary">Save preferences</button>
        </form>
    </div>
</body>
</html>
