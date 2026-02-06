<?php
session_start();
require_once '../config/db.php';
require_role('client');

$client_id = $_SESSION['user']['id'];
$unread_notifications = fetch_unread_notification_count($pdo, $client_id);

$coreFunctions = [
    [
        'title' => 'Shop browsing and filtering',
        'detail' => 'Search by location, service type, turnaround time, and rating to find the best match.',
        'icon' => 'fas fa-filter',
        'tone' => 'primary',
    ],
    [
        'title' => 'Availability display',
        'detail' => 'Surface shop capacity, lead times, and open order slots before booking.',
        'icon' => 'fas fa-calendar-check',
        'tone' => 'success',
    ],
    [
        'title' => 'Hiring shop discovery',
        'detail' => 'Highlight shops that are actively hiring and accept new staff applications.',
        'icon' => 'fas fa-briefcase',
        'tone' => 'info',
    ],
];

$coreProcess = [
    'Client searches or filters',
    'System ranks results',
    'Client selects shop or hiring post',
];

$automation = [
    [
        'title' => 'Popular & nearby shop suggestions',
        'detail' => 'Recommend top-rated and location-relevant shops based on recent activity.',
        'icon' => 'fas fa-map-marker-alt',
    ],
    [
        'title' => 'Auto-highlight hiring shops',
        'detail' => 'Surface open hiring posts with a boosted badge and priority placement.',
        'icon' => 'fas fa-bullhorn',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search & Discovery Module - Client</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .discovery-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .overview-card {
            grid-column: span 12;
        }

        .functions-card {
            grid-column: span 7;
        }

        .process-card {
            grid-column: span 5;
        }

        .automation-card {
            grid-column: span 12;
        }

        .function-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
        }

        .function-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: var(--bg-primary);
        }

        .function-item .icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: var(--radius-full);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 0.75rem;
            color: white;
        }

        .bg-primary { background: var(--primary-600); }
        .bg-success { background: var(--success-600); }
        .bg-info { background: var(--info-600); }

        .process-step {
            display: flex;
            gap: 1rem;
            align-items: flex-start;
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

        .automation-grid {
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
                        <a href="search_discovery.php" class="dropdown-item active"><i class="fas fa-compass"></i> Search & Discovery</a>
                        <a href="design_proofing.php" class="dropdown-item"><i class="fas fa-clipboard-check"></i> Design Proofing &amp; Approval</a>
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
                    <h2>Search, Discovery & Hiring Visibility</h2>
                    <p class="text-muted">Find the right shop, check availability, and explore hiring opportunities.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-compass"></i> Module 6</span>
            </div>
        </div>

        <div class="discovery-grid">
            <div class="card overview-card">
                <div class="card-header">
                    <h3><i class="fas fa-bullseye text-primary"></i> Purpose</h3>
                </div>
                <p class="text-muted mb-0">
                    Allows clients to discover shops, services, and hiring opportunities with targeted filters,
                    smart ranking, and availability insights.
                </p>
            </div>

            <div class="card functions-card">
                <div class="card-header">
                    <h3><i class="fas fa-list-check text-primary"></i> Core Functions</h3>
                    <p class="text-muted">Capabilities that power discovery and search workflows.</p>
                </div>
                <div class="function-list">
                    <?php foreach ($coreFunctions as $function): ?>
                        <div class="function-item">
                            <span class="icon bg-<?php echo $function['tone']; ?>">
                                <i class="<?php echo $function['icon']; ?>"></i>
                            </span>
                            <h4><?php echo $function['title']; ?></h4>
                            <p class="text-muted mb-0"><?php echo $function['detail']; ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card process-card">
                <div class="card-header">
                    <h3><i class="fas fa-route text-primary"></i> Core Process</h3>
                    <p class="text-muted">How clients move from search to selection.</p>
                </div>
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($coreProcess as $index => $step): ?>
                        <div class="process-step">
                            <span class="badge"><?php echo $index + 1; ?></span>
                            <div>
                                <strong><?php echo $step; ?></strong>
                                <?php if ($index === 0): ?>
                                    <p class="text-muted mb-0">Apply filters for service type, budget, and lead time.</p>
                                <?php elseif ($index === 1): ?>
                                    <p class="text-muted mb-0">Ranked by relevance, ratings, proximity, and availability.</p>
                                <?php else: ?>
                                    <p class="text-muted mb-0">Choose a shop or view hiring details before engaging.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card automation-card">
                <div class="card-header">
                    <h3><i class="fas fa-robot text-primary"></i> Automation</h3>
                    <p class="text-muted">System-driven assists to keep discovery effortless.</p>
                </div>
                <div class="automation-grid">
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
