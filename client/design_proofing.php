<?php
session_start();
require_once '../config/db.php';
require_role('client');

$client_id = $_SESSION['user']['id'];
$unread_notifications = fetch_unread_notification_count($pdo, $client_id);

$coreProcess = [
    [
        'title' => 'Design submitted',
        'detail' => 'Client uploads artwork or updates notes for the shop review.',
    ],
    [
        'title' => 'Proof prepared',
        'detail' => 'Shop returns a production-ready proof with colors, sizing, and placement.',
    ],
    [
        'title' => 'Client approves or requests revision',
        'detail' => 'Approval or revision feedback is captured before moving forward.',
    ],
    [
        'title' => 'Production unlocked',
        'detail' => 'Only approved proofs can be released to production and scheduling.',
    ],
];

$automation = [
    [
        'title' => 'Approval reminders',
        'detail' => 'Automated nudges keep approvals moving before delivery deadlines.',
        'icon' => 'fas fa-bell',
    ],
    [
        'title' => 'Production lock enforcement',
        'detail' => 'System blocks production start until an approval is recorded.',
        'icon' => 'fas fa-lock',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Design Proofing & Approval Module - Client</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .proofing-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .overview-card {
            grid-column: span 12;
        }

        .process-card {
            grid-column: span 7;
        }

        .automation-card {
            grid-column: span 5;
        }

        .process-step {
            display: flex;
            gap: 1rem;
            align-items: flex-start;
            padding: 1rem;
            border-radius: var(--radius);
            border: 1px solid var(--gray-200);
            background: var(--bg-primary);
        }

        .process-step .badge {
            width: 2rem;
            height: 2rem;
            border-radius: var(--radius-full);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            background: var(--primary-100);
            color: var(--primary-700);
        }

        .process-list {
            display: grid;
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
                <i class="fas fa-user"></i> Client Portal
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle">
                        <i class="fas fa-clipboard-list"></i> Orders
                    </a>
                    <div class="dropdown-menu">
                        <a href="place_order.php" class="dropdown-item"><i class="fas fa-plus-circle"></i> Place Order</a>
                        <a href="track_order.php" class="dropdown-item"><i class="fas fa-route"></i> Track Orders</a>
                    </div>
                </li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle active">
                        <i class="fas fa-layer-group"></i> Services
                    </a>
                    <div class="dropdown-menu">
                        <a href="customize_design.php" class="dropdown-item"><i class="fas fa-paint-brush"></i> Customize Design</a>
                        <a href="rate_provider.php" class="dropdown-item"><i class="fas fa-star"></i> Rate Provider</a>
                        <a href="search_discovery.php" class="dropdown-item"><i class="fas fa-compass"></i> Search &amp; Discovery</a>
                        <a href="design_proofing.php" class="dropdown-item active"><i class="fas fa-clipboard-check"></i> Design Proofing &amp; Approval</a>
                        <a href="pricing_quotation.php" class="dropdown-item"><i class="fas fa-calculator"></i> Pricing &amp; Quotation</a>
                        <a href="order_management.php" class="dropdown-item"><i class="fas fa-clipboard-list"></i> Order Management</a>
                        <a href="payment_handling.php" class="dropdown-item"><i class="fas fa-hand-holding-dollar"></i> Payment Handling &amp; Release</a>
                        <a href="client_posting_community.php" class="dropdown-item"><i class="fas fa-comments"></i> Client Posting &amp; Community</a>
                    </div>
                </li>
                <li><a href="messages.php" class="nav-link">Messages</a></li>
                <li><a href="notifications.php" class="nav-link">Notifications
                    <?php if ($unread_notifications > 0): ?>
                        <span class="badge badge-danger"><?php echo $unread_notifications; ?></span>
                    <?php endif; ?>
                </a></li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle">
                        <i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($_SESSION['user']['fullname']); ?>
                    </a>
                    <div class="dropdown-menu">
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
                    <h2>Design Proofing &amp; Approval</h2>
                    <p class="text-muted">Keep production locked until every proof is approved by the client.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-clipboard-check"></i> Module 9</span>
            </div>
        </div>

        <div class="proofing-grid">
            <div class="card overview-card">
                <div class="card-header">
                    <h3><i class="fas fa-shield-halved text-primary"></i> Purpose</h3>
                </div>
                <p class="text-muted mb-0">
                    Prevents production without client approval by capturing feedback, documenting sign-off,
                    and unlocking production only after an approved proof.
                </p>
            </div>

            <div class="card process-card">
                <div class="card-header">
                    <h3><i class="fas fa-route text-primary"></i> Core Process</h3>
                    <p class="text-muted">From proof submission through approval gates.</p>
                </div>
                <div class="process-list">
                    <?php foreach ($coreProcess as $index => $step): ?>
                        <div class="process-step">
                            <span class="badge"><?php echo $index + 1; ?></span>
                            <div>
                                <strong><?php echo $step['title']; ?></strong>
                                <p class="text-muted mb-0"><?php echo $step['detail']; ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card automation-card">
                <div class="card-header">
                    <h3><i class="fas fa-robot text-primary"></i> Automation</h3>
                    <p class="text-muted">Reminders and safeguards that keep approvals on track.</p>
                </div>
                <div class="d-flex flex-column gap-3">
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
