<?php
session_start();
require_once '../config/db.php';
require_once '../includes/analytics_service.php';
require_role(['hr', 'staff']);
require_staff_position(['hr_staff']);

$hr_id = (int) ($_SESSION['user']['id'] ?? 0);
$hr_name = htmlspecialchars($_SESSION['user']['fullname'] ?? 'HR Lead');

$hr_stmt = $pdo->prepare("\n    SELECT se.shop_id\n    FROM shop_staffs se\n    WHERE se.user_id = ?\n      AND (se.staff_role = 'hr' OR LOWER(REPLACE(se.position, ' ', '_')) = 'hr_staff')\n      AND se.status = 'active'\n    LIMIT 1\n");
$hr_stmt->execute([$hr_id]);
$shop_id = (int) ($hr_stmt->fetchColumn() ?: 0);

if ($shop_id <= 0) {
    die('You are not assigned to any shop as HR.');
}

$overview = fetch_order_analytics($pdo, [$shop_id]);
$staff_count_stmt = $pdo->prepare("\n    SELECT COUNT(*)\n    FROM shop_staffs ss\n    WHERE ss.shop_id = ?\n      AND ss.status = 'active'\n");
$staff_count_stmt->execute([$shop_id]);
$staff_count = (int) $staff_count_stmt->fetchColumn();
$completion_rate = ((float) ($overview['completion_rate'] ?? 0)) * 100;

$staff_status_stmt = $pdo->prepare("SELECT
    SUM(CASE WHEN ss.employment_status = 'active' THEN 1 ELSE 0 END) AS active_staff,
    SUM(CASE WHEN ss.employment_status = 'inactive' THEN 1 ELSE 0 END) AS inactive_staff,
    SUM(CASE WHEN ss.employment_status = 'on_leave' THEN 1 ELSE 0 END) AS on_leave_staff
FROM shop_staffs ss
WHERE ss.shop_id = ?
  AND ss.status = 'active'");
$staff_status_stmt->execute([$shop_id]);
$staff_statuses = $staff_status_stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$assignment_status_stmt = $pdo->prepare("SELECT o.status, COUNT(*) AS total
FROM orders o
WHERE o.shop_id = ?
GROUP BY o.status
ORDER BY total DESC");
$assignment_status_stmt->execute([$shop_id]);
$assignment_status = $assignment_status_stmt->fetchAll(PDO::FETCH_ASSOC);

$workload_stmt = $pdo->prepare("SELECT
    u.id,
    u.fullname,
    COUNT(o.id) AS assigned_orders,
    SUM(CASE WHEN o.status = 'completed' THEN 1 ELSE 0 END) AS completed_orders,
    SUM(CASE WHEN o.status IN ('pending','accepted','digitizing','production_pending','production','qc_pending','production_rework','ready_for_delivery') THEN 1 ELSE 0 END) AS active_orders,
    SUM(CASE WHEN o.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_orders
FROM users u
JOIN shop_staffs ss ON ss.user_id = u.id
LEFT JOIN orders o ON o.assigned_to = u.id AND o.shop_id = ss.shop_id
WHERE ss.shop_id = ?
  AND ss.status = 'active'
GROUP BY u.id, u.fullname
ORDER BY active_orders DESC, assigned_orders DESC
LIMIT 12");
$workload_stmt->execute([$shop_id]);
$workload_by_staff = $workload_stmt->fetchAll(PDO::FETCH_ASSOC);

$ticket_summary_stmt = $pdo->prepare("SELECT
    SUM(CASE WHEN st.status IN ('open','under_review','assigned','in_progress') THEN 1 ELSE 0 END) AS open_tickets,
    SUM(CASE WHEN st.status IN ('resolved','closed') THEN 1 ELSE 0 END) AS resolved_tickets
FROM support_tickets st
JOIN orders o ON o.id = st.order_id
WHERE o.shop_id = ?");
$ticket_summary_stmt->execute([$shop_id]);
$ticket_summary = $ticket_summary_stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$recent_activity_stmt = $pdo->prepare("SELECT al.created_at, al.action, al.entity_type, al.entity_id
FROM audit_logs al
WHERE al.actor_role IN ('hr','staff')
  AND EXISTS (
      SELECT 1
      FROM shop_staffs ss
      WHERE ss.user_id = al.actor_id
        AND ss.shop_id = ?
        AND ss.status = 'active'
  )
ORDER BY al.created_at DESC
LIMIT 10");
$recent_activity_stmt->execute([$shop_id]);
$recent_activity = $recent_activity_stmt->fetchAll(PDO::FETCH_ASSOC);

$kpis = [
    ['label' => 'Total orders', 'value' => number_format($overview['total_orders']), 'icon' => 'fas fa-receipt', 'tone' => 'primary'],
    ['label' => 'Completed orders', 'value' => number_format($overview['completed_orders']), 'icon' => 'fas fa-clipboard-check', 'tone' => 'success'],
    ['label' => 'Active staff headcount', 'value' => number_format((int) ($staff_statuses['active_staff'] ?? 0)), 'icon' => 'fas fa-users', 'tone' => 'info'],
    ['label' => 'Completion rate', 'value' => number_format($completion_rate, 1) . '%', 'icon' => 'fas fa-chart-line', 'tone' => 'warning'],
    ['label' => 'Open support workload', 'value' => number_format((int) ($ticket_summary['open_tickets'] ?? 0)), 'icon' => 'fas fa-life-ring', 'tone' => 'danger'],
    ['label' => 'Resolved tickets', 'value' => number_format((int) ($ticket_summary['resolved_tickets'] ?? 0)), 'icon' => 'fas fa-circle-check', 'tone' => 'success'],
    ['label' => 'On leave staff', 'value' => number_format((int) ($staff_statuses['on_leave_staff'] ?? 0)), 'icon' => 'fas fa-user-clock', 'tone' => 'warning'],
    ['label' => 'Total earnings', 'value' => '₱' . number_format($overview['total_revenue'], 2), 'icon' => 'fas fa-peso-sign', 'tone' => 'primary'],
];
$active_page = 'analytics';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics &amp; Reporting - HR</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .reporting-grid { display:grid; grid-template-columns:repeat(12,1fr); gap:1.5rem; margin:2rem 0; }
        .kpi-card { grid-column: span 3; }
        .span-6 { grid-column: span 6; }
        .span-4 { grid-column: span 4; }
        .span-8 { grid-column: span 8; }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/hr_navbar.php'; ?>
<main class="container">
    <section class="page-header">
        <div>
            <h1>Analytics &amp; Reporting</h1>
            <p class="text-muted">Live workforce and assignment analytics for <?php echo $hr_name; ?>.</p>
        </div>
    </section>

    <section class="reporting-grid">
        <?php foreach ($kpis as $kpi): ?>
            <div class="card kpi-card"><div class="metric"><div><p class="text-muted mb-1"><?php echo $kpi['label']; ?></p><h3 class="mb-0"><?php echo $kpi['value']; ?></h3></div><div class="icon-circle bg-<?php echo $kpi['tone']; ?> text-white"><i class="<?php echo $kpi['icon']; ?>"></i></div></div></div>
        <?php endforeach; ?>

        <div class="card span-8">
            <div class="card-header"><h2>Workload by staff</h2></div>
            <div class="table-responsive"><table><thead><tr><th>Staff</th><th>Assigned</th><th>Active</th><th>Completed</th><th>Cancelled</th><th>Completion %</th></tr></thead><tbody>
            <?php if (empty($workload_by_staff)): ?><tr><td colspan="6" class="text-center text-muted">No staff workload data available.</td></tr>
            <?php else: foreach ($workload_by_staff as $staff): $assigned=(int)$staff['assigned_orders']; $comp=(int)$staff['completed_orders']; $rate=$assigned>0?($comp/$assigned)*100:0; ?>
                <tr><td><?php echo htmlspecialchars($staff['fullname']); ?></td><td><?php echo $assigned; ?></td><td><?php echo (int)$staff['active_orders']; ?></td><td><?php echo $comp; ?></td><td><?php echo (int)$staff['cancelled_orders']; ?></td><td><?php echo number_format($rate,1); ?>%</td></tr>
            <?php endforeach; endif; ?>
            </tbody></table></div>
        </div>

        <div class="card span-4">
            <div class="card-header"><h2>Staff status</h2></div>
            <div class="d-flex flex-column gap-2">
                <div class="alert alert-success mb-0">Active: <?php echo number_format((int) ($staff_statuses['active_staff'] ?? 0)); ?></div>
                <div class="alert alert-warning mb-0">On leave: <?php echo number_format((int) ($staff_statuses['on_leave_staff'] ?? 0)); ?></div>
                <div class="alert alert-secondary mb-0">Inactive: <?php echo number_format((int) ($staff_statuses['inactive_staff'] ?? 0)); ?></div>
                <div class="alert alert-info mb-0">Total staff records: <?php echo number_format((int) $staff_count); ?></div>
            </div>
        </div>

        <div class="card span-6">
            <div class="card-header"><h2>Assignment status distribution</h2></div>
            <div class="table-responsive"><table><thead><tr><th>Status</th><th>Total Orders</th></tr></thead><tbody>
            <?php if (empty($assignment_status)): ?><tr><td colspan="2" class="text-center text-muted">No assignment data.</td></tr>
            <?php else: foreach ($assignment_status as $row): ?><tr><td><?php echo htmlspecialchars($row['status']); ?></td><td><?php echo number_format((int) $row['total']); ?></td></tr><?php endforeach; endif; ?>
            </tbody></table></div>
        </div>

        <div class="card span-6">
            <div class="card-header"><h2>HR/Staff activity summary</h2></div>
            <div class="table-responsive"><table><thead><tr><th>Time</th><th>Action</th><th>Entity</th></tr></thead><tbody>
            <?php if (empty($recent_activity)): ?><tr><td colspan="3" class="text-center text-muted">No recent HR/staff audit activity.</td></tr>
            <?php else: foreach ($recent_activity as $activity): ?><tr><td><?php echo htmlspecialchars(date('M d, Y H:i', strtotime($activity['created_at']))); ?></td><td><?php echo htmlspecialchars($activity['action']); ?></td><td><?php echo htmlspecialchars($activity['entity_type']); ?> #<?php echo (int) ($activity['entity_id'] ?? 0); ?></td></tr><?php endforeach; endif; ?>
            </tbody></table></div>
        </div>
    </section>
</main>
</body>
</html>
