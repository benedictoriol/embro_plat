<?php
session_start();
require_once '../config/db.php';
require_once '../includes/analytics_service.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$shop_stmt = $pdo->prepare("SELECT id, shop_name FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shops = $shop_stmt->fetchAll();
$shop = $shops[0] ?? null;
$shop_ids = array_column($shops, 'id');

$overview = fetch_order_analytics($pdo, $shop_ids);
$staff_count = fetch_staff_count($pdo, $shop_ids);
$total_orders = $overview['total_orders'];
$completion_rate = $total_orders > 0 ? ($overview['completed_orders'] / $total_orders) * 100 : 0;

$kpis = [
    [
        'label' => 'Total orders',
        'value' => number_format($overview['total_orders']),
        'note' => 'All orders currently on record.',
        'icon' => 'fas fa-receipt',
        'tone' => 'primary',
    ],
    [
        'label' => 'Completed orders',
        'value' => number_format($overview['completed_orders']),
        'note' => 'Successfully finished jobs.',
        'icon' => 'fas fa-clipboard-check',
        'tone' => 'success',
    ],
    [
        'label' => 'Cancelled orders',
        'value' => number_format($overview['cancelled_orders']),
        'note' => 'Orders cancelled by either party.',
        'icon' => 'fas fa-ban',
        'tone' => 'danger',
    ],
    [
        'label' => 'Paid orders',
        'value' => number_format($overview['paid_orders']),
        'note' => 'Orders with verified payments.',
        'icon' => 'fas fa-wallet',
        'tone' => 'info',
    ],
    [
        'label' => 'Total earnings',
        'value' => '₱' . number_format($overview['total_revenue'], 2),
        'note' => 'Verified payment total from payments.',
        'icon' => 'fas fa-peso-sign',
        'tone' => 'warning',
    ],
    [
        'label' => 'Average rating',
        'value' => number_format($overview['average_rating'], 1) . '/5',
        'note' => number_format($overview['rating_count']) . ' approved review(s).',
        'icon' => 'fas fa-star',
        'tone' => 'success',
    ],
    [
        'label' => 'Completion rate',
        'value' => number_format($completion_rate, 1) . '%',
        'note' => 'Share of orders marked completed.',
        'icon' => 'fas fa-clock',
        'tone' => 'primary',
    ],
    [
        'label' => 'Active staff',
        'value' => number_format($staff_count),
        'note' => 'Currently assigned to your shop.',
        'icon' => 'fas fa-users',
        'tone' => 'warning',
    ],
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
        .reporting-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .kpi-card {
            grid-column: span 3;
        }

        @media (max-width: 992px) {
            .kpi-card {
                grid-column: span 6;
            }
        }

        @media (max-width: 576px) {
            .kpi-card {
                grid-column: span 12;
            }
        }

        .empty-state-card {
            grid-column: span 12;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . "/includes/owner_navbar.php"; ?>

    <main class="container">
        <section class="page-header">
            <div>
                <h1>Analytics &amp; Reporting</h1>
                <p class="text-muted">Follow shop performance with dashboards, scheduled insights, and alerts.</p>
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
                        <div class="icon-circle bg-<?php echo $kpi['tone']; ?> text-white">
                            <i class="<?php echo $kpi['icon']; ?>"></i>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="card empty-state-card">
                <h2>Reporting panels</h2>
                <p class="text-muted mb-0">
                    PANELLLLLSSSS
            </div>
        </section>
    </main>
</body>
</html>
