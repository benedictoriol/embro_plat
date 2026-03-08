<?php
session_start();
require_once '../config/db.php';
require_once '../config/automation_helpers.php';
require_once '../config/constants.php';
require_role('client');

$client_id = $_SESSION['user']['id'];
$unread_notifications = fetch_unread_notification_count($pdo, $client_id);
$error = '';
$success = '';
$max_revision_count = 2;

$action = sanitize($_POST['action'] ?? '');
if(isset($_POST['approve_digitized_design']) && $action === '') {
    $action = 'approve_design';
}

if($action === 'approve_design') {
    $order_id = (int) ($_POST['order_id'] ?? 0);

    $approval_stmt = $pdo->prepare("\n        SELECT o.id, o.order_number, o.design_approved, o.shop_id, o.status AS order_status,
               o.assigned_to, s.owner_id,
               da.id AS approval_id, da.status AS approval_status
        FROM orders o
        JOIN shops s ON s.id = o.shop_id
        LEFT JOIN design_approvals da ON da.order_id = o.id
        WHERE o.id = ? AND o.client_id = ?
        LIMIT 1
    ");
    $approval_stmt->execute([$order_id, $client_id]);
    $approval = $approval_stmt->fetch();

    if(!$approval) {
        $error = 'Order not found.';
    } elseif((int) ($approval['design_approved'] ?? 0) === 1 || ($approval['approval_status'] ?? '') === 'approved') {
        $error = 'Digitized design is already approved.';
    } elseif(empty($approval['approval_id'])) {
        $error = 'No design approval record found for this order.';
    } else {
        $pdo->beginTransaction();
        try {
            $order_update = $pdo->prepare("UPDATE orders SET design_approved = 1, updated_at = NOW() WHERE id = ? AND client_id = ?");
            $order_update->execute([$order_id, $client_id]);

            if(!empty($approval['approval_id'])) {
                $approve_stmt = $pdo->prepare("UPDATE design_approvals SET status = 'approved', approved_at = NOW(), updated_at = NOW() WHERE id = ?");
                $approve_stmt->execute([(int) $approval['approval_id']]);
            }

            record_order_status_history(
                $pdo,
                $order_id,
                (string) ($approval['order_status'] ?? STATUS_ACCEPTED),
                get_order_progress_for_status((string) ($approval['order_status'] ?? STATUS_ACCEPTED)),
                'Client approved digitized design for production readiness.',
                $client_id
            );

            $owner_message = sprintf('Client approved the design proof for order #%s.', $approval['order_number']);
            if(!empty($approval['owner_id'])) {
                create_notification($pdo, (int) $approval['owner_id'], $order_id, 'design', $owner_message);
            }
            if(!empty($approval['assigned_to'])) {
                create_notification($pdo, (int) $approval['assigned_to'], $order_id, 'design', $owner_message);
            }

            automation_log_audit_if_available(
                $pdo,
                $client_id,
                $_SESSION['user']['role'] ?? null,
                'design_approved',
                'orders',
                $order_id,
                ['design_approved' => $approval['design_approved'] ?? null],
                ['design_approved' => 1]
            );

            $pdo->commit();
            $success = 'Digitized design approved. Production can proceed when the shop updates the order status.';
        } catch(Throwable $e) {
            if($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Unable to approve digitized design right now.';
        }
    }
    } elseif($action === 'request_revision') {
    $order_id = (int) ($_POST['order_id'] ?? 0);
    $notes = sanitize($_POST['revision_notes'] ?? '');

    $revision_stmt = $pdo->prepare("\n        SELECT o.id, o.order_number, o.design_approved, o.revision_count, o.assigned_to,
               s.owner_id, da.id AS approval_id
        FROM orders o
        JOIN shops s ON s.id = o.shop_id
        LEFT JOIN design_approvals da ON da.order_id = o.id
        WHERE o.id = ? AND o.client_id = ?
        LIMIT 1
    ");
    $revision_stmt->execute([$order_id, $client_id]);
    $order = $revision_stmt->fetch();

    if(!$order) {
        $error = 'Order not found.';
    } elseif($notes === '') {
        $error = 'Please add revision notes so the shop knows what to adjust.';
    } elseif((int) ($order['revision_count'] ?? 0) >= $max_revision_count) {
        $error = 'You have reached the maximum number of revision requests for this order.';
    } else {
        $pdo->beginTransaction();
        try {
            $update_order_stmt = $pdo->prepare("\n                UPDATE orders
                SET revision_count = revision_count + 1,
                    revision_notes = ?,
                    revision_requested_at = NOW(),
                    design_approved = 0,
                    updated_at = NOW()
                WHERE id = ? AND client_id = ?
            ");
            $update_order_stmt->execute([$notes, $order_id, $client_id]);

            if(!empty($order['approval_id'])) {
                $pending_status = order_workflow_design_pending_status($pdo);
                $update_approval_stmt = $pdo->prepare("\n                    UPDATE design_approvals
                    SET status = ?, approved_at = NULL, customer_notes = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $update_approval_stmt->execute([$pending_status, $notes, (int) $order['approval_id']]);
            }

            $message = sprintf('Client requested a design revision for order #%s.', $order['order_number']);
            if(!empty($order['owner_id'])) {
                create_notification($pdo, (int) $order['owner_id'], $order_id, 'design', $message);
            }
            if(!empty($order['assigned_to'])) {
                create_notification($pdo, (int) $order['assigned_to'], $order_id, 'design', $message);
            }

            automation_log_audit_if_available(
                $pdo,
                $client_id,
                $_SESSION['user']['role'] ?? null,
                'design_revision_requested',
                'orders',
                $order_id,
                [
                    'design_approved' => $order['design_approved'] ?? null,
                    'revision_count' => $order['revision_count'] ?? null,
                ],
                [
                    'design_approved' => 0,
                    'revision_notes' => $notes,
                    'revision_count' => ((int) ($order['revision_count'] ?? 0)) + 1,
                ]
            );

            $pdo->commit();
            $success = 'Revision request sent to the shop.';
        } catch(Throwable $e) {
            if($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Unable to submit your revision request right now.';
        }
    }
}

$coreFlow = [
    [
        'title' => 'Client order placed',
        'detail' => 'Order details, artwork, and requirements are submitted to the shop.',
    ],
    [
        'title' => 'Review & confirmation',
        'detail' => 'Shop reviews specs, confirms pricing, and validates production readiness.',
    ],
    [
        'title' => 'Digitizing',
        'detail' => 'Design is converted into stitch-ready format and shared for your approval.',
    ],
    [
        'title' => 'Production in progress',
        'detail' => 'Jobs are scheduled, stitched, and quality-checked before packaging.',
    ],
    [
        'title' => 'Completion & delivery',
        'detail' => 'Finished orders are marked complete and handed off for pickup or delivery.',
    ],
];

$automation = [
    [
        'title' => 'Status progression updates',
        'detail' => 'Automatic notifications keep clients aware of every stage shift.',
        'icon' => 'fas fa-signal',
    ],
    [
        'title' => 'Stall alerts',
        'detail' => 'Escalations trigger when orders linger too long in a step.',
        'icon' => 'fas fa-triangle-exclamation',
    ],
];

$digitized_stmt = $pdo->prepare("\n    SELECT\n        o.id AS order_id, o.order_number, o.status AS order_status, o.design_approved,\n        s.shop_name,\n        dd.stitch_file_path, dd.stitch_count, dd.thread_colors, dd.estimated_thread_length,\n        dd.width_px, dd.height_px, dd.detected_width_mm, dd.detected_height_mm, dd.suggested_width_mm, dd.suggested_height_mm, dd.scale_ratio,\n        dd.created_at AS digitized_at, dd.approved_at AS digitized_approved_at,\n        da.status AS approval_status, da.approved_at AS approval_at\n    FROM orders o\n    JOIN shops s ON s.id = o.shop_id\n    LEFT JOIN digitized_designs dd ON dd.order_id = o.id\n    LEFT JOIN design_approvals da ON da.order_id = o.id\n    WHERE o.client_id = ?\n      AND o.status IN ('accepted', 'digitizing', 'in_progress')\n      AND (dd.id IS NOT NULL OR da.id IS NOT NULL)\n    ORDER BY o.updated_at DESC\n");
$digitized_stmt->execute([$client_id]);
$digitized_orders = $digitized_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Management Module - Client</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php require_once __DIR__ . '/includes/customer_navbar.php'; ?>

    <div class="container">
        <div class="dashboard-header fade-in">
            <div class="d-flex justify-between align-center">
                <div>
                    <h2>Order Management</h2>
                    <p class="text-muted">Track every order from placement to delivery-ready completion.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-clipboard-list"></i> Module 10</span>
            </div>
        </div>

        <?php if($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if($success !== ''): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

            <div class="card mb-4">
            <div class="card-header">
                <h3><i class="fas fa-vector-square text-primary"></i> Digitized Design Review</h3>
                <p class="text-muted">Approve stitch-ready design output before production starts.</p>
            </div>
<?php if(empty($digitized_orders)): ?>
                <p class="text-muted mb-0">No orders are currently awaiting digitized design review.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr><th>Order</th><th>Shop</th><th>Status</th><th>Digitized info</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach($digitized_orders as $order): ?>
                                <?php $approved = ((int) ($order['design_approved'] ?? 0) === 1) || (($order['approval_status'] ?? '') === 'approved'); ?>
                                <tr>
                                    <td>#<?php echo htmlspecialchars($order['order_number']); ?></td>
                                    <td><?php echo htmlspecialchars($order['shop_name']); ?></td>
                                    <td><span class="badge badge-info"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string) $order['order_status']))); ?></span></td>
                                    <td>
                                        <small class="text-muted d-block">Stitches: <?php echo (int) ($order['stitch_count'] ?? 0); ?></small>
                                        <small class="text-muted d-block">Thread colors: <?php echo (int) ($order['thread_colors'] ?? 0); ?></small>
                                        <small class="text-muted d-block">Size(px): <?php echo (int) ($order['width_px'] ?? 0); ?> × <?php echo (int) ($order['height_px'] ?? 0); ?></small>
                                        <small class="text-muted d-block">Detected(mm): <?php echo htmlspecialchars((string) ($order['detected_width_mm'] ?? '-')); ?> × <?php echo htmlspecialchars((string) ($order['detected_height_mm'] ?? '-')); ?></small>
                                        <small class="text-muted d-block">Suggested(mm): <?php echo htmlspecialchars((string) ($order['suggested_width_mm'] ?? '-')); ?> × <?php echo htmlspecialchars((string) ($order['suggested_height_mm'] ?? '-')); ?></small>
                                        <?php if(!empty($order['stitch_file_path'])): ?>
                                            <a href="../<?php echo ltrim((string) $order['stitch_file_path'], '/'); ?>" target="_blank" rel="noopener">View stitch file</a>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if($approved): ?>
                                            <span class="badge badge-success">Approved</span>
                                        <?php else: ?>
                                            <form method="POST">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="approve_design">
                                                <input type="hidden" name="order_id" value="<?php echo (int) $order['order_id']; ?>">
                                                <button type="submit" name="approve_digitized_design" class="btn btn-sm btn-primary">Approve design</button>
                                            </form>
                                            <form method="POST" class="mt-2">
                                                <?php echo csrf_field(); ?>
                                                <input type="hidden" name="action" value="request_revision">
                                                <input type="hidden" name="order_id" value="<?php echo (int) $order['order_id']; ?>">
                                                <textarea name="revision_notes" class="form-control" rows="2" placeholder="Request changes to this proof..." required></textarea>
                                                <button type="submit" class="btn btn-sm btn-warning mt-2">Request revision</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header"><h3><i class="fas fa-route text-primary"></i> Core Flow</h3></div>
            <ul>
                <?php foreach ($coreFlow as $step): ?>
                    <li><strong><?php echo $step['title']; ?>:</strong> <?php echo $step['detail']; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</body>
</html>
