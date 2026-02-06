<?php
session_start();
require_once '../config/db.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$shop_stmt = $pdo->prepare("SELECT shop_name FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch();

$inventory_kpis = [
    [
        'label' => 'Materials in stock',
        'value' => 128,
        'note' => 'Active SKUs tracked this week.',
        'icon' => 'fas fa-boxes-stacked',
        'tone' => 'primary',
    ],
    [
        'label' => 'Low-stock items',
        'value' => 9,
        'note' => 'Below reorder point.',
        'icon' => 'fas fa-triangle-exclamation',
        'tone' => 'warning',
    ],
    [
        'label' => 'Reorder value',
        'value' => '₱18,450',
        'note' => 'Projected replenishment cost.',
        'icon' => 'fas fa-receipt',
        'tone' => 'info',
    ],
    [
        'label' => 'Days of coverage',
        'value' => '14',
        'note' => 'Average run rate coverage.',
        'icon' => 'fas fa-calendar-day',
        'tone' => 'success',
    ],
];

$materials = [
    [
        'name' => 'Polyester thread - Navy',
        'category' => 'Thread',
        'on_hand' => '28 cones',
        'reorder_point' => '20 cones',
        'next_delivery' => 'Sept 18',
        'status' => 'Healthy',
    ],
    [
        'name' => 'Rayon thread - Crimson',
        'category' => 'Thread',
        'on_hand' => '12 cones',
        'reorder_point' => '15 cones',
        'next_delivery' => 'Sept 14',
        'status' => 'Low',
    ],
    [
        'name' => 'Cut-away stabilizer 70gsm',
        'category' => 'Stabilizer',
        'on_hand' => '19 rolls',
        'reorder_point' => '10 rolls',
        'next_delivery' => 'Sept 22',
        'status' => 'Healthy',
    ],
    [
        'name' => '3D foam sheets (3mm)',
        'category' => 'Foam',
        'on_hand' => '6 packs',
        'reorder_point' => '8 packs',
        'next_delivery' => 'Sept 12',
        'status' => 'Low',
    ],
    [
        'name' => 'Backing fabric - Black',
        'category' => 'Backing',
        'on_hand' => '42 yards',
        'reorder_point' => '30 yards',
        'next_delivery' => 'Sept 20',
        'status' => 'Healthy',
    ],
];

$automation_rules = [
    [
        'title' => 'Production-based deduction',
        'detail' => 'Deduct thread, stabilizer, and backing based on stitch count and hoop size once a job moves to production.',
        'icon' => 'fas fa-robot',
    ],
    [
        'title' => 'Batch usage reconciliation',
        'detail' => 'Auto-reconcile actual consumption after QA to keep yields and waste percentages accurate.',
        'icon' => 'fas fa-clipboard-check',
    ],
    [
        'title' => 'Supplier lead-time mapping',
        'detail' => 'Attach supplier SLAs so reorder dates adjust automatically based on shipping windows.',
        'icon' => 'fas fa-truck-fast',
    ],
];

$alert_channels = [
    [
        'channel' => 'Low-stock alerts',
        'detail' => 'Notify owner and floor lead when inventory dips below the reorder point.',
        'icon' => 'fas fa-bell',
    ],
    [
        'channel' => 'Critical shortage',
        'detail' => 'Escalate with SMS when coverage drops under 3 production days.',
        'icon' => 'fas fa-circle-exclamation',
    ],
    [
        'channel' => 'Incoming delivery',
        'detail' => 'Reminder 24 hours before supplier drop-offs for stocking preparation.',
        'icon' => 'fas fa-box-open',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Raw Material Inventory Management Module - Owner</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .inventory-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .inventory-kpi {
            grid-column: span 3;
        }

        .purpose-card {
            grid-column: span 12;
        }

        .stock-card {
            grid-column: span 8;
        }

        .automation-card {
            grid-column: span 4;
        }

        .alerts-card {
            grid-column: span 12;
        }

        .kpi-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .kpi-item i {
            font-size: 1.5rem;
        }

        .automation-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
        }

        .automation-item + .automation-item {
            margin-top: 1rem;
        }

        .alert-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
        }

        .alert-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
            display: flex;
            gap: 0.75rem;
            align-items: flex-start;
        }

        .alert-item i {
            color: var(--primary-600);
            margin-top: 0.25rem;
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
                    <h2>Raw Material Inventory Management</h2>
                    <p class="text-muted">Track embroidery materials with live stock visibility and automated replenishment support.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-warehouse"></i> Module 22</span>
            </div>
        </div>

        <div class="inventory-grid">
            <div class="card purpose-card">
                <div class="card-header">
                    <h3><i class="fas fa-bullseye text-primary"></i> Purpose</h3>
                </div>
                <p class="text-muted mb-0">
                    Tracks embroidery materials such as thread, stabilizers, and backing fabric while keeping stock levels aligned
                    with live production demand and supplier lead times.
                </p>
            </div>

            <?php foreach ($inventory_kpis as $kpi): ?>
                <div class="card inventory-kpi">
                    <div class="kpi-item">
                        <div>
                            <p class="text-muted mb-1"><?php echo $kpi['label']; ?></p>
                            <h3 class="mb-1"><?php echo $kpi['value']; ?></h3>
                            <small class="text-muted"><?php echo $kpi['note']; ?></small>
                        </div>
                        <span class="badge badge-<?php echo $kpi['tone']; ?>">
                            <i class="<?php echo $kpi['icon']; ?>"></i>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="card stock-card">
                <div class="card-header">
                    <h3><i class="fas fa-layer-group text-primary"></i> Material Stock Levels</h3>
                    <p class="text-muted">Current on-hand quantities with reorder thresholds.</p>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Material</th>
                            <th>Category</th>
                            <th>On-hand</th>
                            <th>Reorder point</th>
                            <th>Next delivery</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($materials as $material): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($material['name']); ?></td>
                                <td><?php echo htmlspecialchars($material['category']); ?></td>
                                <td><?php echo htmlspecialchars($material['on_hand']); ?></td>
                                <td><?php echo htmlspecialchars($material['reorder_point']); ?></td>
                                <td class="text-muted"><?php echo htmlspecialchars($material['next_delivery']); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $material['status'] === 'Low' ? 'warning' : 'success'; ?>">
                                        <?php echo $material['status']; ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card automation-card">
                <div class="card-header">
                    <h3><i class="fas fa-gear text-primary"></i> Automation</h3>
                    <p class="text-muted">Smart controls that keep inventory synced with production.</p>
                </div>
                <?php foreach ($automation_rules as $rule): ?>
                    <div class="automation-item">
                        <div class="d-flex align-center gap-2 mb-2">
                            <i class="<?php echo $rule['icon']; ?> text-primary"></i>
                            <strong><?php echo $rule['title']; ?></strong>
                        </div>
                        <p class="text-muted mb-0"><?php echo $rule['detail']; ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card alerts-card">
                <div class="card-header">
                    <h3><i class="fas fa-bell text-primary"></i> Alerts &amp; Notifications</h3>
                    <p class="text-muted">Low-stock alerts help keep production uninterrupted.</p>
                </div>
                <div class="alert-list">
                    <?php foreach ($alert_channels as $alert): ?>
                        <div class="alert-item">
                            <i class="<?php echo $alert['icon']; ?>"></i>
                            <div>
                                <strong><?php echo $alert['channel']; ?></strong>
                                <p class="text-muted mb-0"><?php echo $alert['detail']; ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
