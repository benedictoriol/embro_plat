<?php
session_start();
require_once '../config/db.php';
require_role('hr');

$hr_name = htmlspecialchars($_SESSION['user']['fullname'] ?? 'HR Lead');

$productivity_kpis = [
    [
        'label' => 'Completion rate',
        'value' => '92%',
        'note' => 'Across all active teams',
        'icon' => 'fas fa-check-double',
        'tone' => 'success',
    ],
    [
        'label' => 'Avg. cycle time',
        'value' => '4.6 hrs',
        'note' => 'Down 8% vs last month',
        'icon' => 'fas fa-stopwatch',
        'tone' => 'primary',
    ],
    [
        'label' => 'QC failure rate',
        'value' => '3.1%',
        'note' => 'Target ≤ 4%',
        'icon' => 'fas fa-triangle-exclamation',
        'tone' => 'warning',
    ],
    [
        'label' => 'Output volume',
        'value' => '1,248 pcs',
        'note' => 'This week to date',
        'icon' => 'fas fa-box-open',
        'tone' => 'info',
    ],
];

$team_performance = [
    [
        'team' => 'Embroidery Line A',
        'completion' => '95%',
        'speed' => '4.1 hrs',
        'qc' => '2.4%',
        'output' => '412 pcs',
        'trend' => 'Upward',
    ],
    [
        'team' => 'Embroidery Line B',
        'completion' => '91%',
        'speed' => '4.8 hrs',
        'qc' => '3.7%',
        'output' => '366 pcs',
        'trend' => 'Stable',
    ],
    [
        'team' => 'Finishing & Pack',
        'completion' => '89%',
        'speed' => '5.2 hrs',
        'qc' => '4.1%',
        'output' => '287 pcs',
        'trend' => 'Watch',
    ],
    [
        'team' => 'QC Review',
        'completion' => '97%',
        'speed' => '3.6 hrs',
        'qc' => '1.9%',
        'output' => '183 pcs',
        'trend' => 'Upward',
    ],
];

$focus_insights = [
    [
        'title' => 'Completion rate',
        'detail' => 'High priority orders are closing 12% faster after rescheduling.',
        'icon' => 'fas fa-chart-line',
    ],
    [
        'title' => 'Speed',
        'detail' => 'Line B benefits from new machine calibration; replicate setup.',
        'icon' => 'fas fa-gauge-high',
    ],
    [
        'title' => 'QC failures',
        'detail' => 'Most defects traced to thread tension on 2 machines.',
        'icon' => 'fas fa-screwdriver-wrench',
    ],
    [
        'title' => 'Output volume',
        'detail' => 'Overtime shift lifted output by 9% this week.',
        'icon' => 'fas fa-layer-group',
    ],
];

$anomalies = [
    [
        'title' => 'Spike in QC failures',
        'detail' => 'Line B hit 6% failure rate on Aug 24. Inspect thread batch.',
        'time' => '2 days ago',
        'tone' => 'danger',
    ],
    [
        'title' => 'Cycle time drift',
        'detail' => 'Finishing tasks averaged 6.1 hrs on the night shift.',
        'time' => 'Yesterday',
        'tone' => 'warning',
    ],
    [
        'title' => 'Output surge',
        'detail' => 'Line A produced 18% above target after automation tweak.',
        'time' => 'Today',
        'tone' => 'success',
    ],
];

$automation_items = [
    [
        'title' => 'KPI computation',
        'detail' => 'Daily refresh of completion, speed, QC, and output metrics.',
        'icon' => 'fas fa-robot',
    ],
    [
        'title' => 'Anomaly detection',
        'detail' => 'Alert HR when metrics deviate beyond threshold bands.',
        'icon' => 'fas fa-bell',
    ],
    [
        'title' => 'Performance nudges',
        'detail' => 'Auto-send coaching prompts for teams below targets.',
        'icon' => 'fas fa-lightbulb',
    ],
];

$workflow_steps = [
    [
        'title' => 'Collect workforce signals',
        'detail' => 'Pull production, QC, and attendance inputs every hour.',
    ],
    [
        'title' => 'Compute KPIs',
        'detail' => 'Normalize metrics by team size, shift, and order complexity.',
    ],
    [
        'title' => 'Detect anomalies',
        'detail' => 'Flag spikes in cycle time and QC failures in real time.',
    ],
    [
        'title' => 'Recommend actions',
        'detail' => 'Surface coaching tasks, maintenance checks, and staffing shifts.',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Productivity &amp; Performance Module</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .performance-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .performance-kpi {
            grid-column: span 3;
        }

        .performance-kpi .metric {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
        }

        .performance-kpi .metric i {
            font-size: 1.5rem;
        }

        .purpose-card,
        .workflow-card {
            grid-column: span 12;
        }

        .team-performance-card {
            grid-column: span 8;
        }

        .insights-card,
        .anomalies-card {
            grid-column: span 4;
        }

        .automation-card {
            grid-column: span 6;
        }

        .anomaly-item,
        .automation-item,
        .workflow-step,
        .insight-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
        }

        .anomaly-item + .anomaly-item,
        .automation-item + .automation-item,
        .workflow-step + .workflow-step,
        .insight-item + .insight-item {
            margin-top: 1rem;
        }

        .trend-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.35rem 0.7rem;
            border-radius: 999px;
            font-size: 0.85rem;
            background: var(--gray-100);
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
                <li><a href="staff_productivity_performance.php" class="nav-link active">Productivity</a></li>
                <li><a href="payroll_compensation.php" class="nav-link">Payroll</a></li>
                <li><a href="analytics_reporting.php" class="nav-link">Analytics</a></li>
                <li><a href="../auth/logout.php" class="nav-link">Logout</a></li>
            </ul>
        </div>
    </nav>

    <main class="container">
        <section class="page-header">
            <div>
                <h1>Staff Productivity &amp; Performance</h1>
                <p class="text-muted">Monitor workforce efficiency, quality, and output with automated insights.</p>
            </div>
            <span class="badge badge-primary"><i class="fas fa-chart-line"></i> Module 28</span>
        </section>

        <section class="performance-grid">
            <?php foreach ($productivity_kpis as $kpi): ?>
                <div class="card performance-kpi">
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
                <p class="text-muted">Give HR a single view of staff throughput, speed, and quality for proactive coaching.</p>
            </div>

            <div class="card team-performance-card">
                <h2>Team performance snapshot</h2>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Team</th>
                                <th>Completion</th>
                                <th>Speed</th>
                                <th>QC failures</th>
                                <th>Output</th>
                                <th>Trend</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($team_performance as $team): ?>
                                <tr>
                                    <td><?php echo $team['team']; ?></td>
                                    <td><?php echo $team['completion']; ?></td>
                                    <td><?php echo $team['speed']; ?></td>
                                    <td><?php echo $team['qc']; ?></td>
                                    <td><?php echo $team['output']; ?></td>
                                    <td><span class="trend-pill"><?php echo $team['trend']; ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card insights-card">
                <h2>Focus insights</h2>
                <?php foreach ($focus_insights as $insight): ?>
                    <div class="insight-item">
                        <div class="d-flex align-center gap-2 mb-2">
                            <i class="<?php echo $insight['icon']; ?> text-primary"></i>
                            <strong><?php echo $insight['title']; ?></strong>
                        </div>
                        <p class="text-muted mb-0"><?php echo $insight['detail']; ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="card anomalies-card">
                <h2>Anomaly detection</h2>
                <?php foreach ($anomalies as $anomaly): ?>
                    <div class="anomaly-item">
                        <span class="badge badge-<?php echo $anomaly['tone']; ?> mb-2"><?php echo $anomaly['title']; ?></span>
                        <p class="mb-2"><?php echo $anomaly['detail']; ?></p>
                        <small class="text-muted"><?php echo $anomaly['time']; ?></small>
                    </div>
                <?php endforeach; ?>
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
                <h2>Performance workflow</h2>
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
