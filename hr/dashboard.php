<?php
session_start();
require_once '../config/db.php';
require_role(['hr', 'staff']);
require_staff_position(['hr_staff']);

$hr_id = $_SESSION['user']['id'];
$hr_name = htmlspecialchars($_SESSION['user']['fullname'] ?? 'HR Lead');

$hr_stmt = $pdo->prepare("
    SELECT se.shop_id, s.shop_name
    FROM shop_staffs se
    JOIN shops s ON se.shop_id = s.id
    WHERE se.user_id = ? AND (se.staff_role = 'hr' OR LOWER(REPLACE(se.position, ' ', '_')) = 'hr_staff') AND se.status = 'active'
");
$hr_stmt->execute([$hr_id]);
$hr_shop = $hr_stmt->fetch();

if (!$hr_shop) {
    die("You are not assigned to any shop as HR. Please contact your shop owner.");
}

$shop_id = (int) $hr_shop['shop_id'];
$shop_name = $hr_shop['shop_name'];

$expire_stmt = $pdo->prepare("
    UPDATE hiring_posts
    SET status = 'expired'
    WHERE shop_id = ?
      AND expires_at IS NOT NULL
      AND expires_at < NOW()
      AND status IN ('draft', 'live')
");
$expire_stmt->execute([$shop_id]);

$open_posts_stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM hiring_posts
    WHERE shop_id = ?
      AND status = 'live'
      AND (expires_at IS NULL OR expires_at >= NOW())
");
$open_posts_stmt->execute([$shop_id]);
$open_hiring_posts = (int) $open_posts_stmt->fetchColumn();

$payroll_pending_stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM payroll p
    JOIN shop_staffs ss ON ss.user_id = p.staff_id
    WHERE ss.shop_id = ?
      AND ss.status = 'active'
      AND p.status = 'pending'
");
$payroll_pending_stmt->execute([$shop_id]);
$payroll_pending = (int) $payroll_pending_stmt->fetchColumn();

$low_stock_stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM raw_materials
    WHERE shop_id = ?
      AND status = 'active'
      AND min_stock_level IS NOT NULL
      AND current_stock <= min_stock_level
");
$low_stock_stmt->execute([$shop_id]);
$low_stock_alerts = (int) $low_stock_stmt->fetchColumn();

$staff_snapshot_stmt = $pdo->prepare("
    SELECT u.fullname,
           COUNT(o.id) AS completed_orders,
           AVG(TIMESTAMPDIFF(HOUR, o.created_at, o.completed_at)) AS avg_cycle_hours
    FROM orders o
    JOIN users u ON o.assigned_to = u.id
    JOIN shop_staffs ss ON ss.user_id = u.id
    WHERE o.shop_id = ?
      AND o.status = 'completed'
      AND ss.shop_id = ?
      AND ss.status = 'active'
    GROUP BY u.id
    ORDER BY completed_orders DESC
    LIMIT 5
");
$staff_snapshot_stmt->execute([$shop_id, $shop_id]);
$staff_snapshot = $staff_snapshot_stmt->fetchAll();

$pending_payroll_value_stmt = $pdo->prepare("
    SELECT SUM(p.net_salary)
    FROM payroll p
    JOIN shop_staffs ss ON ss.user_id = p.staff_id
    WHERE ss.shop_id = ?
      AND ss.status = 'active'
      AND p.status = 'pending'
");
$pending_payroll_value_stmt->execute([$shop_id]);
$pending_payroll_value = $pending_payroll_value_stmt->fetchColumn();
$pending_payroll_value = $pending_payroll_value ? number_format((float) $pending_payroll_value, 2) : '0.00';
$active_page = 'dashboard';


$assignment_stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM orders
    WHERE shop_id = ?
      AND status IN ('accepted','digitizing','production_pending','production','production_rework','qc_pending','in_progress')
      AND assigned_to IS NULL
");
$assignment_stmt->execute([$shop_id]);
$unassigned_work = (int) $assignment_stmt->fetchColumn();

$attendance_stmt = $pdo->prepare("
    SELECT
        SUM(CASE WHEN DATE(al.clock_in) = CURDATE() THEN 1 ELSE 0 END) AS clocked_today,
        SUM(CASE WHEN DATE(al.clock_in) = CURDATE() AND al.clock_out IS NULL THEN 1 ELSE 0 END) AS currently_clocked_in
    FROM attendance_logs al
    WHERE al.shop_id = ?
");
$attendance_stmt->execute([$shop_id]);
$attendance_stats = $attendance_stmt->fetch(PDO::FETCH_ASSOC) ?: ['clocked_today' => 0, 'currently_clocked_in' => 0];

$workload_stmt = $pdo->prepare("
    SELECT ss.user_id, u.fullname, COUNT(o.id) AS active_jobs
    FROM shop_staffs ss
    JOIN users u ON u.id = ss.user_id
    LEFT JOIN orders o ON o.shop_id = ss.shop_id
        AND o.assigned_to = ss.user_id
        AND o.status IN ('accepted','digitizing','production_pending','production','production_rework','qc_pending','in_progress')
    WHERE ss.shop_id = ? AND ss.status = 'active'
    GROUP BY ss.user_id, u.fullname
    ORDER BY active_jobs DESC, u.fullname ASC
    LIMIT 5
");
$workload_stmt->execute([$shop_id]);
$workload_rows = $workload_stmt->fetchAll(PDO::FETCH_ASSOC);

$design_pending_status = function_exists('order_workflow_design_pending_status')
    ? order_workflow_design_pending_status($pdo)
    : 'pending';

$hr_action_items = [];

$hr_unassigned_stmt = $pdo->prepare("
    SELECT id, order_number, status
    FROM orders
    WHERE shop_id = ?
      AND status IN ('accepted','digitizing','production_pending','production','production_rework','qc_pending','in_progress')
      AND assigned_to IS NULL
    ORDER BY estimated_completion IS NULL ASC, estimated_completion ASC, created_at ASC
    LIMIT 4
");
$hr_unassigned_stmt->execute([$shop_id]);
foreach ($hr_unassigned_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $hr_action_items[] = [
        'priority' => 90,
        'category' => 'Unassigned active work',
        'problem' => 'Active order has no staff assignment.',
        'record' => 'Order #' . ($row['order_number'] ?? $row['id']),
        'action' => 'Assign owner/staff immediately to avoid idle queue time.',
        'next_link' => 'staff_productivity_performance.php',
        'next_label' => 'Assign workload',
    ];
}

$hr_overload_stmt = $pdo->prepare("
    SELECT ss.user_id, u.fullname, COALESCE(ss.max_active_orders, 0) AS max_active_orders, COUNT(o.id) AS active_jobs
    FROM shop_staffs ss
    JOIN users u ON u.id = ss.user_id
    LEFT JOIN orders o ON o.shop_id = ss.shop_id
      AND o.assigned_to = ss.user_id
      AND o.status IN ('accepted','digitizing','production_pending','production','production_rework','qc_pending','in_progress')
    WHERE ss.shop_id = ? AND ss.status = 'active'
    GROUP BY ss.user_id, u.fullname, ss.max_active_orders
    HAVING COUNT(o.id) > COALESCE(ss.max_active_orders, 0)
    ORDER BY (COUNT(o.id) - COALESCE(ss.max_active_orders, 0)) DESC
    LIMIT 4
");
$hr_overload_stmt->execute([$shop_id]);
foreach ($hr_overload_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $hr_action_items[] = [
        'priority' => 84,
        'category' => 'Staff overload',
        'problem' => 'Staff active jobs exceed configured capacity.',
        'record' => ($row['fullname'] ?? 'Staff') . ' · ' . (int) ($row['active_jobs'] ?? 0) . '/' . (int) ($row['max_active_orders'] ?? 0) . ' jobs',
        'action' => 'Rebalance assignments or increase temporary support.',
        'next_link' => 'staff_productivity_performance.php',
        'next_label' => 'Rebalance workload',
    ];
}

$hr_payroll_detail_stmt = $pdo->prepare("
    SELECT p.id, COALESCE(u.fullname, CONCAT('Staff #', p.staff_id)) AS staff_name, p.pay_period_end
    FROM payroll p
    JOIN shop_staffs ss ON ss.user_id = p.staff_id
    LEFT JOIN users u ON u.id = p.staff_id
    WHERE ss.shop_id = ?
      AND ss.status = 'active'
      AND p.status = 'pending'
    ORDER BY p.pay_period_end ASC
    LIMIT 3
");
$hr_payroll_detail_stmt->execute([$shop_id]);
foreach ($hr_payroll_detail_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $hr_action_items[] = [
        'priority' => 80,
        'category' => 'Payroll pending approval',
        'problem' => 'Payroll record is still pending processing.',
        'record' => 'Payroll #' . (int) ($row['id'] ?? 0) . ' · ' . ($row['staff_name'] ?? 'Staff'),
        'action' => 'Validate attendance/output and release payroll.',
        'next_link' => 'payroll_compensation.php',
        'next_label' => 'Open payroll',
    ];
}

$hr_design_block_stmt = $pdo->prepare("
    SELECT o.id, o.order_number, o.status
    FROM orders o
    LEFT JOIN design_approvals da ON da.order_id = o.id
    WHERE o.shop_id = ?
      AND o.status IN ('accepted','digitizing','production_pending')
      AND COALESCE(o.design_approved, 0) = 0
      AND (da.id IS NULL OR da.status IN (?, 'revision'))
    ORDER BY o.created_at ASC
    LIMIT 3
");
$hr_design_block_stmt->execute([$shop_id, $design_pending_status]);
foreach ($hr_design_block_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $hr_action_items[] = [
        'priority' => 77,
        'category' => 'Design approval blocker',
        'problem' => 'Order is waiting for design approval before production.',
        'record' => 'Order #' . ($row['order_number'] ?? $row['id']),
        'action' => 'Coordinate with owner/client to close approval loop.',
        'next_link' => '../owner/production_tracking.php',
        'next_label' => 'Open production approvals',
    ];
}

$hr_support_stmt = $pdo->prepare("
    SELECT st.id, st.issue_type, o.order_number
    FROM support_tickets st
    JOIN orders o ON o.id = st.order_id
    WHERE o.shop_id = ?
      AND st.status IN ('open','under_review','assigned','in_progress')
    ORDER BY st.created_at ASC
    LIMIT 3
");
$hr_support_stmt->execute([$shop_id]);
foreach ($hr_support_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $hr_action_items[] = [
        'priority' => 72,
        'category' => 'Support escalation',
        'problem' => 'Support ticket remains unresolved.',
        'record' => 'Ticket #' . (int) ($row['id'] ?? 0) . ' · Order #' . ($row['order_number'] ?? 'N/A'),
        'action' => 'Coordinate responsible team and close ticket updates.',
        'next_link' => '../owner/support_management.php',
        'next_label' => 'Open support management',
    ];
}

usort($hr_action_items, static function (array $a, array $b): int {
    return ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0);
});
$hr_action_items = array_slice($hr_action_items, 0, 10);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR Dashboard</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .kpi-card {
            grid-column: span 3;
        }

        .kpi-card .metric {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .kpi-card .metric i {
            font-size: 1.5rem;
        }

        .snapshot-card {
            grid-column: span 7;
        }

        .alerts-card {
            grid-column: span 5;
        }

        .snapshot-row {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--gray-200);
        }

        .snapshot-row:last-child {
            border-bottom: none;
        }
        
        .action-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 0.75rem;
            background: #fff;
            margin-bottom: 0.6rem;
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/hr_navbar.php'; ?>


    <main class="container">
        <section class="page-header">
            <div>
                <h1>HR Dashboard</h1>
                <p class="text-muted">Live signals for <?php echo htmlspecialchars($shop_name); ?> hiring, payroll, and staff performance.</p>
            </div>
            <span class="badge">Logged in as <?php echo $hr_name; ?></span>
        </section>

        <section class="dashboard-grid">
            <div class="card kpi-card">
                <div class="metric">
                    <div>
                        <h3>Open hiring posts</h3>
                        <p class="value"><?php echo $open_hiring_posts; ?></p>
                        <p class="text-muted">Live listings</p>
                    </div>
                    <i class="fas fa-briefcase text-primary"></i>
                </div>
            </div>
            <div class="card kpi-card">
                <div class="metric">
                    <div>
                        <h3>Payroll pending</h3>
                        <p class="value"><?php echo $payroll_pending; ?></p>
                        <p class="text-muted">Total ₱<?php echo $pending_payroll_value; ?></p>
                    </div>
                    <i class="fas fa-coins text-warning"></i>
                </div>
            </div>
            <div class="card kpi-card">
                <div class="metric">
                    <div>
                        <h3>Attendance today</h3>
                        <p class="value"><?php echo (int) ($attendance_stats['clocked_today'] ?? 0); ?></p>
                        <p class="text-muted">Currently clocked in: <?php echo (int) ($attendance_stats['currently_clocked_in'] ?? 0); ?></p>
                    </div>
                    <i class="fas fa-triangle-exclamation text-danger"></i>
                </div>
            </div>
            <div class="card kpi-card">
                <div class="metric">
                    <div>
                        <h3>Unassigned active work</h3>
                        <p class="value"><?php echo $unassigned_work; ?></p>
                        <p class="text-muted">Needs assignment</p>
                    </div>
                    <i class="fas fa-star text-success"></i>
                </div>
            </div>

            
            <div class="card" style="grid-column: span 12;">
                <h2>Prioritized HR action queue</h2>
                <p class="text-muted">Operational exceptions sorted by urgency with direct next-step links.</p>
                <?php if (empty($hr_action_items)): ?>
                    <p class="text-muted mb-0">No urgent HR blockers detected.</p>
                <?php else: ?>
                    <?php foreach ($hr_action_items as $item): ?>
                        <div class="action-item">
                            <div class="d-flex justify-between align-center" style="gap:0.8rem; flex-wrap:wrap;">
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

            <div class="card snapshot-card">
                <h2>Staff performance snapshot</h2>
                <?php if (empty($staff_snapshot)): ?>
                    <p class="text-muted">No completed orders tracked yet.</p>
                <?php else: ?>
                    <?php foreach ($staff_snapshot as $staff): ?>
                        <div class="snapshot-row">
                            <div>
                                <strong><?php echo htmlspecialchars($staff['fullname']); ?></strong>
                                <div class="text-muted"><?php echo (int) $staff['completed_orders']; ?> completed orders</div>
                            </div>
                            <div>
                                <strong><?php echo $staff['avg_cycle_hours'] !== null ? number_format((float) $staff['avg_cycle_hours'], 1) : '—'; ?> hrs</strong>
                                <div class="text-muted">Avg cycle</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="card alerts-card">
                <h2>Operational next actions</h2>
                <ul class="list">
                    <li><a href="hiring_management.php">Pending hiring coverage: <?php echo $open_hiring_posts; ?> live posts</a></li>
                    <li><a href="payroll_compensation.php">Payroll to process: <?php echo $payroll_pending; ?> records</a></li>
                    <li><a href="attendance_management.php">Attendance today: <?php echo (int) ($attendance_stats['clocked_today'] ?? 0); ?> logs</a></li>
                    <li><a href="staff_productivity_performance.php">Unassigned active orders: <?php echo $unassigned_work; ?></a></li>
                </ul>
                <hr>
                <h4 class="mb-2">Current workload</h4>
                <?php if (empty($workload_rows)): ?>
                    <p class="text-muted">No active workload records yet.</p>
                <?php else: ?>
                    <?php foreach ($workload_rows as $wl): ?>
                        <div class="snapshot-row">
                            <span><?php echo htmlspecialchars($wl['fullname']); ?></span>
                            <strong><?php echo (int) $wl['active_jobs']; ?> active jobs</strong>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </main>
</body>
</html>
