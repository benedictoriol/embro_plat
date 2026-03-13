<?php
session_start();
require_once '../config/db.php';
require_once '../includes/analytics_service.php';
require_once 'partials.php';
require_role('sys_admin');

$range = $_GET['range'] ?? '30d';
$allowedRanges = ['7d' => 7, '30d' => 30, '90d' => 90, 'all' => 0];
if (!array_key_exists($range, $allowedRanges)) {
    $range = '30d';
}

$startDate = null;
$endDate = null;
if ($allowedRanges[$range] > 0) {
    $endDate = date('Y-m-d');
    $startDate = date('Y-m-d', strtotime('-' . ($allowedRanges[$range] - 1) . ' days'));
}

$metrics = fetch_sys_admin_reporting_metrics($pdo, $startDate, $endDate);
$usersByRole = $metrics['users_by_role'];
$ordersByStatus = $metrics['orders_by_status'];
$payments = $metrics['payments'];
$disputes = $metrics['disputes'];
$supportTickets = $metrics['support_tickets'];
$inventoryAlerts = $metrics['inventory_alerts'];
$topShops = $metrics['top_shops'];

$activeStatuses = ['accepted', 'digitizing', 'production_pending', 'production', 'production_rework', 'qc_pending', 'ready_for_delivery', 'in_progress'];
$activeOrderCount = 0;
foreach ($activeStatuses as $status) {
    $activeOrderCount += (int) ($ordersByStatus[$status] ?? 0);
}

$kpis = [
    [
        'label' => 'Pending approvals',
        'value' => number_format($metrics['pending_owner_approvals'] + $metrics['pending_member_approvals'] + $metrics['pending_shop_approvals']),
        'note' => number_format($metrics['pending_owner_approvals']) . ' owners, ' . number_format($metrics['pending_member_approvals']) . ' members, ' . number_format($metrics['pending_shop_approvals']) . ' shops.',
        'icon' => 'fas fa-user-check',
        'tone' => 'warning',
    ],
    [
        'label' => 'Completed orders',
        'value' => number_format($metrics['total_completed_orders']),
        'note' => number_format($activeOrderCount) . ' active orders currently in workflow.',
        'icon' => 'fas fa-clipboard-check',
        'tone' => 'success',
    ],
    [
        'label' => 'Verified payments',
        'value' => '₱' . number_format((float) $payments['verified_payment_amount'], 2),
        'note' => number_format((int) $payments['verified_payment_count']) . ' verified payment records.',
        'icon' => 'fas fa-money-check-dollar',
        'tone' => 'primary',
    ],
    [
        'label' => 'Open cases',
        'value' => number_format((int) $disputes['pending'] + (int) $disputes['reviewing'] + (int) $supportTickets['pending'] + (int) $supportTickets['in_progress']),
        'note' => number_format($disputes['pending'] + $disputes['reviewing']) . ' disputes and ' . number_format($supportTickets['pending'] + $supportTickets['in_progress']) . ' support tickets needing action.',
        'icon' => 'fas fa-triangle-exclamation',
        'tone' => 'danger',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics &amp; Reporting - System Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .reporting-grid { display: grid; grid-template-columns: repeat(12, 1fr); gap: 1.5rem; margin: 2rem 0; }
        .kpi-card { grid-column: span 3; }
        .full-card { grid-column: span 12; }
        .half-card { grid-column: span 6; }
        .third-card { grid-column: span 4; }
        .metric-row { display: flex; justify-content: space-between; gap: 1rem; border-bottom: 1px solid var(--gray-200); padding: 0.7rem 0; }
        .metric-row:last-child { border-bottom: none; }
        .analytics-table { width: 100%; border-collapse: collapse; }
        .analytics-table th, .analytics-table td { border-bottom: 1px solid var(--gray-200); padding: 0.75rem; text-align: left; }
        .filters { display: flex; gap: .5rem; flex-wrap: wrap; }
        .filters .btn.active { background: var(--primary-500); color: #fff; border-color: var(--primary-500); }
    </style>
</head>
<body>
    <?php sys_admin_nav('analytics_reporting'); ?>

    <main class="container">
        <section class="page-header">
            <div>
                <h1>Analytics &amp; Reporting</h1>
                <p class="text-muted mb-1">Live system reporting across users, approvals, orders, payments, disputes, support, and inventory.</p>
                <small class="text-muted">
                    <?php echo $startDate && $endDate ? 'Date range: ' . htmlspecialchars($startDate) . ' to ' . htmlspecialchars($endDate) : 'Date range: all records'; ?>
                </small>
            </div>
            <div class="filters">
                <?php foreach (['7d' => '7D', '30d' => '30D', '90d' => '90D', 'all' => 'All Time'] as $key => $label): ?>
                    <a class="btn btn-outline-primary <?php echo $range === $key ? 'active' : ''; ?>" href="?range=<?php echo $key; ?>"><?php echo $label; ?></a>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="reporting-grid">
            <?php foreach ($kpis as $kpi): ?>
                <div class="card kpi-card">
                    <div class="metric">
                        <div>
                            <p class="text-muted mb-1"><?php echo htmlspecialchars($kpi['label']); ?></p>
                            <h3 class="mb-1"><?php echo htmlspecialchars($kpi['value']); ?></h3>
                            <small class="text-muted"><?php echo htmlspecialchars($kpi['note']); ?></small>
                        </div>
                        <div class="icon-circle bg-<?php echo htmlspecialchars($kpi['tone']); ?> text-white">
                            <i class="<?php echo htmlspecialchars($kpi['icon']); ?>"></i>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="card third-card">
                <h2>User totals by role</h2>
                <?php foreach ($usersByRole as $role => $count): ?>
                    <div class="metric-row"><span><?php echo ucwords(str_replace('_', ' ', $role)); ?></span><strong><?php echo number_format((int) $count); ?></strong></div>
                <?php endforeach; ?>
            </div>

            <div class="card third-card">
                <h2>Orders by status</h2>
                <?php foreach (['pending','accepted','digitizing','production_pending','production','qc_pending','production_rework','ready_for_delivery','in_progress','delivered','completed','cancelled'] as $status): ?>
                    <div class="metric-row"><span><?php echo ucwords(str_replace('_', ' ', $status)); ?></span><strong><?php echo number_format((int) ($ordersByStatus[$status] ?? 0)); ?></strong></div>
                <?php endforeach; ?>
            </div>

            <div class="card third-card">
                <h2>Payments</h2>
                <div class="metric-row"><span>Total Amount</span><strong>₱<?php echo number_format((float) $payments['total_payment_amount'], 2); ?></strong></div>
                <div class="metric-row"><span>Paid Amount</span><strong>₱<?php echo number_format((float) $payments['paid_payment_amount'], 2); ?></strong></div>
                <div class="metric-row"><span>Pending Verification</span><strong>₱<?php echo number_format((float) $payments['pending_verification_amount'], 2); ?></strong></div>
                <div class="metric-row"><span>Verified Amount</span><strong>₱<?php echo number_format((float) $payments['verified_payment_amount'], 2); ?></strong></div>
                <div class="metric-row"><span>Verified Records</span><strong><?php echo number_format((int) $payments['verified_payment_count']); ?></strong></div>
            </div>

            <div class="card half-card">
                <h2>Dispute summary</h2>
                <div class="metric-row"><span>Total reports</span><strong><?php echo number_format((int) $disputes['total']); ?></strong></div>
                <div class="metric-row"><span>Pending</span><strong><?php echo number_format((int) $disputes['pending']); ?></strong></div>
                <div class="metric-row"><span>Reviewing</span><strong><?php echo number_format((int) $disputes['reviewing']); ?></strong></div>
                <div class="metric-row"><span>Resolved</span><strong><?php echo number_format((int) $disputes['resolved']); ?></strong></div>
                <div class="metric-row"><span>Dismissed</span><strong><?php echo number_format((int) $disputes['dismissed']); ?></strong></div>
            </div>

            <div class="card half-card">
                <h2>Support ticket summary</h2>
                <div class="metric-row"><span>Total requests</span><strong><?php echo number_format((int) $supportTickets['total']); ?></strong></div>
                <div class="metric-row"><span>Pending</span><strong><?php echo number_format((int) $supportTickets['pending']); ?></strong></div>
                <div class="metric-row"><span>In Progress</span><strong><?php echo number_format((int) $supportTickets['in_progress']); ?></strong></div>
                <div class="metric-row"><span>Completed</span><strong><?php echo number_format((int) $supportTickets['completed']); ?></strong></div>
                <div class="metric-row"><span>Cancelled</span><strong><?php echo number_format((int) $supportTickets['cancelled']); ?></strong></div>
            </div>

            <div class="card half-card">
                <h2>Inventory alerts</h2>
                <div class="metric-row"><span>Low stock materials</span><strong><?php echo number_format((int) $inventoryAlerts['low_stock_materials']); ?></strong></div>
                <div class="metric-row"><span>Warehouse reorder alerts</span><strong><?php echo number_format((int) $inventoryAlerts['reorder_alerts']); ?></strong></div>
            </div>

            <div class="card half-card">
                <h2>Approval queues</h2>
                <div class="metric-row"><span>Pending owner approvals</span><strong><?php echo number_format((int) $metrics['pending_owner_approvals']); ?></strong></div>
                <div class="metric-row"><span>Pending member approvals</span><strong><?php echo number_format((int) $metrics['pending_member_approvals']); ?></strong></div>
                <div class="metric-row"><span>Pending shop approvals</span><strong><?php echo number_format((int) $metrics['pending_shop_approvals']); ?></strong></div>
                <div class="metric-row"><span>Total completed orders</span><strong><?php echo number_format((int) $metrics['total_completed_orders']); ?></strong></div>
            </div>

            <div class="card full-card">
                <h2>Top shop performance</h2>
                <div class="table-responsive">
                    <table class="analytics-table">
                        <thead>
                            <tr>
                                <th>Shop</th>
                                <th>Total Orders</th>
                                <th>Completed Orders</th>
                                <th>Completion Rate</th>
                                <th>Verified Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($topShops)): ?>
                                <?php foreach ($topShops as $shop): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string) ($shop['shop_name'] ?? 'Unknown')); ?></td>
                                        <td><?php echo number_format((int) ($shop['total_orders'] ?? 0)); ?></td>
                                        <td><?php echo number_format((int) ($shop['completed_orders'] ?? 0)); ?></td>
                                        <td><?php echo number_format(((float) ($shop['completion_rate'] ?? 0)) * 100, 1); ?>%</td>
                                        <td>₱<?php echo number_format((float) ($shop['verified_revenue'] ?? 0), 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5" class="text-muted">No shop performance records found for the selected range.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>

    <?php sys_admin_footer(); ?>
</body>
</html>
