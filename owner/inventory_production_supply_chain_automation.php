<?php
session_start();
require_once '../config/db.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$shop_stmt = $pdo->prepare("SELECT shop_name FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch();

$sync_kpis = [
    [
        'label' => 'Active production runs',
        'value' => 7,
        'note' => 'Jobs consuming materials today.',
        'icon' => 'fas fa-industry',
        'tone' => 'primary',
    ],
    [
        'label' => 'Materials auto-deducted',
        'value' => '96%',
        'note' => 'Matched to bill of materials.',
        'icon' => 'fas fa-scissors',
        'tone' => 'success',
    ],
    [
        'label' => 'Purchase triggers',
        'value' => 4,
        'note' => 'Pending supplier approvals.',
        'icon' => 'fas fa-cart-plus',
        'tone' => 'warning',
    ],
    [
        'label' => 'Finished goods logged',
        'value' => 22,
        'note' => 'Units completed this week.',
        'icon' => 'fas fa-box-open',
        'tone' => 'info',
    ],
];

$sync_events = [
    [
        'stage' => 'Order release',
        'inventory' => 'Reserve thread + backing',
        'production' => 'Allocate machine time',
        'purchasing' => 'Check supplier ETAs',
        'status' => 'On track',
    ],
    [
        'stage' => 'Production start',
        'inventory' => 'Auto-deduct BOM quantities',
        'production' => 'Log batch consumption',
        'purchasing' => 'Trigger low-stock PO',
        'status' => 'Action required',
    ],
    [
        'stage' => 'QC approval',
        'inventory' => 'Reconcile scrap + waste',
        'production' => 'Close work order',
        'purchasing' => 'Update vendor score',
        'status' => 'On track',
    ],
    [
        'stage' => 'Finished goods',
        'inventory' => 'Receive FG into storage',
        'production' => 'Hand off to fulfillment',
        'purchasing' => 'Confirm inbound coverage',
        'status' => 'Scheduled',
    ],
];

$supply_chain_signals = [
    [
        'title' => 'Material deduction queue',
        'detail' => 'Tracks production steps that have not yet synced to inventory balances.',
        'icon' => 'fas fa-layer-group',
    ],
    [
        'title' => 'Purchase trigger thresholds',
        'detail' => 'Highlights SKUs that dip below safety stock based on active work orders.',
        'icon' => 'fas fa-circle-exclamation',
    ],
    [
        'title' => 'Finished goods logging',
        'detail' => 'Captures completed items, lot numbers, and storage zones for dispatch.',
        'icon' => 'fas fa-clipboard-check',
    ],
];

$automation_rules = [
    [
        'title' => 'Material deduction',
        'detail' => 'Deduct thread, stabilizers, and blanks as production starts to keep real-time stock accuracy.',
        'icon' => 'fas fa-robot',
    ],
    [
        'title' => 'Purchase triggers',
        'detail' => 'Auto-create purchase requests when projected usage breaches reorder points.',
        'icon' => 'fas fa-cart-shopping',
    ],
    [
        'title' => 'Finished goods logging',
        'detail' => 'Log completed items to finished goods storage with batch traceability.',
        'icon' => 'fas fa-boxes-packing',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory–Production–Supply Chain Automation Engine - Owner</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .automation-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .sync-kpi {
            grid-column: span 3;
        }

        .purpose-card,
        .signals-card {
            grid-column: span 12;
        }

        .sync-map-card {
            grid-column: span 8;
        }

        .automation-card {
            grid-column: span 4;
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

        .signal-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1rem;
        }

        .signal-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
            display: flex;
            gap: 0.75rem;
            align-items: flex-start;
        }

        .signal-item i {
            color: var(--primary-600);
            margin-top: 0.25rem;
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
                    <h2>Inventory–Production–Supply Chain Automation Engine</h2>
                    <p class="text-muted">Synchronize inventory, production, and purchasing workflows with a unified control layer.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-sitemap"></i> Module 26</span>
            </div>
        </div>

        <div class="automation-grid">
            <div class="card purpose-card">
                <div class="card-header">
                    <h3><i class="fas fa-bullseye text-primary"></i> Purpose</h3>
                </div>
                <p class="text-muted mb-0">
                    Synchronizes inventory balances, production activity, and purchasing decisions so material usage, supply
                    triggers, and finished goods updates stay aligned in real time.
                </p>
            </div>

            <?php foreach ($sync_kpis as $kpi): ?>
                <div class="card sync-kpi">
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

            <div class="card sync-map-card">
                <div class="card-header">
                    <h3><i class="fas fa-diagram-project text-primary"></i> Synchronization Map</h3>
                    <p class="text-muted">Key handoffs across inventory, production, and purchasing.</p>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Stage</th>
                            <th>Inventory</th>
                            <th>Production</th>
                            <th>Purchasing</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sync_events as $event): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($event['stage']); ?></td>
                                <td><?php echo htmlspecialchars($event['inventory']); ?></td>
                                <td><?php echo htmlspecialchars($event['production']); ?></td>
                                <td><?php echo htmlspecialchars($event['purchasing']); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $event['status'] === 'Action required' ? 'warning' : 'success'; ?>">
                                        <?php echo htmlspecialchars($event['status']); ?>
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
                    <p class="text-muted">Rules that keep the supply chain engine in sync.</p>
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

            <div class="card signals-card">
                <div class="card-header">
                    <h3><i class="fas fa-wave-square text-primary"></i> Supply Chain Signals</h3>
                    <p class="text-muted">Operational indicators feeding the automation engine.</p>
                </div>
                <div class="signal-list">
                    <?php foreach ($supply_chain_signals as $signal): ?>
                        <div class="signal-item">
                            <i class="<?php echo $signal['icon']; ?>"></i>
                            <div>
                                <strong><?php echo $signal['title']; ?></strong>
                                <p class="text-muted mb-0"><?php echo $signal['detail']; ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
