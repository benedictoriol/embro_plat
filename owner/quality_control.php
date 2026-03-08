<?php
session_start();
require_once '../config/db.php';
require_once '../config/constants.php';
require_once '../config/automation_helpers.php';
require_role(['owner', 'staff', 'employee']);
require_staff_position(['qc_staff']);

$user_id = (int) $_SESSION['user']['id'];
$user_role = $_SESSION['user']['role'] ?? null;
if ($user_role === 'owner') {
    $shop_stmt = $pdo->prepare("SELECT id, shop_name, owner_id FROM shops WHERE owner_id = ?");
    $shop_stmt->execute([$user_id]);
} else {
    $shop_stmt = $pdo->prepare(" 
        SELECT s.id, s.shop_name, s.owner_id
        FROM shop_staffs ss
        JOIN shops s ON s.id = ss.shop_id
        WHERE ss.user_id = ? AND ss.status = 'active'
        ORDER BY ss.created_at DESC
        LIMIT 1
    ");
    $shop_stmt->execute([$user_id]);
}
$shop = $shop_stmt->fetch();

if (!$shop) {
    die('No active shop found for this QC account.');
}

$shop_id = (int) ($shop['id'] ?? 0);
$owner_id = (int) ($shop['owner_id'] ?? 0);
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'record_qc_result') {
        $order_id = (int) ($_POST['order_id'] ?? 0);
        $qc_result = strtolower(trim((string) ($_POST['qc_result'] ?? '')));
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if ($order_id <= 0 || !in_array($qc_result, ['passed', 'failed'], true)) {
            $error = 'Please select an order and a QC result.';
        } else {
            $order_stmt = $pdo->prepare(" 
                SELECT id, order_number, status
                FROM orders
                WHERE id = ? AND shop_id = ?
                LIMIT 1
            ");
            $order_stmt->execute([$order_id, $shop_id]);
            $order = $order_stmt->fetch();

            if (!$order) {
                $error = 'Order not found for this shop.';
            } elseif (($order['status'] ?? '') !== STATUS_COMPLETED) {
                $error = 'Only completed orders can be quality checked.';
            } elseif ($qc_result === 'passed') {
                [$created_ok, $create_error, $finished_goods_id, $was_created] = automation_ensure_finished_goods_record(
                    $pdo,
                    $order_id,
                    $shop_id,
                    null,
                    'stored'
                );

                if (!$created_ok) {
                    $error = $create_error ?: 'Unable to save QC pass result.';
                } else {
                    $qc_notes = $notes !== '' ? $notes : 'QC passed.';
                    record_order_status_history(
                        $pdo,
                        $order_id,
                        STATUS_COMPLETED,
                        get_order_progress_for_status(STATUS_COMPLETED),
                        'QC PASS: ' . $qc_notes,
                        $user_id
                    );

                    automation_notify_order_parties(
                        $pdo,
                        $order_id,
                        'order_status',
                        'Order #' . $order['order_number'] . ' passed quality checking and is being prepared for fulfillment.'
                    );

                    automation_log_audit_if_available(
                        $pdo,
                        $user_id,
                        $user_role,
                        $was_created ? 'qc_pass_create_finished_goods' : 'qc_pass_reuse_finished_goods',
                        'orders',
                        $order_id,
                        ['qc_result' => null],
                        [
                            'qc_result' => 'passed',
                            'finished_goods_id' => $finished_goods_id,
                            'finished_goods_created' => $was_created,
                        ]
                    );

                    $success = $was_created
                        ? 'QC passed and finished goods record created. Fulfillment can now proceed.'
                        : 'QC passed. Existing finished goods record retained for fulfillment readiness.';
                }
            } else {
                $qc_notes = $notes !== '' ? $notes : 'QC failed. Rework required.';
                record_order_status_history(
                    $pdo,
                    $order_id,
                    STATUS_COMPLETED,
                    get_order_progress_for_status(STATUS_COMPLETED),
                    'QC FAIL: ' . $qc_notes,
                    $user_id
                );

                if ($owner_id > 0) {
                    create_notification(
                        $pdo,
                        $owner_id,
                        $order_id,
                        'order_status',
                        'QC failed for order #' . $order['order_number'] . '. Review required before fulfillment.'
                    );
                }

                automation_log_audit_if_available(
                    $pdo,
                    $user_id,
                    $user_role,
                    'qc_fail',
                    'orders',
                    $order_id,
                    ['qc_result' => null],
                    ['qc_result' => 'failed', 'notes' => $qc_notes]
                );

                $success = 'QC failed was recorded. No finished goods record was created.';
            }
        }
    }
}

$orders_stmt = $pdo->prepare(" 
    SELECT o.id, o.order_number, o.completed_at, u.fullname AS client_name, fg.id AS finished_goods_id
    FROM orders o
    JOIN users u ON u.id = o.client_id
    LEFT JOIN finished_goods fg ON fg.order_id = o.id
    WHERE o.shop_id = ? AND o.status = 'completed'
    ORDER BY o.completed_at DESC, o.updated_at DESC
");
$orders_stmt->execute([$shop_id]);
$completed_orders = $orders_stmt->fetchAll();

$inspection_steps = [
    [
        'title' => 'Pre-stitch verification',
        'detail' => 'Confirm thread colors, design placement, and fabric specs match the approved proof.',
        'icon' => 'fas fa-file-circle-check',
    ],
    [
        'title' => 'In-line sampling',
        'detail' => 'Inspect first-off samples for tension, density, and registration before full runs.',
        'icon' => 'fas fa-magnifying-glass',
    ],
    [
        'title' => 'Finish & trim audit',
        'detail' => 'Check trims, backing, and edge finishes to ensure clean presentation.',
        'icon' => 'fas fa-scissors',
    ],
    [
        'title' => 'Final packaging review',
        'detail' => 'Verify count accuracy, labeling, and protective packaging before dispatch.',
        'icon' => 'fas fa-box-open',
    ],
];

$quality_metrics = [
    [
        'label' => 'Defect rate',
        'value' => '1.4%',
        'note' => 'Rolling 30-day production average.',
        'icon' => 'fas fa-chart-line',
        'tone' => 'success',
    ],
    [
        'label' => 'Rework queue',
        'value' => '3 jobs',
        'note' => 'Awaiting correction before shipment.',
        'icon' => 'fas fa-rotate-right',
        'tone' => 'warning',
    ],
    [
        'label' => 'QC pass rate',
        'value' => '98.6%',
        'note' => 'First-pass approvals today.',
        'icon' => 'fas fa-circle-check',
        'tone' => 'info',
    ],
];

$automation = [
    [
        'title' => 'Delivery lock until QC pass',
        'detail' => 'Jobs cannot be marked ready for pickup or delivery until QC approval is logged.',
        'icon' => 'fas fa-lock',
    ],
    [
        'title' => 'Auto-generated QC reports',
        'detail' => 'Inspection outcomes are summarized per batch for client visibility.',
        'icon' => 'fas fa-file-lines',
    ],
    [
        'title' => 'Exception alerts',
        'detail' => 'Supervisors are notified when defects exceed set thresholds.',
        'icon' => 'fas fa-bell',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quality Control Module - Owner</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .qc-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .overview-card {
            grid-column: span 12;
        }

        .qc-form-card {
            grid-column: span 12;
        }

        .inspection-card {
            grid-column: span 7;
        }

        .metrics-card {
            grid-column: span 5;
        }

        .automation-card {
            grid-column: span 12;
        }

        .inspection-item {
            display: flex;
            gap: 1rem;
            align-items: flex-start;
            padding: 1rem;
            border-radius: var(--radius);
            border: 1px solid var(--gray-200);
            background: var(--bg-primary);
        }

        .inspection-icon {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: var(--radius-full);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--primary-100);
            color: var(--primary-700);
            font-size: 1rem;
        }

        .inspection-list {
            display: grid;
            gap: 1rem;
        }

        .metric-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: white;
        }

        .metric-item i {
            color: var(--primary-600);
        }

        .metric-value {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0.25rem 0;
        }

        .automation-list {
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
    <?php include __DIR__ . "/includes/owner_navbar.php"; ?>

    <div class="container">
        <div class="dashboard-header fade-in">
            <div class="d-flex justify-between align-center">
                <div>
                    <h2>Quality Control</h2>
                    <p class="text-muted">Standardize inspections so every delivery meets client expectations.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-shield-check"></i> Module 15</span>
            </div>
        </div>

        <div class="qc-grid">
            <?php if ($success !== ''): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="card overview-card">
                <div class="card-header">
                    <h3><i class="fas fa-bullseye text-primary"></i> Purpose</h3>
                </div>
                <p class="text-muted mb-0">
                    Ensures output meets quality standards, locking delivery actions until inspection criteria are
                    satisfied and recorded.
                </p>
            </div>

            <div class="card qc-form-card">
                <div class="card-header">
                    <h3><i class="fas fa-square-check text-primary"></i> Record QC Result</h3>
                    <p class="text-muted">Passing QC auto-creates a finished goods record so fulfillment can start.</p>
                </div>
                <form method="POST" class="d-flex flex-column gap-3">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="record_qc_result">

                    <div>
                        <label for="order_id" class="form-label">Completed Order</label>
                        <select id="order_id" name="order_id" class="form-control" required>
                            <option value="">Select an order</option>
                            <?php foreach ($completed_orders as $order): ?>
                                <option value="<?php echo (int) $order['id']; ?>">
                                    #<?php echo htmlspecialchars($order['order_number']); ?> - <?php echo htmlspecialchars($order['client_name']); ?>
                                    <?php if (!empty($order['finished_goods_id'])): ?>
                                        (finished goods ready)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="qc_result" class="form-label">QC Decision</label>
                        <select id="qc_result" name="qc_result" class="form-control" required>
                            <option value="">Select result</option>
                            <option value="passed">Passed</option>
                            <option value="failed">Failed</option>
                        </select>
                    </div>

                    <div>
                        <label for="notes" class="form-label">QC Notes (optional)</label>
                        <textarea id="notes" name="notes" rows="3" class="form-control" placeholder="Defects found, corrections done, packaging remarks..."></textarea>
                    </div>

                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save QC Decision</button>
                </form>
            </div>

            <div class="card inspection-card">
                <div class="card-header">
                    <h3><i class="fas fa-clipboard-check text-primary"></i> Inspection Workflow</h3>
                    <p class="text-muted">Standardized checkpoints for every embroidery batch.</p>
                </div>
                <div class="inspection-list">
                    <?php foreach ($inspection_steps as $step): ?>
                        <div class="inspection-item">
                            <span class="inspection-icon"><i class="<?php echo $step['icon']; ?>"></i></span>
                            <div>
                                <strong><?php echo $step['title']; ?></strong>
                                <p class="text-muted mb-0"><?php echo $step['detail']; ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card metrics-card">
                <div class="card-header">
                    <h3><i class="fas fa-chart-pie text-primary"></i> Quality Metrics</h3>
                    <p class="text-muted">Live view of inspection performance.</p>
                </div>
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($quality_metrics as $metric): ?>
                        <div class="metric-item">
                            <div class="d-flex align-center gap-2">
                                <i class="<?php echo $metric['icon']; ?>"></i>
                                <strong><?php echo $metric['label']; ?></strong>
                            </div>
                            <div class="metric-value text-<?php echo $metric['tone']; ?>">
                                <?php echo $metric['value']; ?>
                            </div>
                            <p class="text-muted mb-0"><?php echo $metric['note']; ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card automation-card">
                <div class="card-header">
                    <h3><i class="fas fa-robot text-primary"></i> Automation</h3>
                    <p class="text-muted">Guardrails that keep quality approvals consistent.</p>
                </div>
                <div class="automation-list">
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
