<?php
session_start();
require_once '../config/db.php';
require_once 'partials.php';
require_role('sys_admin');

$stats = $pdo->query("SELECT
    (SELECT COUNT(*) FROM users) AS total_users,
    (SELECT COUNT(*) FROM users WHERE status = 'pending') AS pending_users,
    (SELECT COUNT(*) FROM users WHERE status IN ('inactive','rejected')) AS user_issues,
    (SELECT COUNT(*) FROM shops) AS total_shops,
    (SELECT COUNT(*) FROM shops WHERE status = 'pending') AS pending_shops,
    (SELECT COUNT(*) FROM orders) AS total_orders,
    (SELECT COUNT(*) FROM orders WHERE status IN ('pending','accepted','digitizing','production_pending','production','qc_pending','production_rework','ready_for_delivery')) AS open_orders,
    (SELECT COALESCE(SUM(price),0) FROM orders WHERE status = 'completed') AS total_revenue,
    (SELECT COUNT(*) FROM support_tickets WHERE status NOT IN ('resolved','closed')) AS unresolved_tickets,
    (SELECT COUNT(*) FROM order_exceptions WHERE status IN ('open','in_progress','escalated')) AS open_exceptions,
    (SELECT COUNT(*) FROM order_exceptions WHERE status IN ('open','in_progress','escalated') AND severity = 'critical') AS critical_exceptions,
    (SELECT COUNT(*) FROM users WHERE status = 'pending' AND role = 'owner') AS pending_owner_approvals,
    (SELECT COUNT(*) FROM users WHERE status = 'pending' AND role IN ('client','staff','hr')) AS pending_member_approvals,
    (SELECT COUNT(*) FROM orders WHERE status IN ('accepted','digitizing','production_pending','production','production_rework','qc_pending')
        AND estimated_completion IS NOT NULL AND estimated_completion < NOW()) AS overdue_orders,
    (SELECT COUNT(*) FROM payments WHERE status IN ('pending_verification','failed')) AS payment_verification_issues,
    (SELECT COUNT(*) FROM raw_materials WHERE status = 'active' AND min_stock_level IS NOT NULL AND current_stock <= min_stock_level) AS low_stock_items,
    (SELECT COUNT(*) FROM content_reports WHERE status IN ('pending','reviewing')) AS abuse_flags,
    (SELECT COUNT(*) FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        AND (action LIKE '%delete%' OR action LIKE '%reject%' OR action LIKE '%escalat%' OR action LIKE '%override%')) AS audit_anomalies")->fetch(PDO::FETCH_ASSOC) ?: [];

$recent_audit_stmt = $pdo->query("SELECT a.created_at, a.action, a.entity_type, a.entity_id, COALESCE(u.fullname, CONCAT('User #', a.actor_id)) AS actor
FROM audit_logs a
LEFT JOIN users u ON u.id = a.actor_id
ORDER BY a.created_at DESC
LIMIT 8");
$recent_audits = $recent_audit_stmt->fetchAll(PDO::FETCH_ASSOC);

$pending_shop_stmt = $pdo->query("SELECT s.id, s.shop_name, s.created_at, u.fullname AS owner_name
FROM shops s
JOIN users u ON u.id = s.owner_id
WHERE s.status = 'pending'
ORDER BY s.created_at ASC
LIMIT 6");
$pending_shops = $pending_shop_stmt->fetchAll(PDO::FETCH_ASSOC);

$ticket_stmt = $pdo->query("SELECT st.id, st.issue_type, st.status, st.created_at, o.order_number
FROM support_tickets st
LEFT JOIN orders o ON o.id = st.order_id
WHERE st.status NOT IN ('resolved','closed')
ORDER BY st.created_at ASC
LIMIT 6");
$open_tickets = $ticket_stmt->fetchAll(PDO::FETCH_ASSOC);

$exception_stmt = $pdo->query("SELECT oe.id, oe.exception_type, oe.severity, oe.status, oe.created_at, o.order_number
FROM order_exceptions oe
JOIN orders o ON o.id = oe.order_id
WHERE oe.status IN ('open','in_progress','escalated')
ORDER BY FIELD(oe.severity, 'critical','high','medium','low'), oe.created_at ASC
LIMIT 6");
$open_exception_rows = $exception_stmt->fetchAll(PDO::FETCH_ASSOC);

$rawMaterialLabelExpr = function_exists('raw_material_label_column')
    ? raw_material_label_column($pdo)
    : 'name';
$rawMaterialLabelExprWithAlias = function_exists('raw_material_label_column')
    ? raw_material_label_column($pdo, 'rm')
    : 'rm.name';

$overdue_order_stmt = $pdo->query("SELECT o.id, o.order_number, s.shop_name, o.status, o.estimated_completion
FROM orders o
JOIN shops s ON s.id = o.shop_id
WHERE o.status IN ('accepted','digitizing','production_pending','production','production_rework','qc_pending')
  AND o.estimated_completion IS NOT NULL
  AND o.estimated_completion < NOW()
ORDER BY o.estimated_completion ASC
LIMIT 6");
$overdue_orders = $overdue_order_stmt ? $overdue_order_stmt->fetchAll(PDO::FETCH_ASSOC) : [];

$payment_verification_stmt = $pdo->query("SELECT p.id, p.status, p.amount, p.created_at, o.order_number, s.shop_name
FROM payments p
JOIN orders o ON o.id = p.order_id
JOIN shops s ON s.id = o.shop_id
WHERE p.status IN ('pending_verification','failed')
ORDER BY p.created_at ASC
LIMIT 6");
$payment_verification_rows = $payment_verification_stmt ? $payment_verification_stmt->fetchAll(PDO::FETCH_ASSOC) : [];

$raw_materials_has_updated_at = function_exists('column_exists') && column_exists($pdo, 'raw_materials', 'updated_at');
$low_stock_time_column = $raw_materials_has_updated_at ? 'updated_at' : 'created_at';

$low_stock_stmt = $pdo->query("SELECT id, name AS material_name, current_stock, min_stock_level, {$low_stock_time_column} AS material_timestamp
FROM raw_materials
WHERE status = 'active'
  AND min_stock_level IS NOT NULL
  AND current_stock <= min_stock_level
ORDER BY (min_stock_level - current_stock) DESC, {$low_stock_time_column} ASC
LIMIT 6");
$low_stock_rows = $low_stock_stmt ? $low_stock_stmt->fetchAll(PDO::FETCH_ASSOC) : [];

$cron_status_rows = [];
$failed_cron_jobs = 0;
if (function_exists('table_exists') && table_exists($pdo, 'dss_logs')) {
    $cron_actions = [
        'cron_exception_automation' => 30,
        'cron_notification_reminders' => 60,
        'cron_recalculated_shop_metrics' => 90,
    ];

    $cron_latest_stmt = $pdo->prepare("SELECT created_at
    FROM dss_logs
    WHERE action = ?
    ORDER BY created_at DESC
    LIMIT 1");

    foreach ($cron_actions as $action => $staleMinutes) {
        $cron_latest_stmt->execute([$action]);
        $lastRun = $cron_latest_stmt->fetchColumn();
        $isStale = true;
        if ($lastRun) {
            $isStale = (strtotime((string) $lastRun) < strtotime('-' . (int) $staleMinutes . ' minutes'));
        }
        if ($isStale) {
            $failed_cron_jobs++;
        }
        $cron_status_rows[] = [
            'action' => $action,
            'last_run' => $lastRun,
            'stale_minutes' => $staleMinutes,
            'is_stale' => $isStale,
        ];
    }
}


$ops_action_items = [];

$design_block_stmt = $pdo->query("SELECT o.id, o.order_number, s.shop_name, COALESCE(da.status, 'pending') AS approval_status
FROM orders o
JOIN shops s ON s.id = o.shop_id
LEFT JOIN design_approvals da ON da.order_id = o.id
WHERE o.status IN ('accepted','digitizing','production_pending')
  AND COALESCE(o.design_approved, 0) = 0
  AND (da.id IS NULL OR da.status IN ('pending','pending_review','revision'))
ORDER BY o.created_at ASC
LIMIT 4");
foreach (($design_block_stmt ? $design_block_stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
    $ops_action_items[] = [
        'priority' => 96,
        'category' => 'Orders blocked by design approval',
        'problem' => 'Design approval is unresolved, blocking production start.',
        'record' => 'Order #' . ($row['order_number'] ?? $row['id']) . ' · ' . ($row['shop_name'] ?? 'Shop'),
        'action' => 'Follow up owner/client approval workflow.',
        'next_link' => 'analytics_reporting.php',
        'next_label' => 'Open workflow analytics',
    ];
}

$unpaid_stmt = $pdo->query("SELECT o.id, o.order_number, s.shop_name, o.payment_status, o.estimated_completion
FROM orders o
JOIN shops s ON s.id = o.shop_id
WHERE o.payment_status IN ('unpaid','pending_verification','failed')
  AND o.status IN ('accepted','digitizing','production_pending','production','production_rework','qc_pending')
  AND o.estimated_completion IS NOT NULL
  AND o.estimated_completion <= DATE_ADD(NOW(), INTERVAL 3 DAY)
ORDER BY o.estimated_completion ASC
LIMIT 4");
foreach (($unpaid_stmt ? $unpaid_stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
    $ops_action_items[] = [
        'priority' => 92,
        'category' => 'Unpaid orders near deadline',
        'problem' => 'Payment is not settled while deadline is near.',
        'record' => 'Order #' . ($row['order_number'] ?? $row['id']) . ' · ' . ($row['shop_name'] ?? 'Shop'),
        'action' => 'Escalate payment verification and notify accountable shop.',
        'next_link' => 'analytics_reporting.php',
        'next_label' => 'Review payment risk',
    ];
}

$delayed_stmt = $pdo->query("SELECT o.id, o.order_number, s.shop_name, o.status
FROM orders o
JOIN shops s ON s.id = o.shop_id
WHERE o.status IN ('accepted','digitizing','production_pending','production','production_rework','qc_pending')
  AND o.estimated_completion IS NOT NULL
  AND o.estimated_completion < NOW()
ORDER BY o.estimated_completion ASC
LIMIT 4");
foreach (($delayed_stmt ? $delayed_stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
    $ops_action_items[] = [
        'priority' => 88,
        'category' => 'Delayed production jobs',
        'problem' => 'Order ETA is missed and still in active workflow.',
        'record' => 'Order #' . ($row['order_number'] ?? $row['id']) . ' · ' . ($row['shop_name'] ?? 'Shop'),
        'action' => 'Inspect queue bottlenecks and require corrective ETA plan.',
        'next_link' => 'analytics_reporting.php',
        'next_label' => 'Inspect delayed jobs',
    ];
}

$qc_fail_stmt = $pdo->query("SELECT o.id, o.order_number, s.shop_name
FROM order_quality_checks oqc
JOIN orders o ON o.id = oqc.order_id
JOIN shops s ON s.id = o.shop_id
WHERE oqc.qc_status = 'failed'
  AND o.status IN ('qc_pending','production_rework')
ORDER BY COALESCE(oqc.checked_at, oqc.created_at) DESC
LIMIT 4");
foreach (($qc_fail_stmt ? $qc_fail_stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
    $ops_action_items[] = [
        'priority' => 83,
        'category' => 'QC failures needing rework',
        'problem' => 'Failed QC outcome is unresolved.',
        'record' => 'Order #' . ($row['order_number'] ?? $row['id']) . ' · ' . ($row['shop_name'] ?? 'Shop'),
        'action' => 'Require rework completion and follow-up quality pass.',
        'next_link' => 'analytics_reporting.php',
        'next_label' => 'Open QC risk view',
    ];
}

$material_stmt = $pdo->query("SELECT o.id, o.order_number, s.shop_name, {$rawMaterialLabelExprWithAlias} AS material_name
FROM order_material_reservations omr
JOIN orders o ON o.id = omr.order_id
JOIN shops s ON s.id = o.shop_id
JOIN raw_materials rm ON rm.id = omr.material_id
WHERE omr.status = 'reserved'
  AND rm.status = 'active'
  AND rm.min_stock_level IS NOT NULL
  AND rm.current_stock <= rm.min_stock_level
  AND o.status IN ('accepted','digitizing','production_pending','production','production_rework')
ORDER BY o.estimated_completion IS NULL ASC, o.estimated_completion ASC
LIMIT 4");
foreach (($material_stmt ? $material_stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
    $ops_action_items[] = [
        'priority' => 80,
        'category' => 'Low-stock materials affecting orders',
        'problem' => 'Reserved order material is already at low stock level.',
        'record' => 'Order #' . ($row['order_number'] ?? $row['id']) . ' · ' . ($row['material_name'] ?? 'Material') . ' · ' . ($row['shop_name'] ?? 'Shop'),
        'action' => 'Prompt replenishment or allocation adjustments.',
        'next_link' => 'analytics_reporting.php',
        'next_label' => 'Open inventory impact',
    ];
}

$staff_load_stmt = $pdo->query("SELECT ss.user_id, u.fullname, s.shop_name, COALESCE(ss.max_active_orders, 0) AS max_active_orders, COUNT(o.id) AS active_jobs
FROM shop_staffs ss
JOIN users u ON u.id = ss.user_id
JOIN shops s ON s.id = ss.shop_id
LEFT JOIN orders o ON o.shop_id = ss.shop_id
 AND o.assigned_to = ss.user_id
 AND o.status IN ('accepted','digitizing','production_pending','production','production_rework','qc_pending','in_progress')
WHERE ss.status = 'active'
GROUP BY ss.user_id, u.fullname, s.shop_name, ss.max_active_orders
HAVING COUNT(o.id) > COALESCE(ss.max_active_orders, 0)
ORDER BY (COUNT(o.id) - COALESCE(ss.max_active_orders, 0)) DESC
LIMIT 4");
foreach (($staff_load_stmt ? $staff_load_stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
    $ops_action_items[] = [
        'priority' => 78,
        'category' => 'Staff overload',
        'problem' => 'Active jobs exceed staff capacity configuration.',
        'record' => ($row['fullname'] ?? 'Staff') . ' · ' . ($row['shop_name'] ?? 'Shop') . ' · ' . (int)($row['active_jobs'] ?? 0) . '/' . (int)($row['max_active_orders'] ?? 0),
        'action' => 'Require workload rebalance or temporary staffing support.',
        'next_link' => 'analytics_reporting.php',
        'next_label' => 'Review staffing load',
    ];
}

$pending_approvals_stmt = $pdo->query("SELECT u.id, u.fullname, u.role, u.created_at
FROM users u
WHERE u.status = 'pending'
ORDER BY u.created_at ASC
LIMIT 4");
foreach (($pending_approvals_stmt ? $pending_approvals_stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
    $ops_action_items[] = [
        'priority' => 76,
        'category' => 'Pending approvals',
        'problem' => 'Account approval is still waiting in queue.',
        'record' => ($row['fullname'] ?? ('User #' . (int)($row['id'] ?? 0))) . ' · ' . strtoupper((string)($row['role'] ?? 'member')),
        'action' => 'Approve/reject membership to avoid workflow delays.',
        'next_link' => 'member_approval.php',
        'next_label' => 'Open member approvals',
    ];
}

foreach ($open_tickets as $ticket) {
    $ops_action_items[] = [
        'priority' => 74,
        'category' => 'Unresolved support/dispute',
        'problem' => 'Support/dispute ticket remains unresolved.',
        'record' => 'Ticket #' . (int) ($ticket['id'] ?? 0) . ' · Order #' . ($ticket['order_number'] ?? 'N/A'),
        'action' => 'Assign owner and enforce resolution update.',
        'next_link' => 'analytics.php',
        'next_label' => 'Open support analytics',
    ];
}

usort($ops_action_items, static function(array $a, array $b): int {
    return ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0);
});
$ops_action_items = array_slice($ops_action_items, 0, 12);

$trend_stmt = $pdo->query("SELECT DATE(created_at) AS day,
SUM(1) AS registrations,
SUM(CASE WHEN role = 'owner' THEN 1 ELSE 0 END) AS owner_registrations
FROM users
WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
GROUP BY DATE(created_at)");
$trend_rows = $trend_stmt->fetchAll(PDO::FETCH_ASSOC);
$trend_map = [];
foreach ($trend_rows as $row) {
    $trend_map[$row['day']] = $row;
}
$trend = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i day"));
    $trend[] = [
        'label' => date('D', strtotime($day)),
        'registrations' => (int) ($trend_map[$day]['registrations'] ?? 0),
        'owners' => (int) ($trend_map[$day]['owner_registrations'] ?? 0),
    ];
}
$max_reg = max(array_column($trend, 'registrations')) ?: 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Admin Dashboard</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .dashboard-grid { display:grid; grid-template-columns:repeat(12,1fr); gap:1.25rem; margin:1.5rem 0 2rem; }
        .card-wide { grid-column: span 8; }
        .card-narrow { grid-column: span 4; }
        
        .ops-item { border:1px solid var(--gray-200); border-radius: var(--radius); padding:.75rem; margin-bottom:.6rem; background:#fff; }
        .ops-item:last-child { margin-bottom:0; }
        .ops-kpi-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:1rem; margin-bottom:1.25rem; }
        .ops-kpi-card { border:1px solid var(--gray-200); border-radius: var(--radius); padding:1rem; background:#fff; }
        .ops-kpi-card h4 { margin:0; font-size:1.5rem; }
        .ops-kpi-card p { margin:.35rem 0 0; color:var(--gray-600); }
    </style>
</head>
<body>
<?php sys_admin_nav('dashboard'); ?>
<div class="container">
    <div class="dashboard-header fade-in">
        <div class="d-flex justify-between align-center">
            <div>
                <h2>System Overview</h2>
                <p class="text-muted">Welcome back, <?php echo htmlspecialchars($_SESSION['user']['fullname']); ?>. Action items are based on live platform data.</p>
            </div>
            <button class="btn btn-sm btn-outline-primary" onclick="location.reload()"><i class="fas fa-sync-alt"></i> Refresh</button>
        </div>
    </div>

        <div class="stats-grid mb-4">
        <div class="stat-card"><h3><?php echo number_format((int) ($stats['total_users'] ?? 0)); ?></h3><p>Total users</p></div>
        <div class="stat-card"><h3><?php echo number_format((int) ($stats['pending_shops'] ?? 0)); ?></h3><p>Pending shop approvals</p></div>
        <div class="stat-card"><h3><?php echo number_format((int) ($stats['unresolved_tickets'] ?? 0)); ?></h3><p>Unresolved support tickets</p></div>
        <div class="stat-card"><h3><?php echo number_format((int) ($stats['open_exceptions'] ?? 0)); ?></h3><p>Open exceptions</p></div>
    </div>

    
    <div class="ops-kpi-grid">
        <a class="ops-kpi-card" href="member_approval.php" style="text-decoration:none; color:inherit;">
            <h4><?php echo number_format((int) (($stats['pending_owner_approvals'] ?? 0) + ($stats['pending_shops'] ?? 0))); ?></h4>
            <p>Pending owner/shop approvals</p>
        </a>
        <a class="ops-kpi-card" href="analytics_reporting.php" style="text-decoration:none; color:inherit;">
            <h4><?php echo number_format((int) ($stats['overdue_orders'] ?? 0)); ?></h4>
            <p>Overdue active orders</p>
        </a>
        <a class="ops-kpi-card" href="analytics_reporting.php" style="text-decoration:none; color:inherit;">
            <h4><?php echo number_format((int) ($stats['open_exceptions'] ?? 0)); ?></h4>
            <p>Unresolved disputes/exceptions</p>
        </a>
        <a class="ops-kpi-card" href="notification_reminder.php" style="text-decoration:none; color:inherit;">
            <h4><?php echo number_format((int) ($failed_cron_jobs ?? 0)); ?></h4>
            <p>Failed/stale scheduled jobs</p>
        </a>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h3><i class="fas fa-triangle-exclamation text-danger"></i> Actionable summaries</h3></div>
        <div class="d-flex gap-3" style="flex-wrap:wrap;">
            <a class="alert alert-warning" style="flex:1; min-width:250px; text-decoration:none;" href="member_approval.php"><strong>Pending approvals</strong><p class="mb-0"><?php echo (int) ($stats['pending_users'] ?? 0); ?> users and <?php echo (int) ($stats['pending_shops'] ?? 0); ?> shops</p></a>
            <a class="alert alert-info" style="flex:1; min-width:250px; text-decoration:none;" href="accounts.php"><strong>User/account issues</strong><p class="mb-0"><?php echo (int) ($stats['user_issues'] ?? 0); ?> inactive or rejected accounts</p></a>
            <a class="alert alert-danger" style="flex:1; min-width:250px; text-decoration:none;" href="content_moderation.php"><strong>Operational risk</strong><p class="mb-0"><?php echo (int) ($stats['open_exceptions'] ?? 0); ?> exceptions, <?php echo (int) ($stats['unresolved_tickets'] ?? 0); ?> disputes, <?php echo (int) ($stats['abuse_flags'] ?? 0); ?> abuse flags</p></a>
            <a class="alert alert-secondary" style="flex:1; min-width:250px; text-decoration:none;" href="audit_logs.php"><strong>Audit anomalies</strong><p class="mb-0"><?php echo (int) ($stats['audit_anomalies'] ?? 0); ?> high-risk events in the last 24 hours</p></a>
        </div>
    </div>

    
    <div class="card mb-4">
        <div class="card-header"><h3><i class="fas fa-list-check text-primary"></i> Prioritized operational action queue</h3></div>
        <p class="text-muted">Each row states what is wrong, affected record, required action, and destination module.</p>
        <?php if (empty($ops_action_items)): ?>
            <p class="text-muted mb-0">No urgent cross-platform actions found.</p>
        <?php else: ?>
            <?php foreach ($ops_action_items as $item): ?>
                <div class="ops-item">
                    <div class="d-flex justify-between align-center" style="gap:0.75rem; flex-wrap:wrap;">
                        <strong><?php echo htmlspecialchars((string) ($item['category'] ?? 'Action')); ?></strong>
                        <span class="badge badge-warning">Priority <?php echo (int) ($item['priority'] ?? 0); ?></span>
                    </div>
                    <div><strong>What is wrong:</strong> <?php echo htmlspecialchars((string) ($item['problem'] ?? '')); ?></div>
                    <div><strong>Affected record:</strong> <?php echo htmlspecialchars((string) ($item['record'] ?? '')); ?></div>
                    <div><strong>Action:</strong> <?php echo htmlspecialchars((string) ($item['action'] ?? '')); ?></div>
                    <div class="mt-2"><a class="btn btn-sm btn-outline-primary" href="<?php echo htmlspecialchars((string) ($item['next_link'] ?? '#')); ?>"><?php echo htmlspecialchars((string) ($item['next_label'] ?? 'Open')); ?></a></div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

        <div class="dashboard-grid">
        <div class="card card-wide">
            <div class="card-header"><h3>Pending shop approvals</h3></div>
            <div class="table-responsive"><table><thead><tr><th>Shop</th><th>Owner</th><th>Submitted</th><th></th></tr></thead><tbody>
                <?php if (empty($pending_shops)): ?><tr><td colspan="4" class="text-center text-muted">No pending shops.</td></tr>
                <?php else: foreach ($pending_shops as $shop): ?><tr>
                    <td><?php echo htmlspecialchars($shop['shop_name']); ?></td>
                    <td><?php echo htmlspecialchars($shop['owner_name']); ?></td>
                    <td><?php echo htmlspecialchars(date('M d, Y H:i', strtotime($shop['created_at']))); ?></td>
                    <td><a class="btn btn-sm btn-primary" href="member_approval.php">Review</a></td>
                </tr><?php endforeach; endif; ?>
            </tbody></table></div>
        </div>

            <div class="card card-narrow">
            <div class="card-header"><h3>Abuse & critical exceptions</h3></div>
            <p class="text-muted mb-2">Platform moderation flags and critical unresolved exceptions.</p>
            <h2><?php echo number_format((int) ($stats['abuse_flags'] ?? 0)); ?> flags</h2>
            <p class="mb-2">Critical exceptions: <strong><?php echo number_format((int) ($stats['critical_exceptions'] ?? 0)); ?></strong></p>
            <div class="d-flex" style="gap:8px;">
                <a href="content_moderation.php" class="btn btn-warning btn-sm">Moderation queue</a>
                <a href="analytics_reporting.php" class="btn btn-outline-primary btn-sm">Analytics</a>
            </div>
        </div>

            <div class="card card-wide">
            <div class="card-header"><h3>Unresolved disputes / support tickets</h3></div>
            <div class="table-responsive"><table><thead><tr><th>Ticket</th><th>Order</th><th>Status</th><th>Opened</th><th></th></tr></thead><tbody>
                <?php if (empty($open_tickets)): ?><tr><td colspan="5" class="text-center text-muted">No unresolved support tickets.</td></tr>
                <?php else: foreach ($open_tickets as $ticket): ?><tr>
                    <td>#<?php echo (int) $ticket['id']; ?> - <?php echo htmlspecialchars($ticket['issue_type']); ?></td>
                    <td><?php echo htmlspecialchars($ticket['order_number'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($ticket['status']); ?></td>
                    <td><?php echo htmlspecialchars(date('M d, Y H:i', strtotime($ticket['created_at']))); ?></td>
                    <td><a href="analytics.php" class="btn btn-sm btn-outline-primary">Inspect</a></td>
                </tr><?php endforeach; endif; ?>
            </tbody></table></div>
        </div>

            <div class="card card-narrow">
            <div class="card-header"><h3>Open exceptions</h3></div>
            <div class="d-flex flex-column gap-2">
                <?php if (empty($open_exception_rows)): ?><p class="text-muted mb-0">No open order exceptions.</p>
                <?php else: foreach ($open_exception_rows as $ex): ?>
                    <div class="alert alert-<?php echo $ex['severity'] === 'critical' || $ex['severity'] === 'high' ? 'danger' : 'warning'; ?> mb-0">
                        <strong><?php echo htmlspecialchars($ex['exception_type']); ?></strong>
                        <p class="mb-0">Order <?php echo htmlspecialchars($ex['order_number']); ?> · <?php echo htmlspecialchars($ex['status']); ?></p>
                        <a href="analytics_reporting.php" class="btn btn-sm btn-outline-light mt-1">Investigate</a>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        
        <div class="card card-wide">
            <div class="card-header"><h3>Overdue orders</h3></div>
            <div class="table-responsive"><table><thead><tr><th>Order</th><th>Shop</th><th>Status</th><th>ETA</th><th></th></tr></thead><tbody>
                <?php if (empty($overdue_orders)): ?><tr><td colspan="5" class="text-center text-muted">No overdue active orders.</td></tr>
                <?php else: foreach ($overdue_orders as $order): ?><tr>
                    <td>#<?php echo htmlspecialchars($order['order_number'] ?? (string) $order['id']); ?></td>
                    <td><?php echo htmlspecialchars($order['shop_name'] ?? 'Shop'); ?></td>
                    <td><?php echo htmlspecialchars($order['status']); ?></td>
                    <td><?php echo htmlspecialchars(date('M d, Y H:i', strtotime((string) $order['estimated_completion']))); ?></td>
                    <td><a class="btn btn-sm btn-outline-primary" href="analytics_reporting.php">Track</a></td>
                </tr><?php endforeach; endif; ?>
            </tbody></table></div>
        </div>

        <div class="card card-narrow">
            <div class="card-header"><h3>Payment verification queue</h3></div>
            <?php if (empty($payment_verification_rows)): ?>
                <p class="text-muted mb-0">No failed or pending verification payment items.</p>
            <?php else: ?>
                <?php foreach ($payment_verification_rows as $payment): ?>
                    <div class="ops-item">
                        <strong>Order #<?php echo htmlspecialchars($payment['order_number'] ?? 'N/A'); ?></strong>
                        <div><?php echo htmlspecialchars($payment['shop_name'] ?? 'Shop'); ?></div>
                        <div>Status: <span class="badge badge-<?php echo ($payment['status'] ?? '') === 'failed' ? 'danger' : 'warning'; ?>"><?php echo htmlspecialchars($payment['status']); ?></span></div>
                        <div>Amount: ₱<?php echo number_format((float) ($payment['amount'] ?? 0), 2); ?></div>
                        <a class="btn btn-sm btn-outline-primary mt-1" href="analytics_reporting.php">Review payment</a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="card card-wide">
            <div class="card-header"><h3>Low stock alerts</h3></div>
            <div class="table-responsive"><table><thead><tr><th>Material</th><th>Current</th><th>Minimum</th><th>Updated</th><th></th></tr></thead><tbody>
                <?php if (empty($low_stock_rows)): ?><tr><td colspan="5" class="text-center text-muted">No low-stock materials in alert state.</td></tr>
                <?php else: foreach ($low_stock_rows as $material): ?><tr>
                    <td><?php echo htmlspecialchars($material['material_name']); ?></td>
                    <td><?php echo number_format((float) ($material['current_stock'] ?? 0), 2); ?></td>
                    <td><?php echo number_format((float) ($material['min_stock_level'] ?? 0), 2); ?></td>
                    <td><?php echo htmlspecialchars(date('M d, Y H:i', strtotime((string) $material['material_timestamp']))); ?></td>
                    <td><a class="btn btn-sm btn-outline-primary" href="analytics_reporting.php">Inspect</a></td>
                </tr><?php endforeach; endif; ?>
            </tbody></table></div>
        </div>

        <div class="card card-narrow">
            <div class="card-header"><h3>Scheduled job health</h3></div>
            <?php if (empty($cron_status_rows)): ?>
                <p class="text-muted mb-0">Cron execution logs are not available (dss_logs missing).</p>
            <?php else: ?>
                <?php foreach ($cron_status_rows as $cron): ?>
                    <div class="ops-item">
                        <strong><?php echo htmlspecialchars(str_replace('_', ' ', (string) $cron['action'])); ?></strong>
                        <div>Last run: <?php echo $cron['last_run'] ? htmlspecialchars(date('M d, Y H:i', strtotime((string) $cron['last_run']))) : 'Never logged'; ?></div>
                        <div><span class="badge badge-<?php echo $cron['is_stale'] ? 'danger' : 'success'; ?>"><?php echo $cron['is_stale'] ? 'Stale/failed' : 'Healthy'; ?></span> · SLA <?php echo (int) $cron['stale_minutes']; ?>m</div>
                    </div>
                <?php endforeach; ?>
                <a href="notification_reminder.php" class="btn btn-sm btn-outline-primary">Open notification module</a>
            <?php endif; ?>
        </div>

        <div class="card card-wide">
            <div class="card-header"><h3>Recent audit events</h3></div>
            <div class="table-responsive"><table><thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Target</th></tr></thead><tbody>
                <?php if (empty($recent_audits)): ?><tr><td colspan="4" class="text-center text-muted">No audit events available.</td></tr>
                <?php else: foreach ($recent_audits as $audit): ?><tr>
                    <td><?php echo htmlspecialchars(date('M d, Y H:i', strtotime($audit['created_at']))); ?></td>
                    <td><?php echo htmlspecialchars($audit['actor'] ?? 'System'); ?></td>
                    <td><?php echo htmlspecialchars($audit['action']); ?></td>
                    <td><?php echo htmlspecialchars($audit['entity_type']); ?> #<?php echo (int) ($audit['entity_id'] ?? 0); ?></td>
                </tr><?php endforeach; endif; ?>
            </tbody></table></div>
        </div>

        <div class="card card-narrow">
            <div class="card-header"><h3>7-day registrations</h3></div>
            <div class="d-flex justify-between align-end" style="height:180px; border-bottom:1px solid var(--gray-200);">
                <?php foreach ($trend as $d): $h = max(10, (int) (($d['registrations'] / $max_reg) * 160)); ?>
                    <div class="d-flex flex-column align-center" style="flex:1;">
                        <div class="bg-primary-100 rounded" style="width:28px;height:<?php echo $h; ?>px;" title="<?php echo $d['registrations']; ?> registrations"></div>
                        <small class="text-muted mt-1"><?php echo $d['label']; ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="text-muted mt-2 mb-0">Total orders: <?php echo number_format((int) ($stats['total_orders'] ?? 0)); ?> · Open orders: <?php echo number_format((int) ($stats['open_orders'] ?? 0)); ?> · Audit anomalies: <?php echo number_format((int) ($stats['audit_anomalies'] ?? 0)); ?></p>
        </div>
    </div>
</div>
<?php sys_admin_footer(); ?>
</body>
</html>