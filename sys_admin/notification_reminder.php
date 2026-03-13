<?php
session_start();
require_once '../config/db.php';
require_once 'partials.php';
require_role('sys_admin');

$run_result = null;
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_reminders_now'])) {
    $run_result = run_notification_reminders($pdo, false);
}

$preview = run_notification_reminders($pdo, true);

$event_counts_stmt = $pdo->query("\n    SELECT type, COUNT(*) AS total\n    FROM notifications\n    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)\n    GROUP BY type\n    ORDER BY total DESC\n    LIMIT 8\n");
$event_counts = $event_counts_stmt->fetchAll(PDO::FETCH_ASSOC);

$pending_quotes = (int) ($preview['stale_pending_quotes'] ?? 0);
$pending_payments = (int) ($preview['unpaid_orders'] ?? 0);
$production_alerts = (int) (($preview['overdue_production'] ?? 0) + ($preview['overdue_orders'] ?? 0));
$pickup_alerts = (int) ($preview['ready_for_pickup_unclaimed'] ?? 0);

$notification_kpis = [
    [
        'label' => 'Active notification flows',
        'value' => (string) count($event_counts),
        'note' => 'Distinct notification types in the last 7 days.',
        'icon' => 'fas fa-bell',
        'tone' => 'primary',
    ],
    [
        'label' => 'Pending approvals',
        'value' => (string) $pending_quotes,
        'note' => 'Stale quote approvals due for reminder.',
        'icon' => 'fas fa-clipboard-check',
        'tone' => 'warning',
    ],
    [
        'label' => 'Production alerts',
        'value' => (string) $production_alerts,
        'note' => 'Overdue production and overdue order alerts.',
        'icon' => 'fas fa-triangle-exclamation',
        'tone' => 'danger',
    ],
    [
        'label' => 'Pickup reminders',
        'value' => (string) $pickup_alerts,
        'note' => 'Ready-for-pickup orders not yet claimed.',
        'icon' => 'fas fa-box-open',
        'tone' => 'success',
    ],
];

$delivery_channels = [
    [
        'title' => 'Multi-channel delivery',
        'detail' => 'In-app notifications are active and can be extended to other channels later.',
        'icon' => 'fas fa-paper-plane',
    ],
    [
        'title' => 'Escalation paths',
        'detail' => 'Automated reminders are throttled by configurable cooldown hours.',
        'icon' => 'fas fa-arrow-up-right-dots',
    ],
    [
        'title' => 'Quiet hours',
        'detail' => 'Reminder timing windows are configurable via system settings.',
        'icon' => 'fas fa-moon',
    ],
];

$reminder_streams = [
    [
        'title' => 'Approval reminders',
        'detail' => 'Notify clients and owners when stale quote approvals remain pending.',
        'tone' => 'primary',
        'time' => 'Threshold: ' . (int) ($preview['settings']['stale_quote_hours'] ?? 24) . 'h',
    ],
    [
        'title' => 'Production alerts',
        'detail' => 'Flag overdue production and overdue active orders.',
        'tone' => 'danger',
        'time' => 'Threshold: ' . (int) ($preview['settings']['overdue_production_hours'] ?? 12) . 'h',
    ],
    [
        'title' => 'Payment and pickup reminders',
        'detail' => 'Notify unpaid active orders and ready-for-pickup orders not yet claimed.',
        'tone' => 'success',
        'time' => 'Cooldown: ' . (int) ($preview['settings']['reminder_cooldown_hours'] ?? 24) . 'h',
    ],
];

$notification_queue = [];
foreach($event_counts as $row) {
    $notification_queue[] = [
        'recipient' => 'In-app recipients',
        'event' => ucfirst(str_replace('_', ' ', (string) ($row['type'] ?? 'event'))),
        'channel' => 'In-app',
        'status' => (string) ($row['total'] ?? '0') . ' in last 7 days',
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification &amp; Reminder Module - System Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .notification-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .kpi-card {
            grid-column: span 3;
        }

        .purpose-card,
        .automation-card {
            grid-column: span 12;
        }

        .channel-card,
        .reminder-card,
        .queue-card {
            grid-column: span 4;
        }

        .channel-tile,
        .reminder-item,
        .queue-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
        }

        .channel-tile + .channel-tile,
        .reminder-item + .reminder-item,
        .queue-item + .queue-item {
            margin-top: 1rem;
        }

        .queue-item .meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem 1rem;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <?php sys_admin_nav('notification_reminder'); ?>

    <main class="container">
        <section class="page-header">
            <div>
                <h1>Notification &amp; Reminder Module</h1>
                <p class="text-muted">Keeps users informed across workflows with automated reminders and alerts.</p>
            </div>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <button type="submit" name="run_reminders_now" class="btn btn-primary btn-sm"><i class="fas fa-play"></i> Run reminders now</button>
            </form>
        </section>

        <?php if($run_result): ?>
            <div class="alert alert-success">
                Reminder run completed. Created <?php echo (int) ($run_result['notifications_created'] ?? 0); ?> notifications.
            </div>
        <?php endif; ?>

        <section class="notification-grid">
            <?php foreach ($notification_kpis as $kpi): ?>
                <div class="card kpi-card">
                    <div class="metric">
                        <div>
                            <p class="text-muted mb-1"><?php echo $kpi['label']; ?></p>
                            <h3 class="mb-1"><?php echo $kpi['value']; ?></h3>
                            <small class="text-muted"><?php echo $kpi['note']; ?></small>
                        </div>
                        <div class="icon-circle bg-<?php echo $kpi['tone']; ?> text-white">
                            <i class="<?php echo $kpi['icon']; ?>"></i>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="card purpose-card">
                <h2>Purpose</h2>
                <p class="text-muted mb-0">
                    Centralizes notification automation so approvals, production progress, and hiring workflows
                    stay on time with the right stakeholders.
                </p>
            </div>

            <div class="card channel-card">
                <h2>Delivery controls</h2>
                <?php foreach ($delivery_channels as $channel): ?>
                    <div class="channel-tile">
                        <h3 class="mb-1"><i class="<?php echo $channel['icon']; ?> text-primary"></i> <?php echo $channel['title']; ?></h3>
                        <p class="text-muted mb-0"><?php echo $channel['detail']; ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card reminder-card">
                <h2>Live reminder streams</h2>
                <?php foreach ($reminder_streams as $stream): ?>
                    <div class="reminder-item">
                        <div class="d-flex justify-between align-center">
                            <h3 class="mb-1"><?php echo $stream['title']; ?></h3>
                            <span class="badge badge-<?php echo $stream['tone']; ?>"><?php echo $stream['time']; ?></span>
                        </div>
                        <p class="text-muted mb-0"><?php echo $stream['detail']; ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card queue-card">
                <h2>Upcoming notifications</h2>
                <?php foreach ($notification_queue as $item): ?>
                    <div class="queue-item">
                        <h3 class="mb-1"><?php echo $item['event']; ?></h3>
                        <div class="meta text-muted">
                            <span><i class="fas fa-user"></i> <?php echo $item['recipient']; ?></span>
                            <span><i class="fas fa-satellite-dish"></i> <?php echo $item['channel']; ?></span>
                            <span class="badge badge-outline"><?php echo $item['status']; ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card automation-card">
                <h2>Automation coverage</h2>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Automation</th>
                                <th>Trigger</th>
                                <th>Outcome</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Approval reminders</td>
                                <td>Quotes remain in pending/sent state beyond threshold</td>
                                <td>Notifies client and owner with cooldown protection</td>
                            </tr>
                            <tr>
                                <td>Production alerts</td>
                                <td>Overdue production windows and overdue orders</td>
                                <td>Routes alerts to client, owner, and assigned staff</td>
                            </tr>
                            <tr>
                                <td>Payment + pickup reminders</td>
                                <td>Unpaid active orders and ready pickup not yet claimed</td>
                                <td>Sends in-app reminders with duplicate throttling</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>

    <?php sys_admin_footer(); ?>
</body>
</html>
