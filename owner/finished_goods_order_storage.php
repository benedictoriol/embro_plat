<?php
session_start();
require_once '../config/db.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$shop_stmt = $pdo->prepare("SELECT shop_name FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch();

$storage_kpis = [
    [
        'label' => 'Orders in staging',
        'value' => 14,
        'note' => 'Awaiting pickup or delivery.',
        'icon' => 'fas fa-box-open',
        'tone' => 'primary',
    ],
    [
        'label' => 'Ready for dispatch',
        'value' => 6,
        'note' => 'Packed and labeled.',
        'icon' => 'fas fa-truck-fast',
        'tone' => 'success',
    ],
    [
        'label' => 'Pending client pickup',
        'value' => 5,
        'note' => 'Scheduled for pickup.',
        'icon' => 'fas fa-hand-holding',
        'tone' => 'info',
    ],
    [
        'label' => 'Aging over 48 hrs',
        'value' => 3,
        'note' => 'Needs follow-up.',
        'icon' => 'fas fa-clock',
        'tone' => 'warning',
    ],
];

$finished_orders = [
    [
        'order' => 'Order #4621',
        'client' => 'Horizon Athletics',
        'channel' => 'Delivery',
        'items' => '18 polos',
        'location' => 'FG-A1',
        'status' => 'Ready',
    ],
    [
        'order' => 'Order #4624',
        'client' => 'Luna Cafe',
        'channel' => 'Pickup',
        'items' => '12 aprons',
        'location' => 'FG-B2',
        'status' => 'Awaiting pickup',
    ],
    [
        'order' => 'Order #4628',
        'client' => 'Bridgeway Realty',
        'channel' => 'Delivery',
        'items' => '30 caps',
        'location' => 'FG-C1',
        'status' => 'Packed',
    ],
    [
        'order' => 'Order #4630',
        'client' => 'Riverline School',
        'channel' => 'Pickup',
        'items' => '40 patches',
        'location' => 'FG-B4',
        'status' => 'Awaiting pickup',
    ],
];

$storage_zones = [
    [
        'zone' => 'FG-A1',
        'type' => 'Shelf row',
        'capacity' => '12 orders',
        'utilization' => '75%',
        'status' => 'Available',
    ],
    [
        'zone' => 'FG-B2',
        'type' => 'Pickup rack',
        'capacity' => '8 orders',
        'utilization' => '62%',
        'status' => 'Active',
    ],
    [
        'zone' => 'FG-C1',
        'type' => 'Delivery dock',
        'capacity' => '10 orders',
        'utilization' => '80%',
        'status' => 'Busy',
    ],
];

$release_schedule = [
    [
        'slot' => 'Today, 2:00 PM',
        'orders' => '3 orders',
        'channel' => 'Delivery',
        'note' => 'Route: East Metro',
    ],
    [
        'slot' => 'Today, 4:30 PM',
        'orders' => '2 orders',
        'channel' => 'Pickup',
        'note' => 'Clients notified',
    ],
    [
        'slot' => 'Tomorrow, 9:00 AM',
        'orders' => '4 orders',
        'channel' => 'Delivery',
        'note' => 'Courier booked',
    ],
];

$automation_rules = [
    [
        'title' => 'QC pass registration',
        'detail' => 'Auto-register completed orders into Finished Goods storage after QC approval.',
        'icon' => 'fas fa-clipboard-check',
    ],
    [
        'title' => 'Pickup notification',
        'detail' => 'Send pickup-ready alerts to clients once orders are shelved.',
        'icon' => 'fas fa-bell',
    ],
    [
        'title' => 'Dispatch manifest',
        'detail' => 'Build delivery manifests with location labels and route grouping.',
        'icon' => 'fas fa-route',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finished Goods &amp; Order Storage Module - Owner</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .storage-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .storage-kpi {
            grid-column: span 3;
        }

        .purpose-card,
        .schedule-card {
            grid-column: span 12;
        }

        .orders-card {
            grid-column: span 8;
        }

        .zones-card,
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

        .schedule-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
        }

        .schedule-item + .schedule-item {
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
                    <h2>Finished Goods &amp; Order Storage</h2>
                    <p class="text-muted">Track completed orders, assign storage locations, and coordinate pickup or delivery handoffs.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-warehouse"></i> Module 25</span>
            </div>
        </div>

        <div class="storage-grid">
            <div class="card purpose-card">
                <div class="card-header">
                    <h3><i class="fas fa-bullseye text-primary"></i> Purpose</h3>
                </div>
                <p class="text-muted mb-0">
                    Stores completed orders awaiting delivery or pickup, keeping finished goods organized, labeled, and
                    dispatch-ready.
                </p>
            </div>

            <?php foreach ($storage_kpis as $kpi): ?>
                <div class="card storage-kpi">
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

            <div class="card orders-card">
                <div class="card-header">
                    <h3><i class="fas fa-boxes-packing text-primary"></i> Finished Goods Queue</h3>
                    <p class="text-muted">Orders in storage with location and release channel.</p>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Client</th>
                            <th>Channel</th>
                            <th>Items</th>
                            <th>Location</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($finished_orders as $order): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($order['order']); ?></td>
                                <td><?php echo htmlspecialchars($order['client']); ?></td>
                                <td><?php echo htmlspecialchars($order['channel']); ?></td>
                                <td><?php echo htmlspecialchars($order['items']); ?></td>
                                <td><?php echo htmlspecialchars($order['location']); ?></td>
                                <td><?php echo htmlspecialchars($order['status']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card zones-card">
                <div class="card-header">
                    <h3><i class="fas fa-map-location-dot text-primary"></i> Storage Zones</h3>
                    <p class="text-muted">Finished goods bins with utilization status.</p>
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
                        <?php foreach ($storage_zones as $zone): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($zone['zone']); ?></td>
                                <td><?php echo htmlspecialchars($zone['type']); ?></td>
                                <td><?php echo htmlspecialchars($zone['capacity']); ?></td>
                                <td><?php echo htmlspecialchars($zone['utilization']); ?></td>
                                <td><?php echo htmlspecialchars($zone['status']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card automation-card">
                <div class="card-header">
                    <h3><i class="fas fa-gear text-primary"></i> Automation</h3>
                    <p class="text-muted">Rules that keep handoffs and notifications synchronized.</p>
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

            <div class="card schedule-card">
                <div class="card-header">
                    <h3><i class="fas fa-calendar-check text-primary"></i> Release Schedule</h3>
                    <p class="text-muted">Upcoming pickup and delivery windows.</p>
                </div>
                <?php foreach ($release_schedule as $slot): ?>
                    <div class="schedule-item">
                        <div class="d-flex justify-between align-center mb-2">
                            <strong><?php echo htmlspecialchars($slot['slot']); ?></strong>
                            <span class="badge badge-secondary"><?php echo htmlspecialchars($slot['channel']); ?></span>
                        </div>
                        <p class="text-muted mb-1"><?php echo htmlspecialchars($slot['orders']); ?></p>
                        <small class="text-muted"><?php echo htmlspecialchars($slot['note']); ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</body>
</html>
