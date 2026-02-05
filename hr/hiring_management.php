<?php
session_start();
require_once '../config/db.php';
require_role('hr');

$hr_name = htmlspecialchars($_SESSION['user']['fullname'] ?? 'HR Lead');

$hiring_kpis = [
    [
        'label' => 'Open roles',
        'value' => 8,
        'note' => 'Across 3 departments',
        'icon' => 'fas fa-briefcase',
        'tone' => 'primary',
    ],
    [
        'label' => 'Applicants this week',
        'value' => 46,
        'note' => 'Up 12% vs last week',
        'icon' => 'fas fa-user-plus',
        'tone' => 'success',
    ],
    [
        'label' => 'Interviews scheduled',
        'value' => 12,
        'note' => 'Next 5 business days',
        'icon' => 'fas fa-calendar-check',
        'tone' => 'info',
    ],
    [
        'label' => 'Offers pending',
        'value' => 3,
        'note' => 'Awaiting approvals',
        'icon' => 'fas fa-file-signature',
        'tone' => 'warning',
    ],
];

$active_postings = [
    [
        'role' => 'Senior Embroidery Technician',
        'team' => 'Production',
        'location' => 'Onsite - Cebu City',
        'posted' => 'Aug 12, 2024',
        'expires' => 'Sep 12, 2024',
        'status' => 'Live',
    ],
    [
        'role' => 'Quality Control Lead',
        'team' => 'Quality Assurance',
        'location' => 'Onsite - Makati',
        'posted' => 'Aug 18, 2024',
        'expires' => 'Sep 18, 2024',
        'status' => 'Live',
    ],
    [
        'role' => 'Customer Success Associate',
        'team' => 'Client Support',
        'location' => 'Hybrid - Taguig',
        'posted' => 'Aug 21, 2024',
        'expires' => 'Sep 4, 2024',
        'status' => 'Closing soon',
    ],
    [
        'role' => 'Warehouse Runner',
        'team' => 'Logistics',
        'location' => 'Onsite - Quezon City',
        'posted' => 'Aug 23, 2024',
        'expires' => 'Sep 23, 2024',
        'status' => 'Live',
    ],
];

$visibility_channels = [
    [
        'channel' => 'Internal talent pool',
        'audience' => 'Active & alumni staff',
        'reach' => '85 profiles',
        'status' => 'Active',
    ],
    [
        'channel' => 'Public job boards',
        'audience' => 'External candidates',
        'reach' => '3 platforms',
        'status' => 'Scheduled',
    ],
    [
        'channel' => 'Referral program',
        'audience' => 'Employee referrals',
        'reach' => '12 ambassadors',
        'status' => 'Active',
    ],
];

$alert_queue = [
    [
        'title' => 'New applicant',
        'detail' => 'Alina Reyes applied for Senior Embroidery Technician.',
        'time' => '2h ago',
        'tone' => 'primary',
    ],
    [
        'title' => 'Interview feedback overdue',
        'detail' => 'QC Lead panel feedback pending for 2 candidates.',
        'time' => 'Today',
        'tone' => 'warning',
    ],
    [
        'title' => 'Posting expiring soon',
        'detail' => 'Customer Success Associate role expires in 3 days.',
        'time' => 'Tomorrow',
        'tone' => 'danger',
    ],
];

$automation_rules = [
    [
        'title' => 'Auto-expiration',
        'detail' => 'Close hiring posts 30 days after publishing unless extended.',
        'icon' => 'fas fa-hourglass-end',
    ],
    [
        'title' => 'Hiring alerts',
        'detail' => 'Notify HR leads and hiring managers when new applicants arrive.',
        'icon' => 'fas fa-bell',
    ],
    [
        'title' => 'Visibility booster',
        'detail' => 'Refresh listings across channels every 7 days for priority roles.',
        'icon' => 'fas fa-bullhorn',
    ],
];

$workflow_steps = [
    [
        'title' => 'Post request intake',
        'detail' => 'Capture hiring need, budget approval, and target start date.',
    ],
    [
        'title' => 'Publish & distribute',
        'detail' => 'Launch across internal pools, referrals, and job boards.',
    ],
    [
        'title' => 'Screen & shortlist',
        'detail' => 'Score applicants, schedule interviews, and log feedback.',
    ],
    [
        'title' => 'Offer & onboarding',
        'detail' => 'Issue offer letters and coordinate onboarding tasks.',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR Hiring Management Module</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .hiring-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .hiring-kpi {
            grid-column: span 3;
        }

        .hiring-kpi .metric {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .hiring-kpi .metric i {
            font-size: 1.5rem;
        }

        .purpose-card,
        .workflow-card {
            grid-column: span 12;
        }

        .postings-card {
            grid-column: span 8;
        }

        .channels-card,
        .alerts-card {
            grid-column: span 4;
        }

        .automation-card {
            grid-column: span 6;
        }

        .alerts-card .alert-item,
        .automation-card .automation-item,
        .workflow-step {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
        }

        .alert-item + .alert-item,
        .automation-item + .automation-item,
        .workflow-step + .workflow-step {
            margin-top: 1rem;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar--compact">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-people-group"></i> <?php echo $hr_name; ?>
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <li><a href="hiring_management.php" class="nav-link active">Hiring</a></li>
                <li><a href="staff_productivity_performance.php" class="nav-link">Productivity</a></li>
                <li><a href="payroll_compensation.php" class="nav-link">Payroll</a></li>
                <li><a href="analytics_reporting.php" class="nav-link">Analytics</a></li>
                <li><a href="../auth/logout.php" class="nav-link">Logout</a></li>
            </ul>
        </div>
    </nav>

    <main class="container">
        <section class="page-header">
            <div>
                <h1>HR Hiring Management</h1>
                <p class="text-muted">Manage hiring posts, visibility, and automated follow-ups.</p>
            </div>
            <span class="badge badge-primary"><i class="fas fa-user-tie"></i> Module 27</span>
        </section>

        <section class="hiring-grid">
            <?php foreach ($hiring_kpis as $kpi): ?>
                <div class="card hiring-kpi">
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

            <div class="card purpose-card">
                <h2>Purpose</h2>
                <p class="text-muted">Centralize hiring requests, job post visibility, and talent pipeline monitoring.</p>
            </div>

            <div class="card postings-card">
                <h2>Active hiring posts</h2>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Role</th>
                                <th>Team</th>
                                <th>Location</th>
                                <th>Posted</th>
                                <th>Expires</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($active_postings as $posting): ?>
                                <tr>
                                    <td><?php echo $posting['role']; ?></td>
                                    <td><?php echo $posting['team']; ?></td>
                                    <td><?php echo $posting['location']; ?></td>
                                    <td><?php echo $posting['posted']; ?></td>
                                    <td><?php echo $posting['expires']; ?></td>
                                    <td><span class="badge badge-outline"><?php echo $posting['status']; ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card channels-card">
                <h2>Visibility channels</h2>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Channel</th>
                                <th>Audience</th>
                                <th>Reach</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($visibility_channels as $channel): ?>
                                <tr>
                                    <td><?php echo $channel['channel']; ?></td>
                                    <td><?php echo $channel['audience']; ?></td>
                                    <td><?php echo $channel['reach']; ?></td>
                                    <td><span class="badge badge-outline"><?php echo $channel['status']; ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card alerts-card">
                <h2>Hiring alerts</h2>
                <?php foreach ($alert_queue as $alert): ?>
                    <div class="alert-item">
                        <span class="badge badge-<?php echo $alert['tone']; ?> mb-2"><?php echo $alert['title']; ?></span>
                        <p class="mb-2"><?php echo $alert['detail']; ?></p>
                        <small class="text-muted"><?php echo $alert['time']; ?></small>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card automation-card">
                <h2>Automation</h2>
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

            <div class="card workflow-card">
                <h2>Hiring workflow</h2>
                <?php foreach ($workflow_steps as $step): ?>
                    <div class="workflow-step">
                        <strong><?php echo $step['title']; ?></strong>
                        <p class="text-muted mb-0"><?php echo $step['detail']; ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </main>
</body>
</html>
