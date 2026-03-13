<?php
session_start();
require_once '../config/db.php';
require_once '../includes/analytics_service.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$shop_stmt = $pdo->prepare("SELECT id, shop_name FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shops = $shop_stmt->fetchAll(PDO::FETCH_ASSOC);
$shop_ids = array_map('intval', array_column($shops, 'id'));

$overview = fetch_order_analytics($pdo, $shop_ids);
$status_map = fetch_order_status_breakdown($pdo, $shop_ids);
$monthly_earnings = fetch_monthly_earnings($pdo, $shop_ids, 6);
$quote_conversion = fetch_quote_conversion_summary($pdo, $shop_ids);
$qc_summary = fetch_qc_summary($pdo, $shop_ids);
$staff_productivity = fetch_employee_productivity($pdo, $shop_ids, 5);
$staff_count = fetch_staff_count($pdo, $shop_ids);
$completion_rate = ((float) ($overview['completion_rate'] ?? 0)) * 100;

$kpis = [
    ['label' => 'Total orders', 'value' => number_format($overview['total_orders']), 'note' => 'All orders currently on record.', 'icon' => 'fas fa-receipt', 'tone' => 'primary'],
    ['label' => 'Completed orders', 'value' => number_format($overview['completed_orders']), 'note' => 'Successfully finished jobs.', 'icon' => 'fas fa-clipboard-check', 'tone' => 'success'],
    ['label' => 'Cancelled orders', 'value' => number_format($overview['cancelled_orders']), 'note' => 'Orders cancelled by either party.', 'icon' => 'fas fa-ban', 'tone' => 'danger'],
    ['label' => 'Total earnings', 'value' => '₱' . number_format($overview['total_revenue'], 2), 'note' => 'Sum of verified payments.', 'icon' => 'fas fa-peso-sign', 'tone' => 'warning'],
    ['label' => 'Completion rate', 'value' => number_format($completion_rate, 1) . '%', 'note' => 'Share of orders marked completed.', 'icon' => 'fas fa-clock', 'tone' => 'primary'],
    ['label' => 'Avg completion time', 'value' => number_format((float) ($overview['average_turnaround_days'] ?? 0), 1) . ' day(s)', 'note' => 'Based on completed orders with timestamps.', 'icon' => 'fas fa-hourglass-half', 'tone' => 'info'],
    ['label' => 'Quote conversion', 'value' => number_format(($quote_conversion['conversion_rate'] ?? 0) * 100, 1) . '%', 'note' => number_format($quote_conversion['converted_orders'] ?? 0) . ' converted of ' . number_format($quote_conversion['quoted_orders'] ?? 0) . ' quoted.', 'icon' => 'fas fa-file-signature', 'tone' => 'success'],
    ['label' => 'Active staff', 'value' => number_format($staff_count), 'note' => 'Currently assigned to your shop.', 'icon' => 'fas fa-users', 'tone' => 'warning'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics &amp; Reporting - Owner</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .reporting-grid { display: grid; grid-template-columns: repeat(12, 1fr); gap: 1.5rem; margin: 2rem 0; }
        .kpi-card { grid-column: span 3; }
        .half-card { grid-column: span 6; }
        .full-card { grid-column: span 12; }
        .metric-row { display: flex; justify-content: space-between; align-items: center; padding: .75rem 0; border-bottom: 1px solid var(--gray-100); }
        .metric-row:last-child { border-bottom: none; }
        .analytics-table { width: 100%; border-collapse: collapse; }
        .analytics-table th, .analytics-table td { padding: .75rem; border-bottom: 1px solid var(--gray-100); text-align: left; }
        @media (max-width: 992px) { .kpi-card, .half-card { grid-column: span 6; } }
        @media (max-width: 576px) { .kpi-card, .half-card, .full-card { grid-column: span 12; } }
    </style>
</head>
<body>
    <?php include __DIR__ . "/includes/owner_navbar.php"; ?>

    <main class="container">
        <section class="page-header">
            <div>
                <h1>Analytics &amp; Reporting</h1>
                <p class="text-muted">Live shop metrics computed from orders, payments, and QC activity.</p>
            </div>
            <span class="badge badge-primary"><i class="fas fa-chart-pie"></i> Module 30</span>
        </section>

        <section class="reporting-grid">
            <?php foreach ($kpis as $kpi): ?>
                <div class="card kpi-card">
                    <div class="metric">
                        <div>
                            <p class="text-muted mb-1"><?php echo $kpi['label']; ?></p>
                            <h3 class="mb-1"><?php echo $kpi['value']; ?></h3>
                            <small class="text-muted"><?php echo $kpi['note']; ?></small>
                        </div>
                        <div class="icon-circle bg-<?php echo $kpi['tone']; ?> text-white"><i class="<?php echo $kpi['icon']; ?>"></i></div>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="card half-card">
                <h3><i class="fas fa-layer-group text-info"></i> Orders by Status</h3>
                <?php foreach (['pending', 'accepted', 'digitizing', 'production', 'qc_pending', 'ready_for_delivery', 'in_progress', 'completed', 'cancelled'] as $status): ?>
                    <div class="metric-row">
                        <span><?php echo ucwords(str_replace('_', ' ', $status)); ?></span>
                        <strong><?php echo number_format((int) ($status_map[$status] ?? 0)); ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card half-card">
                <h3><i class="fas fa-check-double text-success"></i> QC Outcomes</h3>
                <div class="metric-row"><span>QC Passed</span><strong><?php echo number_format($qc_summary['passed'] ?? 0); ?></strong></div>
                <div class="metric-row"><span>QC Failed</span><strong><?php echo number_format($qc_summary['failed'] ?? 0); ?></strong></div>
                <div class="metric-row"><span>QC Pending</span><strong><?php echo number_format($qc_summary['pending'] ?? 0); ?></strong></div>
                <small class="text-muted">QC metrics depend on availability of <code>order_quality_checks</code> records.</small>
            </div>

            <div class="card full-card">
                <h3><i class="fas fa-calendar-alt text-warning"></i> Monthly Earnings (Verified Payments)</h3>
                <table class="analytics-table">
                    <thead><tr><th>Month</th><th>Earnings</th></tr></thead>
                    <tbody>
                        <?php foreach ($monthly_earnings as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['label']); ?></td>
                                <td>₱<?php echo number_format((float) $row['earnings'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card full-card">
                <h3><i class="fas fa-user-clock text-primary"></i> Employee Workload & Productivity</h3>
                <?php if (empty($staff_productivity)): ?>
                    <p class="text-muted mb-0">No staff productivity data available yet.</p>
                <?php else: ?>
                    <table class="analytics-table">
                        <thead>
                            <tr><th>Staff</th><th>Assigned</th><th>Active</th><th>Completed</th><th>Cancelled</th><th>Avg Completion (hrs)</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($staff_productivity as $staff): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string) ($staff['fullname'] ?? 'Unknown')); ?></td>
                                    <td><?php echo number_format((int) ($staff['total_assigned'] ?? 0)); ?></td>
                                    <td><?php echo number_format((int) ($staff['active_orders'] ?? 0)); ?></td>
                                    <td><?php echo number_format((int) ($staff['completed_orders'] ?? 0)); ?></td>
                                    <td><?php echo number_format((int) ($staff['cancelled_orders'] ?? 0)); ?></td>
                                    <td><?php echo isset($staff['avg_completion_hours']) && $staff['avg_completion_hours'] !== null ? number_format((float) $staff['avg_completion_hours'], 1) : '—'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </section>
    </main>
</body>
</html>
