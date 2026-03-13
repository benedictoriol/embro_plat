<?php
session_start();
require_once '../config/db.php';
require_once 'partials.php';
require_role('sys_admin');

$admin_id = (int) ($_SESSION['user']['id'] ?? 0);
$unread_notifications = fetch_unread_notification_count($pdo, $admin_id);
$success = '';

if(isset($_POST['mark_all_read'])) {
    mark_all_notifications_read($pdo, $admin_id);
    $unread_notifications = 0;
    $success = 'All notifications marked as read.';
}

$notifications = fetch_notifications_for_user($pdo, $admin_id, 100);

function notification_badge_class(string $type): string {
    $map = [
        'order_status' => 'badge-info',
        'payment' => 'badge-success',
        'warning' => 'badge-warning',
        'security_alert' => 'badge-danger',
        'account' => 'badge-primary',
    ];

    return $map[$type] ?? 'badge-secondary';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Notifications</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .notification-card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 18px;
            background: #fff;
            margin-bottom: 14px;
        }
        .notification-card.unread {
            border-left: 4px solid #4f46e5;
            background: #f8fafc;
        }
        .notification-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <?php sys_admin_nav('notifications'); ?>

    <main class="container">
        <section class="page-header">
            <div>
                <h1>Admin Notification Inbox</h1>
                <p class="text-muted">Operational alerts, escalations, and review notifications for system administrators.</p>
            </div>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <button type="submit" name="mark_all_read" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-check-double"></i> Mark all as read
                </button>
            </form>
        </section>

        <?php if($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
        <?php endif; ?>

        <div class="card mb-3">
            <strong><?php echo (int) $unread_notifications; ?> unread notifications</strong>
        </div>

        <?php if(!empty($notifications)): ?>
            <?php foreach($notifications as $notification): ?>
                <div class="notification-card <?php echo !empty($notification['is_read']) ? '' : 'unread'; ?>">
                    <div class="notification-meta">
                        <div>
                            <?php $type = (string) ($notification['type'] ?? 'notice'); ?>
                            <strong><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $type))); ?></strong>
                            <span class="badge <?php echo notification_badge_class($type); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $type))); ?></span>
                        </div>
                        <span class="text-muted small"><?php echo date('M d, Y h:i A', strtotime((string) ($notification['created_at'] ?? 'now'))); ?></span>
                    </div>
                    <p class="text-muted mb-0"><?php echo htmlspecialchars((string) ($notification['message'] ?? '')); ?></p>
                    <?php if(!empty($notification['order_id'])): ?>
                        <div class="text-muted small mt-2">Order #<?php echo htmlspecialchars((string) $notification['order_id']); ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="card">
                <div class="text-center p-4">
                    <i class="fas fa-bell-slash fa-3x text-muted mb-3"></i>
                    <h4>No notifications yet</h4>
                    <p class="text-muted">Alerts and automation updates will appear here as they are generated.</p>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <?php sys_admin_footer(); ?>
</body>
</html>
