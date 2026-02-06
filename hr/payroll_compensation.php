<?php
session_start();
require_once '../config/db.php';
require_role('hr');

$hr_name = htmlspecialchars($_SESSION['user']['fullname'] ?? 'HR Lead');

$payroll_kpis = [
    [
        'label' => 'Active staffs',
        'value' => 128,
        'note' => 'Across production & support',
        'icon' => 'fas fa-users',
        'tone' => 'primary',
    ],
    [
        'label' => 'Next pay run',
        'value' => 'Sep 30',
        'note' => 'Bi-weekly cycle',
        'icon' => 'fas fa-calendar-day',
        'tone' => 'info',
    ],
    [
        'label' => 'Draft payroll total',
        'value' => '₱2.48M',
        'note' => 'Pending HR review',
        'icon' => 'fas fa-coins',
        'tone' => 'warning',
    ],
    [
        'label' => 'Payslips released',
        'value' => '98%',
        'note' => 'Last cycle completion',
        'icon' => 'fas fa-file-invoice-dollar',
        'tone' => 'success',
    ],
];

$pay_periods = [
    [
        'period' => 'Sep 01 - Sep 15',
        'status' => 'Draft',
        'owner' => 'Payroll Ops',
        'payout' => 'Sep 20',
        'total' => '₱1.21M',
    ],
    [
        'period' => 'Aug 16 - Aug 31',
        'status' => 'Paid',
        'owner' => 'Payroll Ops',
        'payout' => 'Sep 05',
        'total' => '₱1.27M',
    ],
    [
        'period' => 'Aug 01 - Aug 15',
        'status' => 'Paid',
        'owner' => 'Payroll Ops',
        'payout' => 'Aug 20',
        'total' => '₱1.19M',
    ],
];

$approval_queue = [
    [
        'title' => 'Overtime reconciliation',
        'detail' => 'Validate 14 overtime entries for Line B.',
        'time' => 'Today',
        'tone' => 'warning',
    ],
    [
        'title' => 'Allowance update',
        'detail' => 'Transport allowance revision for 6 staff.',
        'time' => 'Yesterday',
        'tone' => 'primary',
    ],
    [
        'title' => 'Owner approval pending',
        'detail' => 'Payroll draft ready for owner sign-off.',
        'time' => '2 days ago',
        'tone' => 'success',
    ],
];

$exception_log = [
    [
        'staff' => 'Mara Santos',
        'issue' => 'Missing production output',
        'impact' => 'Auto-rate held',
        'status' => 'Review',
    ],
    [
        'staff' => 'Jonas Lim',
        'issue' => 'Backdated leave entry',
        'impact' => 'Net pay adjusted',
        'status' => 'Resolved',
    ],
    [
        'staff' => 'Ellen Cruz',
        'issue' => 'Shift differential update',
        'impact' => 'Supervisor approval',
        'status' => 'Pending',
    ],
];

$automation_items = [
    [
        'title' => 'Production-based salary',
        'detail' => 'Calculate pay from completed pieces, rate tiers, and quality scores.',
        'icon' => 'fas fa-industry',
    ],
    [
        'title' => 'Payroll draft builder',
        'detail' => 'Auto-generate payroll drafts after timekeeping cutoff.',
        'icon' => 'fas fa-robot',
    ],
    [
        'title' => 'Payslip release',
        'detail' => 'Trigger payslip delivery upon owner approval.',
        'icon' => 'fas fa-paper-plane',
    ],
];

$workflow_steps = [
    [
        'title' => 'Pay period',
        'detail' => 'Lock timekeeping, production output, and allowance inputs.',
    ],
    [
        'title' => 'Payroll draft',
        'detail' => 'Generate earnings, deductions, and incentives per staff.',
    ],
    [
        'title' => 'HR review',
        'detail' => 'Resolve exceptions and validate payroll totals.',
    ],
    [
        'title' => 'Owner approval',
        'detail' => 'Secure sign-off for fund release and compliance.',
    ],
    [
        'title' => 'Payslip release',
        'detail' => 'Distribute payslips and notify staffs automatically.',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payroll &amp; Compensation Module</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .payroll-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .payroll-kpi {
            grid-column: span 3;
        }

        .payroll-kpi .metric {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .payroll-kpi .metric i {
            font-size: 1.5rem;
        }

        .purpose-card,
        .workflow-card {
            grid-column: span 12;
        }

        .periods-card {
            grid-column: span 7;
        }

        .approvals-card,
        .exceptions-card {
            grid-column: span 5;
        }

        .automation-card {
            grid-column: span 6;
        }

        .approval-item,
        .automation-item,
        .workflow-step {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
        }

        .approval-item + .approval-item,
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
                <li><a href="hiring_management.php" class="nav-link">Hiring</a></li>
                <li><a href="staff_productivity_performance.php" class="nav-link">Productivity</a></li>
                <li><a href="payroll_compensation.php" class="nav-link active">Payroll</a></li>
                <li><a href="analytics_reporting.php" class="nav-link">Analytics</a></li>
                <li><a href="../auth/logout.php" class="nav-link">Logout</a></li>
            </ul>
        </div>
    </nav>

    <main class="container">
        <section class="page-header">
            <div>
                <h1>Payroll &amp; Compensation</h1>
                <p class="text-muted">Manage pay cycles, approvals, and production-based compensation in one workspace.</p>
            </div>
            <span class="badge badge-primary"><i class="fas fa-file-invoice-dollar"></i> Module 29</span>
        </section>

        <section class="payroll-grid">
            <?php foreach ($payroll_kpis as $kpi): ?>
                <div class="card payroll-kpi">
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
                <p class="text-muted">Coordinate salary computation, payroll drafts, approvals, and payslip delivery without manual handoffs.</p>
            </div>

            <div class="card periods-card">
                <h2>Pay period overview</h2>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Period</th>
                                <th>Status</th>
                                <th>Owner</th>
                                <th>Payout</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pay_periods as $period): ?>
                                <tr>
                                    <td><?php echo $period['period']; ?></td>
                                    <td><span class="badge badge-outline"><?php echo $period['status']; ?></span></td>
                                    <td><?php echo $period['owner']; ?></td>
                                    <td><?php echo $period['payout']; ?></td>
                                    <td><?php echo $period['total']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card approvals-card">
                <h2>HR review &amp; approvals</h2>
                <?php foreach ($approval_queue as $item): ?>
                    <div class="approval-item">
                        <span class="badge badge-<?php echo $item['tone']; ?> mb-2"><?php echo $item['title']; ?></span>
                        <p class="mb-2"><?php echo $item['detail']; ?></p>
                        <small class="text-muted"><?php echo $item['time']; ?></small>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card exceptions-card">
                <h2>Payroll exceptions</h2>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>staff</th>
                                <th>Issue</th>
                                <th>Impact</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($exception_log as $exception): ?>
                                <tr>
                                    <td><?php echo $exception['staff']; ?></td>
                                    <td><?php echo $exception['issue']; ?></td>
                                    <td><?php echo $exception['impact']; ?></td>
                                    <td><span class="badge badge-outline"><?php echo $exception['status']; ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card automation-card">
                <h2>Automation</h2>
                <?php foreach ($automation_items as $automation): ?>
                    <div class="automation-item">
                        <div class="d-flex align-center gap-2 mb-2">
                            <i class="<?php echo $automation['icon']; ?> text-primary"></i>
                            <strong><?php echo $automation['title']; ?></strong>
                        </div>
                        <p class="text-muted mb-0"><?php echo $automation['detail']; ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card workflow-card">
                <h2>Payroll workflow</h2>
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
