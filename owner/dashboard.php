<?php
session_start();

require_once '../config/db.php';
require_once '../config/scheduling_helpers.php';
require_once '../config/inventory_helpers.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];


$shop_stmt = $pdo->prepare("SELECT * FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch();


if(!$shop) {
    header("Location: create_shop.php");
    exit();
}

$shop_id = $shop['id'];
$rawMaterialLabelExprWithAlias = function_exists('raw_material_label_column')
    ? raw_material_label_column($pdo, 'rm')
    : 'rm.name';

// Get shop statistics
$stats_stmt = $pdo->prepare("
    SELECT 
        (SELECT COUNT(*) FROM orders WHERE shop_id = ?) as total_orders,
        (SELECT COUNT(*) FROM orders WHERE shop_id = ? AND status = 'pending') as pending_orders,
        (SELECT COUNT(*) FROM orders WHERE shop_id = ? AND status IN ('accepted','digitizing','production_pending','production','production_rework','qc_pending','ready_for_delivery','delivered','in_progress')) as active_orders,
        (SELECT COUNT(*) FROM orders WHERE shop_id = ? AND status = 'completed') as completed_orders,
        (SELECT SUM(price) FROM orders WHERE shop_id = ? AND status = 'completed') as total_earnings,
        (SELECT COUNT(*) FROM shop_staffs WHERE shop_id = ? AND status = 'active') as total_staff,
        (SELECT COUNT(*) FROM orders WHERE shop_id = ? AND status = 'accepted') as accepted_orders,
        (SELECT COUNT(*) FROM orders WHERE shop_id = ? AND status = 'cancelled') as cancelled_orders,
        (SELECT COUNT(*) FROM orders WHERE shop_id = ? AND status = 'qc_pending') as qc_pending_orders,
        (SELECT COUNT(*) FROM orders WHERE shop_id = ? AND status = 'production_rework') as rework_orders,
        (SELECT COUNT(*) FROM raw_materials WHERE shop_id = ? AND min_stock_level IS NOT NULL AND current_stock <= min_stock_level) as low_stock_materials,
        (SELECT COUNT(*) FROM order_material_reservations WHERE shop_id = ? AND status = 'reserved') as reserved_material_orders
");
$stats_stmt->execute([$shop_id, $shop_id, $shop_id, $shop_id, $shop_id, $shop_id, $shop_id, $shop_id, $shop_id, $shop_id, $shop_id, $shop_id]);
$stats = $stats_stmt->fetch();

// Recent orders
$orders_stmt = $pdo->prepare("
    SELECT o.*, u.fullname as client_name 
    FROM orders o 
    JOIN users u ON o.client_id = u.id 
    WHERE o.shop_id = ? 
    ORDER BY o.created_at DESC 
    LIMIT 5
");
$orders_stmt->execute([$shop_id]);
$recent_orders = $orders_stmt->fetchAll();

$completion_rate = $stats['total_orders'] > 0
    ? ($stats['completed_orders'] / $stats['total_orders'] * 100)
    : 0;
    
$machine_schedule_stmt = $pdo->prepare("
    SELECT
        mj.id,
        mj.order_id,
        mj.estimated_stitches,
        mj.scheduled_start,
        mj.scheduled_end,
        mj.status,
        m.machine_name,
        m.max_stitches_per_hour,
        o.order_number,
        TIMESTAMPDIFF(MINUTE, mj.scheduled_start, mj.scheduled_end) AS duration_minutes
    FROM machine_jobs mj
    JOIN machines m ON m.id = mj.machine_id
    JOIN orders o ON o.id = mj.order_id
    WHERE m.shop_id = ?
    ORDER BY mj.scheduled_start ASC
    LIMIT 8
");
$machine_schedule_stmt->execute([$shop_id]);
$machine_schedule = $machine_schedule_stmt->fetchAll();



$exception_stats_stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS open_exceptions,
        SUM(CASE WHEN oe.status = 'escalated' THEN 1 ELSE 0 END) AS escalated_exceptions,
        SUM(CASE WHEN oe.status IN ('open','in_progress','escalated') AND oe.severity IN ('high','critical') THEN 1 ELSE 0 END) AS blocking_exceptions
    FROM order_exceptions oe
    JOIN orders o ON o.id = oe.order_id
    WHERE o.shop_id = ?
      AND oe.status IN ('open','in_progress','escalated')
");
$exception_stats_stmt->execute([$shop_id]);
$exception_stats = $exception_stats_stmt->fetch() ?: ['open_exceptions' => 0, 'escalated_exceptions' => 0, 'blocking_exceptions' => 0];

$production_queue = function_exists('fetch_production_queue')
    ? fetch_production_queue($pdo, (int) $shop_id)
    : [];

    
$next_action_stmt = $pdo->prepare("
    SELECT
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_approvals,
        SUM(CASE WHEN payment_status = 'pending_verification' THEN 1 ELSE 0 END) AS payments_to_verify,
        SUM(CASE WHEN status IN ('accepted','digitizing','production_pending','production','production_rework','qc_pending')
             AND estimated_completion IS NOT NULL
             AND estimated_completion < NOW() THEN 1 ELSE 0 END) AS production_bottlenecks
    FROM orders
    WHERE shop_id = ?
");
$next_action_stmt->execute([$shop_id]);
$next_actions = $next_action_stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$qc_issue_stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM order_quality_checks oqc
    JOIN orders o ON o.id = oqc.order_id
    WHERE o.shop_id = ?
      AND oqc.qc_status IN ('pending','failed')
");
$qc_issue_stmt->execute([$shop_id]);
$qc_issues = (int) $qc_issue_stmt->fetchColumn();

$bottleneck_rows_stmt = $pdo->prepare("
    SELECT id, order_number, service_type, estimated_completion, status
    FROM orders
    WHERE shop_id = ?
      AND status IN ('accepted','digitizing','production_pending','production','production_rework','qc_pending')
      AND estimated_completion IS NOT NULL
      AND estimated_completion < NOW()
    ORDER BY estimated_completion ASC
    LIMIT 5
");
$bottleneck_rows_stmt->execute([$shop_id]);
$bottleneck_rows = $bottleneck_rows_stmt->fetchAll(PDO::FETCH_ASSOC);

$design_pending_status = function_exists('order_workflow_design_pending_status')
    ? order_workflow_design_pending_status($pdo)
    : 'pending';

$dashboard_action_items = [];

$design_blocked_stmt = $pdo->prepare("\n    SELECT o.id, o.order_number, o.status, da.status AS design_status\n    FROM orders o\n    LEFT JOIN design_approvals da ON da.order_id = o.id\n    WHERE o.shop_id = ?\n      AND o.status IN ('accepted','digitizing','production_pending')\n      AND COALESCE(o.design_approved, 0) = 0\n      AND (da.id IS NULL OR da.status IN (?, 'revision'))\n    ORDER BY o.estimated_completion IS NULL ASC, o.estimated_completion ASC, o.created_at ASC\n    LIMIT 3\n");
$design_blocked_stmt->execute([$shop_id, $design_pending_status]);
foreach ($design_blocked_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $dashboard_action_items[] = [
        'priority' => 95,
        'category' => 'Design approval block',
        'problem' => 'Production cannot start because the design is not approved.',
        'record' => 'Order #' . ($row['order_number'] ?? $row['id']),
        'action' => 'Upload/revise proof or follow up client approval.',
        'next_link' => 'production_tracking.php',
        'next_label' => 'Open production tracking',
    ];
}

$unpaid_deadline_stmt = $pdo->prepare("\n    SELECT id, order_number, payment_status, estimated_completion\n    FROM orders\n    WHERE shop_id = ?\n      AND payment_status IN ('unpaid','pending_verification','failed')\n      AND status IN ('accepted','digitizing','production_pending','production','production_rework','qc_pending')\n      AND estimated_completion IS NOT NULL\n      AND estimated_completion <= DATE_ADD(NOW(), INTERVAL 3 DAY)\n    ORDER BY estimated_completion ASC\n    LIMIT 3\n");
$unpaid_deadline_stmt->execute([$shop_id]);
foreach ($unpaid_deadline_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $dashboard_action_items[] = [
        'priority' => 90,
        'category' => 'Payment risk',
        'problem' => 'Order is near deadline without cleared payment.',
        'record' => 'Order #' . ($row['order_number'] ?? $row['id']),
        'action' => 'Verify payment or contact client before deadline is missed.',
        'next_link' => 'payment_verifications.php',
        'next_label' => 'Open payment verification',
    ];
}

foreach ($bottleneck_rows as $row) {
    $dashboard_action_items[] = [
        'priority' => 85,
        'category' => 'Delayed production',
        'problem' => 'Production ETA is already overdue.',
        'record' => 'Order #' . ($row['order_number'] ?? $row['id']),
        'action' => 'Re-prioritize queue, assign staff, or update delivery commitment.',
        'next_link' => 'view_order.php?id=' . (int) ($row['id'] ?? 0),
        'next_label' => 'Open order',
    ];
}

$qc_fail_stmt = $pdo->prepare("\n    SELECT o.id, o.order_number, oqc.created_at\n    FROM order_quality_checks oqc\n    JOIN orders o ON o.id = oqc.order_id\n    WHERE o.shop_id = ?\n      AND oqc.qc_status = 'failed'\n      AND o.status IN ('qc_pending','production_rework')\n    ORDER BY COALESCE(oqc.checked_at, oqc.created_at) DESC\n    LIMIT 3\n");
$qc_fail_stmt->execute([$shop_id]);
foreach ($qc_fail_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $dashboard_action_items[] = [
        'priority' => 80,
        'category' => 'QC rework required',
        'problem' => 'Quality check failed and needs production rework.',
        'record' => 'Order #' . ($row['order_number'] ?? $row['id']),
        'action' => 'Review QC remarks and relaunch rework.',
        'next_link' => 'quality_control.php',
        'next_label' => 'Open QC module',
    ];
}

$material_impact_stmt = $pdo->prepare("\n    SELECT o.id, o.order_number, {$rawMaterialLabelExprWithAlias} AS material_name\n    FROM order_material_reservations omr\n    JOIN orders o ON o.id = omr.order_id\n    JOIN raw_materials rm ON rm.id = omr.material_id\n    WHERE omr.shop_id = ?\n      AND omr.status = 'reserved'\n      AND rm.status = 'active'\n      AND rm.min_stock_level IS NOT NULL\n      AND rm.current_stock <= rm.min_stock_level\n      AND o.status IN ('accepted','digitizing','production_pending','production','production_rework')\n    ORDER BY o.estimated_completion IS NULL ASC, o.estimated_completion ASC\n    LIMIT 3\n");
$material_impact_stmt->execute([$shop_id]);
foreach ($material_impact_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $dashboard_action_items[] = [
        'priority' => 78,
        'category' => 'Material shortage risk',
        'problem' => 'Reserved material is low-stock and can stall fulfillment.',
        'record' => 'Order #' . ($row['order_number'] ?? $row['id']) . ' · ' . ($row['material_name'] ?? 'Material'),
        'action' => 'Replenish material or substitute before production stops.',
        'next_link' => 'raw_material_inventory.php',
        'next_label' => 'Open inventory',
    ];
}

$support_dispute_stmt = $pdo->prepare("\n    SELECT st.id, st.issue_type, st.order_id, o.order_number\n    FROM support_tickets st\n    LEFT JOIN orders o ON o.id = st.order_id\n    WHERE o.shop_id = ?\n      AND st.status IN ('open','under_review','assigned','in_progress')\n    ORDER BY st.created_at ASC\n    LIMIT 3\n");
$support_dispute_stmt->execute([$shop_id]);
foreach ($support_dispute_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $dashboard_action_items[] = [
        'priority' => 72,
        'category' => 'Client support/dispute',
        'problem' => 'Ticket is unresolved and may delay order closure.',
        'record' => 'Ticket #' . (int) ($row['id'] ?? 0) . ' · Order #' . ($row['order_number'] ?? ((int) ($row['order_id'] ?? 0))),
        'action' => 'Respond to the issue and update ticket disposition.',
        'next_link' => 'support_management.php',
        'next_label' => 'Open support queue',
    ];
}

usort($dashboard_action_items, static function (array $a, array $b): int {
    return ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0);
});

$dashboard_action_items = array_slice($dashboard_action_items, 0, 12);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner Dashboard - <?php echo htmlspecialchars($shop['shop_name']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .shop-header {
            background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
            color: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 25px;
        }
        .shop-rating {
            background: rgba(255,255,255,0.2);
            padding: 8px 20px;
            border-radius: 25px;
            display: inline-block;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin: 25px 0;
        }
        .content-stack {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }
        .stat-card {
            background: white;
            padding: 14px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            text-align: center;
            transition: transform 0.3s;
        }
        
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 0.9rem;
        }

        .quick-action-group {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 0.8rem;
            background: var(--gray-50);
        }

        .quick-action-links {
            display: flex;
            flex-wrap: wrap;
            gap: 0.45rem;
        }
        .action-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 0.8rem;
            background: #fff;
            margin-bottom: 0.6rem;
        }
        .action-item:last-child {
            margin-bottom: 0;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .order-status {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 5px;
        }
        .pending { background: #ffc107; }
        .accepted { background: #17a2b8; }
        .in_progress { background: #007bff; }
        .completed { background: #28a745; }
        @media (max-width: 768px) {
            .shop-header .d-flex {
                flex-direction: column;
                align-items: flex-start !important;
                gap: 16px;
            }
            .shop-header .text-right {
                text-align: left;
            }
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
                gap: 12px;
            }
            .stat-card {
                padding: 14px;
            }
            .stat-number {
                font-size: 1.2rem;
                word-break: break-word;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <?php include __DIR__ . "/includes/owner_navbar.php"; ?>

    <div class="container">
        <!-- Shop Header -->
        <div class="shop-header">
            <div class="d-flex justify-between align-center">
                <div>
                    <h2><?php echo htmlspecialchars($shop['shop_name']); ?></h2>
                    <p class="mb-0"><?php echo htmlspecialchars($shop['shop_description']); ?></p>
                    <div class="mt-2">
                        <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($shop['address']); ?>
                    </div>
                </div>
                <div class="text-right">
                    <div class="shop-rating mb-3">
                        <i class="fas fa-star"></i> 
                        <strong><?php echo number_format((float) $shop['rating'], 1); ?></strong>
                        <small>(<?php echo (int) ($shop['rating_count'] ?? 0); ?> reviews)</small>
                    </div>
                    <div>
                        <a href="shop_profile.php" class="btn btn-light btn-sm">
                            <i class="fas fa-edit"></i> Edit Profile
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <h4 class="stat-number"><?php echo (int) ($exception_stats['open_exceptions'] ?? 0); ?></h4>
                <p class="text-muted mb-1">Open Exceptions</p>
                <small class="text-muted">Escalated: <?php echo (int) ($exception_stats['escalated_exceptions'] ?? 0); ?> · Blocking: <?php echo (int) ($exception_stats['blocking_exceptions'] ?? 0); ?></small>
                <div class="mt-2"><a href="exception_dashboard.php?status=open" class="btn btn-sm btn-outline-primary">Review</a></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon text-primary">
                    <i class="fas fa-shopping-cart"></i>
                </div>
                <div class="stat-number"><?php echo $stats['total_orders']; ?></div>
                <div class="stat-label">Total Orders</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon text-warning">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-number"><?php echo $stats['pending_orders']; ?></div>
                <div class="stat-label">Pending Orders</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon text-info">
                    <i class="fas fa-spinner"></i>
                </div>
                <div class="stat-number"><?php echo $stats['active_orders']; ?></div>
                <div class="stat-label">Active Orders</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon text-success">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="stat-number">₱<?php echo number_format($stats['total_earnings'], 2); ?></div>
                <div class="stat-label">Total Earnings</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon text-danger">
                    <i class="fas fa-triangle-exclamation"></i>
                </div>
                <div class="stat-number"><?php echo (int) ($stats['low_stock_materials'] ?? 0); ?></div>
                <div class="stat-label">Low-stock Materials</div>
            </div>

            <div class="stat-card">
                <div class="stat-icon text-secondary">
                    <i class="fas fa-boxes-stacked"></i>
                </div>
                <div class="stat-number"><?php echo (int) ($stats['reserved_material_orders'] ?? 0); ?></div>
                <div class="stat-label">Material Reservations</div>
            </div>
        </div>

        <div class="card mb-4">
            <h3>Operational Next Actions</h3>
            <div class="quick-action-links" style="margin-bottom:12px;">
                <a href="shop_orders.php?filter=pending" class="btn btn-primary btn-sm">Pending approvals (<?php echo (int) ($next_actions['pending_approvals'] ?? 0); ?>)</a>
                <a href="payment_verifications.php" class="btn btn-outline-warning btn-sm">Payments to verify (<?php echo (int) ($next_actions['payments_to_verify'] ?? 0); ?>)</a>
                <a href="production_tracking.php" class="btn btn-outline-danger btn-sm">Production bottlenecks (<?php echo (int) ($next_actions['production_bottlenecks'] ?? 0); ?>)</a>
                <a href="quality_control.php" class="btn btn-outline-info btn-sm">QC issues (<?php echo $qc_issues; ?>)</a>
                <a href="exception_dashboard.php?status=open" class="btn btn-outline-secondary btn-sm">Open exceptions (<?php echo (int) ($exception_stats['open_exceptions'] ?? 0); ?>)</a>
            </div>
            <?php if(!empty($bottleneck_rows)): ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>Order</th><th>Service</th><th>Status</th><th>Missed ETA</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach($bottleneck_rows as $row): ?>
                                <tr>
                                    <td>#<?php echo htmlspecialchars($row['order_number']); ?></td>
                                    <td><?php echo htmlspecialchars($row['service_type']); ?></td>
                                    <td><?php echo ucfirst(str_replace('_', ' ', (string) $row['status'])); ?></td>
                                    <td><?php echo date('M d, Y h:i A', strtotime($row['estimated_completion'])); ?></td>
                                    <td><a href="view_order.php?id=<?php echo (int) $row['id']; ?>" class="btn btn-sm btn-outline-primary">Open</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card mb-4">
            <h3>Prioritized Action Queue</h3>
            <p class="text-muted">Each item shows the issue, affected record, owner action, and direct navigation.</p>
            <?php if (empty($dashboard_action_items)): ?>
                <p class="text-muted mb-0">No critical blockers detected right now.</p>
            <?php else: ?>
                <?php foreach ($dashboard_action_items as $item): ?>
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

        <!-- Quick Actions -->
        <div class="card mb-4">
            <h3>Quick Actions</h3>
            <div class="quick-actions-grid">
                <div class="quick-action-group">
                    <h5 class="mb-2">Order & Team</h5>
                    <div class="quick-action-links">
                        <a href="shop_orders.php?filter=pending" class="btn btn-primary btn-sm">
                            <i class="fas fa-clipboard-check"></i> Review Orders (<?php echo $stats['pending_orders']; ?>)
                        </a>
                        <a href="manage_staff.php" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-users"></i> Staff (<?php echo $stats['total_staff']; ?>)
                        </a>
                        <a href="quality_control.php" class="btn btn-outline-warning btn-sm">
                            <i class="fas fa-magnifying-glass"></i> QC Queue (<?php echo (int) ($stats['qc_pending_orders'] ?? 0); ?>)
                        </a>
                        <a href="shop_orders.php?filter=production_rework" class="btn btn-outline-danger btn-sm">
                            <i class="fas fa-rotate-right"></i> Rework (<?php echo (int) ($stats['rework_orders'] ?? 0); ?>)
                        </a>
                        <a href="create_hr.php" class="btn btn-outline-info btn-sm">
                            <i class="fas fa-user-plus"></i> Create HR
                        </a>
                    </div>
                </div>
                <div class="quick-action-group">
                    <h5 class="mb-2">Business & Supply</h5>
                    <div class="quick-action-links">
                        <a href="shop_profile.php" class="btn btn-outline-success btn-sm">
                            <i class="fas fa-edit"></i> Shop Profile
                        </a>
                        <a href="earnings.php" class="btn btn-outline-warning btn-sm">
                            <i class="fas fa-chart-line"></i> Earnings
                        </a>
                        <a href="storage_warehouse_management.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-warehouse"></i> Warehouse
                        </a>
                        <a href="supplier_management.php" class="btn btn-outline-dark btn-sm">
                            <i class="fas fa-truck-loading"></i> Suppliers
                        </a>
                    </div>
                </div>
            </div>
        </div>

         <div class="content-stack">
            <div class="card">
                <h3>Recent Orders in Your Shop</h3>
                <?php if(!empty($recent_orders)): ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Client</th>
                                    <th>Service</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Hold</th>
                                    <th>Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($recent_orders as $order): ?>
                                <tr>
                                    <td>#<?php echo htmlspecialchars($order['order_number']); ?></td>
                                    <td><?php echo htmlspecialchars($order['client_name']); ?></td>
                                    <td><?php echo htmlspecialchars($order['service_type']); ?></td>
                                    <td>₱<?php echo number_format($order['price'], 2); ?></td>
                                    <td>
                                        <span class="order-status <?php echo htmlspecialchars($order['status']); ?>"></span>
                                        <?php echo ucfirst(str_replace('_', ' ', $order['status'])); ?>
                                    </td>
                                    <td>
                                        <?php $payment_hold = payment_hold_status($order['status'] ?? STATUS_PENDING, $order['payment_status'] ?? 'unpaid', $order['payment_release_status'] ?? null); ?>
                                        <span class="hold-pill <?php echo htmlspecialchars($payment_hold['class']); ?>">
                                            <?php echo htmlspecialchars($payment_hold['label']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d', strtotime($order['created_at'])); ?></td>
                                    <td>
                                        <?php if($order['status'] == 'pending'): ?>
                                            <div class="d-flex" style="gap: 5px;">
                                                 <a href="view_order.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-outline-primary">View</a>
                                                <a href="accept_order.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-success" onclick="return confirm('Accept this order and use the estimated price as the official price?');">Accept</a>
                                                <a href="reject_order.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-danger">Reject</a>
                                            </div>
                                        <?php else: ?>
                                           <a href="view_order.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-outline-primary">View</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center p-4">
                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                        <h4>No Orders Yet</h4>
                        <p class="text-muted">Orders will appear here once customers place them.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card">
                <h3>Machine Schedule</h3>
                <?php if(!empty($machine_schedule)): ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Machine</th>
                                    <th>Order #</th>
                                    <th>Estimated Stitches</th>
                                    <th>Scheduled Window</th>
                                    <th>Duration</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($machine_schedule as $schedule): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($schedule['machine_name']); ?></strong><br>
                                            <small><?php echo number_format((int) $schedule['max_stitches_per_hour']); ?> stitches/hr</small>
                                        </td>
                                        <td>#<?php echo htmlspecialchars($schedule['order_number']); ?></td>
                                        <td><?php echo number_format((int) $schedule['estimated_stitches']); ?></td>
                                        <td>
                                            <?php echo date('M d, Y h:i A', strtotime($schedule['scheduled_start'])); ?><br>
                                            <small>to <?php echo date('M d, Y h:i A', strtotime($schedule['scheduled_end'])); ?></small>
                                        </td>
                                        <td><?php echo max(1, (int) round(((int) $schedule['duration_minutes']) / 60)); ?> hour(s)</td>
                                        <td><?php echo ucfirst(str_replace('_', ' ', (string) $schedule['status'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0">No machine schedule entries yet. Jobs will appear here once production starts.</p>
                <?php endif; ?>
            </div>

            
            <div class="card">
                <h3>Production Queue Overview</h3>
                <?php if(!empty($production_queue)): ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Queue #</th>
                                    <th>Order #</th>
                                    <th>Client</th>
                                    <th>Status</th>
                                    <th>Priority</th>
                                    <th>Assigned Staff</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($production_queue as $queue_item): ?>
                                    <tr>
                                        <td><?php echo (int) ($queue_item['queue_position'] ?? 0); ?></td>
                                        <td>#<?php echo htmlspecialchars((string) ($queue_item['order_number'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars((string) ($queue_item['client_name'] ?? '')); ?></td>
                                        <td><?php echo ucfirst(str_replace('_', ' ', (string) ($queue_item['status'] ?? ''))); ?></td>
                                        <td><?php echo (int) ($queue_item['priority'] ?? 0); ?></td>
                                        <td>
                                            <?php if(!empty($queue_item['assigned_to'])): ?>
                                                #<?php echo (int) $queue_item['assigned_to']; ?>
                                            <?php else: ?>
                                                <span class="text-muted">Unassigned</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0">No eligible orders are currently in the production queue.</p>
                <?php endif; ?>
            </div>

            <div class="card">
                <h3>Shop Performance & Ratings</h3>
                <div class="stats-grid" style="margin-top: 0;">
                    <div class="stat-card">
                        <div class="stat-label">Completion Rate</div>
                        <div class="stat-number"><?php echo round($completion_rate, 1); ?>%</div>
                        <div class="progress" style="height: 8px; margin-top: 8px;">
                            <div class="progress-bar bg-success" style="width: <?php echo $completion_rate; ?>%"></div>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-label">Average Rating</div>
                        <div class="stat-number"><?php echo number_format((float) $shop['rating'], 1); ?>/5</div>
                        <div class="text-warning mt-2">
                            <?php for($i = 1; $i <= 5; $i++): ?>
                                <i class="fas fa-star<?php echo $i <= (float) $shop['rating'] ? '' : '-o'; ?>"></i>
                            <?php endfor; ?>
                        </div>
                         <small class="text-muted"><?php echo (int) ($shop['rating_count'] ?? 0); ?> reviews</small>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-label">Accepted Orders</div>
                        <div class="stat-number"><?php echo (int) $stats['accepted_orders']; ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Cancelled Orders</div>
                        <div class="stat-number"><?php echo (int) $stats['cancelled_orders']; ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2024 <?php echo htmlspecialchars($shop['shop_name']); ?> - Owner Dashboard</p>
            <small class="text-muted">Shop ID: <?php echo $shop['id']; ?> | Status: <?php echo ucfirst($shop['status']); ?></small>
        </div>
    </footer>
</body>
</html>