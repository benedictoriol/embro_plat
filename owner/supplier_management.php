<?php
session_start();
require_once '../config/db.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$shop_stmt = $pdo->prepare("SELECT shop_name FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch();

$supplier_kpis = [
    [
        'label' => 'Active suppliers',
        'value' => 6,
        'note' => 'Approved vendors for materials.',
        'icon' => 'fas fa-people-arrows',
        'tone' => 'primary',
    ],
    [
        'label' => 'Average lead time',
        'value' => '4.6 days',
        'note' => 'From PO to delivery.',
        'icon' => 'fas fa-hourglass-half',
        'tone' => 'info',
    ],
    [
        'label' => 'On-time delivery rate',
        'value' => '92%',
        'note' => 'Last 30 days.',
        'icon' => 'fas fa-truck-fast',
        'tone' => 'success',
    ],
    [
        'label' => 'Monthly spend',
        'value' => '₱62,840',
        'note' => 'Material procurement.',
        'icon' => 'fas fa-receipt',
        'tone' => 'warning',
    ],
];

$supplier_scorecards = [
    [
        'name' => 'ThreadWorks Trading',
        'category' => 'Thread & Cones',
        'rating' => '4.7',
        'lead_time' => '3.2 days',
        'fill_rate' => '96%',
        'status' => 'Preferred',
    ],
    [
        'name' => 'Stabilize Supply Co.',
        'category' => 'Stabilizers',
        'rating' => '4.4',
        'lead_time' => '4.1 days',
        'fill_rate' => '91%',
        'status' => 'Approved',
    ],
    [
        'name' => 'FoamForge Materials',
        'category' => '3D Foam',
        'rating' => '4.1',
        'lead_time' => '5.3 days',
        'fill_rate' => '88%',
        'status' => 'Watchlist',
    ],
    [
        'name' => 'Backing & Beyond',
        'category' => 'Backing Fabric',
        'rating' => '4.6',
        'lead_time' => '4.0 days',
        'fill_rate' => '94%',
        'status' => 'Preferred',
    ],
];

$purchase_requests = [
    [
        'item' => 'Rayon thread - Crimson',
        'quantity' => '24 cones',
        'priority' => 'Urgent',
        'target_date' => 'Sept 13',
        'status' => 'Awaiting quote',
    ],
    [
        'item' => 'Cut-away stabilizer 70gsm',
        'quantity' => '10 rolls',
        'priority' => 'Standard',
        'target_date' => 'Sept 18',
        'status' => 'Quotes received',
    ],
    [
        'item' => '3D foam sheets (3mm)',
        'quantity' => '8 packs',
        'priority' => 'Standard',
        'target_date' => 'Sept 20',
        'status' => 'Draft PO',
    ],
];

$automation_rules = [
    [
        'title' => 'Purchase request generation',
        'detail' => 'Auto-generate PRs from low-stock alerts and forecasted production demand each morning.',
        'icon' => 'fas fa-file-circle-plus',
    ],
    [
        'title' => 'Supplier performance tracking',
        'detail' => 'Score vendors weekly based on lead time, fill rate, and QA defects to inform renewals.',
        'icon' => 'fas fa-chart-line',
    ],
    [
        'title' => 'Auto-routing for approvals',
        'detail' => 'Send PRs to the right approver based on budget tier and supplier status.',
        'icon' => 'fas fa-user-check',
    ],
];

$review_checkpoints = [
    [
        'title' => 'Quarterly supplier review',
        'detail' => 'Review scorecards and compliance documents every 90 days.',
        'icon' => 'fas fa-calendar-check',
    ],
    [
        'title' => 'Delivery exception log',
        'detail' => 'Capture late deliveries and short-shipments to support escalation.',
        'icon' => 'fas fa-clipboard-list',
    ],
    [
        'title' => 'Savings opportunities',
        'detail' => 'Flag bundle pricing or alternative vendors based on volume.',
        'icon' => 'fas fa-tags',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Management Module - Owner</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .supplier-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .supplier-kpi {
            grid-column: span 3;
        }

        .purpose-card,
        .review-card {
            grid-column: span 12;
        }

        .scorecard-card {
            grid-column: span 8;
        }

        .purchase-card,
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

        .review-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
        }

        .review-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
            display: flex;
            gap: 0.75rem;
            align-items: flex-start;
        }

        .review-item i {
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
                    <h2>Supplier Management</h2>
                    <p class="text-muted">Coordinate purchasing, monitor supplier health, and keep replenishment on schedule.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-handshake"></i> Module 23</span>
            </div>
        </div>

        <div class="supplier-grid">
            <div class="card purpose-card">
                <div class="card-header">
                    <h3><i class="fas fa-bullseye text-primary"></i> Purpose</h3>
                </div>
                <p class="text-muted mb-0">
                    Handles purchasing and supplier evaluation to ensure embroidery materials arrive on time, at the right quality,
                    and within budget.
                </p>
            </div>

            <?php foreach ($supplier_kpis as $kpi): ?>
                <div class="card supplier-kpi">
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

            <div class="card scorecard-card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-line text-primary"></i> Supplier Scorecards</h3>
                    <p class="text-muted">Performance tracking across lead time, fill rate, and service status.</p>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Supplier</th>
                            <th>Category</th>
                            <th>Rating</th>
                            <th>Lead time</th>
                            <th>Fill rate</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($supplier_scorecards as $supplier): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($supplier['name']); ?></td>
                                <td><?php echo htmlspecialchars($supplier['category']); ?></td>
                                <td><?php echo htmlspecialchars($supplier['rating']); ?></td>
                                <td><?php echo htmlspecialchars($supplier['lead_time']); ?></td>
                                <td><?php echo htmlspecialchars($supplier['fill_rate']); ?></td>
                                <td>
                                    <?php
                                    $status_tone = $supplier['status'] === 'Preferred' ? 'success' : ($supplier['status'] === 'Watchlist' ? 'warning' : 'info');
                                    ?>
                                    <span class="badge badge-<?php echo $status_tone; ?>">
                                        <?php echo htmlspecialchars($supplier['status']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="card purchase-card">
                <div class="card-header">
                    <h3><i class="fas fa-file-signature text-primary"></i> Purchase Requests</h3>
                    <p class="text-muted">Auto-generated requests awaiting sourcing action.</p>
                </div>
                <?php foreach ($purchase_requests as $request): ?>
                    <div class="queue-item">
                        <div class="d-flex justify-between align-center mb-2">
                            <strong><?php echo htmlspecialchars($request['item']); ?></strong>
                            <span class="badge badge-<?php echo $request['priority'] === 'Urgent' ? 'danger' : 'secondary'; ?>">
                                <?php echo htmlspecialchars($request['priority']); ?>
                            </span>
                        </div>
                        <p class="text-muted mb-1">Qty: <?php echo htmlspecialchars($request['quantity']); ?></p>
                        <p class="text-muted mb-1">Target: <?php echo htmlspecialchars($request['target_date']); ?></p>
                        <span class="badge badge-light"><?php echo htmlspecialchars($request['status']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card automation-card">
                <div class="card-header">
                    <h3><i class="fas fa-gear text-primary"></i> Automation</h3>
                    <p class="text-muted">Purchase workflows that stay aligned with demand.</p>
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

            <div class="card review-card">
                <div class="card-header">
                    <h3><i class="fas fa-clipboard-check text-primary"></i> Review &amp; Compliance</h3>
                    <p class="text-muted">Keep suppliers evaluated and aligned with quality standards.</p>
                </div>
                <div class="review-list">
                    <?php foreach ($review_checkpoints as $review): ?>
                        <div class="review-item">
                            <i class="<?php echo $review['icon']; ?>"></i>
                            <div>
                                <strong><?php echo $review['title']; ?></strong>
                                <p class="text-muted mb-0"><?php echo $review['detail']; ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
