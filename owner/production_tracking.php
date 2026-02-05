<?php
session_start();
require_once '../config/db.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$shop_stmt = $pdo->prepare("SELECT shop_name FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch();

$tracking_stages = [
    [
        'title' => 'Intake confirmed',
        'detail' => 'Order details, stitch count, and due dates are verified before production begins.',
        'icon' => 'fas fa-clipboard-check',
    ],
    [
        'title' => 'Digitizing & proof ready',
        'detail' => 'Artwork files are digitized and queued with machine-ready settings.',
        'icon' => 'fas fa-pen-nib',
    ],
    [
        'title' => 'Stitching in progress',
        'detail' => 'Machine runtime and operator check-ins track progress against the schedule.',
        'icon' => 'fas fa-needle',
    ],
    [
        'title' => 'Quality check',
        'detail' => 'Finished pieces are inspected for thread consistency and alignment.',
        'icon' => 'fas fa-magnifying-glass',
    ],
    [
        'title' => 'Ready for pickup',
        'detail' => 'Completed batches are packaged, labeled, and prepped for pickup/delivery.',
        'icon' => 'fas fa-box',
    ],
];

$insights = [
    [
        'label' => 'Orders on track',
        'value' => '18',
        'note' => 'Running within the expected schedule window.',
        'icon' => 'fas fa-clock',
        'tone' => 'success',
    ],
    [
        'label' => 'At-risk jobs',
        'value' => '4',
        'note' => 'Require follow-up before the due date.',
        'icon' => 'fas fa-triangle-exclamation',
        'tone' => 'warning',
    ],
    [
        'label' => 'Machine utilization',
        'value' => '82%',
        'note' => 'Average embroidery machine usage today.',
        'icon' => 'fas fa-gears',
        'tone' => 'info',
    ],
];

$automation = [
    [
        'title' => 'Overdue alerts',
        'detail' => 'Automated alerts flag jobs that are about to miss their promised ship or pickup dates.',
        'icon' => 'fas fa-bell',
    ],
    [
        'title' => 'Activity logs',
        'detail' => 'Every stage update is captured with timestamps, operator notes, and machine usage.',
        'icon' => 'fas fa-clipboard-list',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Production Tracking Module - Owner</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .tracking-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .overview-card {
            grid-column: span 12;
        }

        .stages-card {
            grid-column: span 8;
        }

        .insights-card {
            grid-column: span 4;
        }

        .automation-card {
            grid-column: span 12;
        }

        .stage-item {
            display: flex;
            gap: 1rem;
            align-items: flex-start;
            padding: 1rem;
            border-radius: var(--radius);
            border: 1px solid var(--gray-200);
            background: var(--bg-primary);
        }

        .stage-icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: var(--radius-full);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--primary-100);
            color: var(--primary-700);
            font-size: 1rem;
        }

        .stage-list {
            display: grid;
            gap: 1rem;
        }

        .insight-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
        }

        .insight-item i {
            color: var(--primary-600);
        }

        .insight-value {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0.25rem 0;
        }

        .automation-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1rem;
        }

        .automation-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
        }

        .automation-item i {
            color: var(--primary-600);
        }
    </style>
</head>
<body>
    <nav class="navbar navbar--compact">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-store"></i> <?php echo htmlspecialchars($shop['shop_name'] ?? 'Shop Owner'); ?>
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <li><a href="shop_profile.php" class="nav-link">Shop Profile</a></li>
                <li><a href="manage_staff.php" class="nav-link">Staff</a></li>
                <li><a href="shop_orders.php" class="nav-link">Orders</a></li>
                <li><a href="messages.php" class="nav-link">Messages</a></li>
                <li><a href="payment_verifications.php" class="nav-link">Payments</a></li>
                <li><a href="earnings.php" class="nav-link">Earnings</a></li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle">
                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['user']['fullname']); ?>
                    </a>
                    <div class="dropdown-menu">
                        <a href="profile.php" class="dropdown-item"><i class="fas fa-user-cog"></i> Profile</a>
                        <a href="../auth/logout.php" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </li>
            </ul>
        </div>
    </nav>

    <div class="container">
        <div class="dashboard-header fade-in">
            <div class="d-flex justify-between align-center">
                <div>
                    <h2>Production Tracking</h2>
                    <p class="text-muted">Monitor embroidery progress and keep every job on schedule.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-chart-line"></i> Module 14</span>
            </div>
        </div>

        <div class="tracking-grid">
            <div class="card overview-card">
                <div class="card-header">
                    <h3><i class="fas fa-bullseye text-primary"></i> Purpose</h3>
                </div>
                <p class="text-muted mb-0">
                    Tracks every embroidery job from intake through pickup, giving owners real-time visibility into
                    production status, bottlenecks, and completion readiness.
                </p>
            </div>

            <div class="card stages-card">
                <div class="card-header">
                    <h3><i class="fas fa-route text-primary"></i> Progress Stages</h3>
                    <p class="text-muted">Standard checkpoints used to monitor embroidery progress.</p>
                </div>
                <div class="stage-list">
                    <?php foreach ($tracking_stages as $stage): ?>
                        <div class="stage-item">
                            <span class="stage-icon"><i class="<?php echo $stage['icon']; ?>"></i></span>
                            <div>
                                <strong><?php echo $stage['title']; ?></strong>
                                <p class="text-muted mb-0"><?php echo $stage['detail']; ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card insights-card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-pie text-primary"></i> Live Insights</h3>
                    <p class="text-muted">Snapshot of shop-floor activity.</p>
                </div>
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($insights as $insight): ?>
                        <div class="insight-item">
                            <div class="d-flex align-center gap-2">
                                <i class="<?php echo $insight['icon']; ?>"></i>
                                <strong><?php echo $insight['label']; ?></strong>
                            </div>
                            <div class="insight-value text-<?php echo $insight['tone']; ?>">
                                <?php echo $insight['value']; ?>
                            </div>
                            <p class="text-muted mb-0"><?php echo $insight['note']; ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card automation-card">
                <div class="card-header">
                    <h3><i class="fas fa-robot text-primary"></i> Automation</h3>
                    <p class="text-muted">Proactive notifications that keep production moving.</p>
                </div>
                <div class="automation-list">
                    <?php foreach ($automation as $rule): ?>
                        <div class="automation-item">
                            <h4 class="d-flex align-center gap-2">
                                <i class="<?php echo $rule['icon']; ?>"></i>
                                <?php echo $rule['title']; ?>
                            </h4>
                            <p class="text-muted mb-0"><?php echo $rule['detail']; ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
