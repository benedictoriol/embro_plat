<?php
session_start();
require_once '../config/db.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$shop_stmt = $pdo->prepare("SELECT shop_name FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch();

$warehouse_kpis = [
    [
        'label' => 'Active locations',
        'value' => 24,
        'note' => 'Racks, bins, and staging bays.',
        'icon' => 'fas fa-location-dot',
        'tone' => 'primary',
    ],
    [
        'label' => 'Space utilization',
        'value' => '78%',
        'note' => 'Used vs. available capacity.',
        'icon' => 'fas fa-warehouse',
        'tone' => 'info',
    ],
    [
        'label' => 'Open pick-lists',
        'value' => 5,
        'note' => 'Ready for fulfillment.',
        'icon' => 'fas fa-clipboard-list',
        'tone' => 'warning',
    ],
    [
        'label' => 'Movements today',
        'value' => 18,
        'note' => 'Transfers & replenishments.',
        'icon' => 'fas fa-right-left',
        'tone' => 'success',
    ],
];

$storage_locations = [
    [
        'zone' => 'Thread Rack A',
        'type' => 'Racking',
        'capacity' => '120 cones',
        'utilization' => '82%',
        'status' => 'Healthy',
    ],
    [
        'zone' => 'Stabilizer Bay B',
        'type' => 'Pallet bay',
        'capacity' => '48 rolls',
        'utilization' => '64%',
        'status' => 'Available',
    ],
    [
        'zone' => 'Foam Bin C3',
        'type' => 'Small bin',
        'capacity' => '30 packs',
        'utilization' => '93%',
        'status' => 'Nearly full',
    ],
    [
        'zone' => 'Finished Goods Staging',
        'type' => 'Staging',
        'capacity' => '20 orders',
        'utilization' => '55%',
        'status' => 'Available',
    ],
];

$pick_lists = [
    [
        'reference' => 'PL-1024',
        'order' => 'Order #4581',
        'items' => '5 materials',
        'zone' => 'Thread Rack A',
        'deadline' => 'Today, 3:00 PM',
        'priority' => 'Urgent',
    ],
    [
        'reference' => 'PL-1025',
        'order' => 'Order #4583',
        'items' => '3 materials',
        'zone' => 'Stabilizer Bay B',
        'deadline' => 'Tomorrow, 10:00 AM',
        'priority' => 'Standard',
    ],
    [
        'reference' => 'PL-1026',
        'order' => 'Order #4587',
        'items' => '4 materials',
        'zone' => 'Finished Goods Staging',
        'deadline' => 'Tomorrow, 1:00 PM',
        'priority' => 'Standard',
    ],
];

$movement_log = [
    [
        'item' => 'Polyester thread - Navy',
        'from' => 'Thread Rack A',
        'to' => 'Production Floor',
        'quantity' => '6 cones',
        'time' => '10:15 AM',
        'status' => 'Completed',
    ],
    [
        'item' => 'Cut-away stabilizer 70gsm',
        'from' => 'Stabilizer Bay B',
        'to' => 'QC Holding',
        'quantity' => '2 rolls',
        'time' => '11:05 AM',
        'status' => 'Completed',
    ],
    [
        'item' => '3D foam sheets (3mm)',
        'from' => 'Receiving Dock',
        'to' => 'Foam Bin C3',
        'quantity' => '4 packs',
        'time' => '12:30 PM',
        'status' => 'In progress',
    ],
];

$automation_rules = [
    [
        'title' => 'Pick-list generation',
        'detail' => 'Auto-build pick-lists when orders move to production, grouped by zone and batch.',
        'icon' => 'fas fa-list-check',
    ],
    [
        'title' => 'Stock movement tracking',
        'detail' => 'Scan items in/out to log transfers, replenishments, and staging handoffs in real time.',
        'icon' => 'fas fa-arrows-rotate',
    ],
    [
        'title' => 'Cycle count prompts',
        'detail' => 'Prompt cycle counts for high-velocity bins weekly to maintain location accuracy.',
        'icon' => 'fas fa-clipboard-check',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Storage &amp; Warehouse Management Module - Owner</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .warehouse-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .warehouse-kpi {
            grid-column: span 3;
        }

        .purpose-card,
        .movement-card {
            grid-column: span 12;
        }

        .location-card {
            grid-column: span 8;
        }

        .picklist-card,
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

        .queue-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
        }

        .queue-item + .queue-item {
            margin-top: 1rem;
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
                    <h2>Storage &amp; Warehouse Management</h2>
                    <p class="text-muted">Organize storage locations, build pick-lists, and monitor stock movement with confidence.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-boxes-stacked"></i> Module 24</span>
            </div>
        </div>

        <div class="warehouse-grid">
            <div class="card purpose-card">
                <div class="card-header">
                    <h3><i class="fas fa-bullseye text-primary"></i> Purpose</h3>
                </div>
                <p class="text-muted mb-0">
                    Manages physical storage locations to keep embroidery materials and finished goods organized, traceable,
                    and ready for picking.
                </p>
            </div>

            <?php foreach ($warehouse_kpis as $kpi): ?>
                <div class="card warehouse-kpi">
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

            <div class="card location-card">
                <div class="card-header">
                    <h3><i class="fas fa-map-location-dot text-primary"></i> Storage Locations</h3>
                    <p class="text-muted">Active zones with capacity and utilization monitoring.</p>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Zone</th>
                            <th>Type</th>
                            <th>Capacity</th>
                            <th>Utilization</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($storage_locations as $location): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($location['zone']); ?></td>
                                <td><?php echo htmlspecialchars($location['type']); ?></td>
                                <td><?php echo htmlspecialchars($location['capacity']); ?></td>
                                <td><?php echo htmlspecialchars($location['utilization']); ?></td>
                                <td><?php echo htmlspecialchars($location['status']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card picklist-card">
                <div class="card-header">
                    <h3><i class="fas fa-clipboard-list text-primary"></i> Pick-list Queue</h3>
                    <p class="text-muted">Auto-generated picks grouped by storage zone.</p>
                </div>
                <?php foreach ($pick_lists as $pick): ?>
                    <div class="queue-item">
                        <div class="d-flex justify-between align-center mb-2">
                            <strong><?php echo htmlspecialchars($pick['reference']); ?></strong>
                            <span class="badge badge-<?php echo $pick['priority'] === 'Urgent' ? 'danger' : 'secondary'; ?>">
                                <?php echo htmlspecialchars($pick['priority']); ?>
                            </span>
                        </div>
                        <p class="text-muted mb-1"><?php echo htmlspecialchars($pick['order']); ?></p>
                        <p class="text-muted mb-1">Items: <?php echo htmlspecialchars($pick['items']); ?></p>
                        <p class="text-muted mb-0">Zone: <?php echo htmlspecialchars($pick['zone']); ?></p>
                        <small class="text-muted">Due: <?php echo htmlspecialchars($pick['deadline']); ?></small>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card automation-card">
                <div class="card-header">
                    <h3><i class="fas fa-gear text-primary"></i> Automation</h3>
                    <p class="text-muted">Pick and movement tasks that stay synchronized.</p>
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

            <div class="card movement-card">
                <div class="card-header">
                    <h3><i class="fas fa-right-left text-primary"></i> Stock Movement Tracking</h3>
                    <p class="text-muted">Every transfer logged for traceability and audit readiness.</p>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Quantity</th>
                            <th>Time</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($movement_log as $movement): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($movement['item']); ?></td>
                                <td><?php echo htmlspecialchars($movement['from']); ?></td>
                                <td><?php echo htmlspecialchars($movement['to']); ?></td>
                                <td><?php echo htmlspecialchars($movement['quantity']); ?></td>
                                <td class="text-muted"><?php echo htmlspecialchars($movement['time']); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $movement['status'] === 'Completed' ? 'success' : 'warning'; ?>">
                                        <?php echo htmlspecialchars($movement['status']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
