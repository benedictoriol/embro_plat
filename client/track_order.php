<?php
session_start();
require_once '../config/db.php';
require_role('client');

$client_id = $_SESSION['user']['id'];
$success = null;
$error = null;

$payment_upload_dir = '../assets/uploads/payments/';

if(isset($_POST['submit_payment'])) {
    $order_id = (int) ($_POST['order_id'] ?? 0);
    $proof_file = $_FILES['payment_proof'] ?? null;

    $order_stmt = $pdo->prepare("
        SELECT o.id, o.order_number, o.price, o.payment_status, o.status, o.shop_id, s.shop_name, s.owner_id
        FROM orders o
        JOIN shops s ON o.shop_id = s.id
        WHERE o.id = ? AND o.client_id = ?
    ");
    $order_stmt->execute([$order_id, $client_id]);
    $order = $order_stmt->fetch();

    if(!$order) {
        $error = 'Unable to find the order for payment submission.';
    } elseif (in_array($order['status'], ['pending', 'cancelled'], true)) {
        $error = 'Payments can only be submitted for accepted or in-progress orders.';
    } else {
        $latest_payment_stmt = $pdo->prepare("
            SELECT status FROM payments WHERE order_id = ? ORDER BY created_at DESC LIMIT 1
        ");
        $latest_payment_stmt->execute([$order_id]);
        $latest_payment = $latest_payment_stmt->fetch();
        $latest_status = $latest_payment['status'] ?? null;

        if($order['payment_status'] === 'paid' || $latest_status === 'verified') {
            $error = 'This order has already been marked as paid.';
        } elseif ($latest_status === 'pending') {
            $error = 'A payment proof is already pending verification.';
        } elseif (!$proof_file || $proof_file['error'] !== UPLOAD_ERR_OK) {
            $error = 'Please upload a valid payment proof file.';
        } else {
            $allowed_ext = ['jpg', 'jpeg', 'png', 'pdf'];
            $file_ext = strtolower(pathinfo($proof_file['name'], PATHINFO_EXTENSION));
            $file_size = (int) $proof_file['size'];

            if(!in_array($file_ext, $allowed_ext, true)) {
                $error = 'Payment proofs must be JPG, PNG, or PDF files.';
            } elseif ($file_size > 5 * 1024 * 1024) {
                $error = 'Payment proof files must be smaller than 5MB.';
            } else {
                if(!is_dir($payment_upload_dir)) {
                    mkdir($payment_upload_dir, 0755, true);
                }

                $filename = 'payment_' . $order_id . '_' . uniqid('proof_', true) . '.' . $file_ext;
                $destination = $payment_upload_dir . $filename;

                if(move_uploaded_file($proof_file['tmp_name'], $destination)) {
                    $payment_stmt = $pdo->prepare("
                        INSERT INTO payments (order_id, client_id, shop_id, amount, proof_file, status)
                        VALUES (?, ?, ?, ?, ?, 'pending')
                    ");
                    $payment_stmt->execute([
                        $order_id,
                        $client_id,
                        $order['shop_id'],
                        $order['price'],
                        $filename
                    ]);

                    $order_update_stmt = $pdo->prepare("
                        UPDATE orders SET payment_status = 'pending' WHERE id = ? AND client_id = ?
                    ");
                    $order_update_stmt->execute([$order_id, $client_id]);

                    $message = sprintf(
                        'New payment proof submitted for order #%s (%s).',
                        $order['order_number'],
                        $order['shop_name']
                    );
                    create_notification($pdo, (int) $order['owner_id'], $order_id, 'payment', $message);

                    $success = 'Payment proof submitted successfully. Awaiting verification.';
                } else {
                    $error = 'Unable to upload payment proof. Please try again.';
                }
            }
        }
    }
}
$unread_notifications = fetch_unread_notification_count($pdo, $client_id);
$error = '';
$success = '';
$max_cancel_progress = 20;
$max_revision_count = 2;
$allowed_filters = ['pending', 'accepted', 'in_progress', 'completed', 'cancelled'];
$filter = $_GET['filter'] ?? 'all';

$action = $_POST['action'] ?? '';
if($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
    $order_id = (int) ($_POST['order_id'] ?? 0);
    $order_stmt = $pdo->prepare("
        SELECT o.id,
            o.status,
            o.progress,
            o.design_file,
            o.design_approved,
            o.order_number,
            o.revision_count,
            o.price,
            s.owner_id,
            s.shop_name
        FROM orders o
        JOIN shops s ON o.shop_id = s.id
        WHERE o.id = ? AND o.client_id = ?
    ");
    $order_stmt->execute([$order_id, $client_id]);
    $order = $order_stmt->fetch();

    if(!$order) {
        $error = 'Unable to locate the order for this action.';
    } elseif($action === 'cancel_order') {
        $reason = sanitize($_POST['cancellation_reason'] ?? '');
        if(!in_array($order['status'], ['pending', 'accepted'], true) || (int) $order['progress'] > $max_cancel_progress) {
            $error = 'This order can no longer be cancelled.';
        } else {
            $cancel_stmt = $pdo->prepare("
                UPDATE orders
                SET status = 'cancelled', cancellation_reason = ?, cancelled_at = NOW(), updated_at = NOW()
                WHERE id = ? AND client_id = ?
            ");
            $cancel_stmt->execute([$reason, $order_id, $client_id]);
            $success = 'Your order has been cancelled.';
            create_notification(
                $pdo,
                $client_id,
                $order_id,
                'warning',
                'Order #' . $order['order_number'] . ' was cancelled per your request.'
            );
        }
    } elseif($action === 'approve_design') {
        if(!in_array($order['status'], ['accepted', 'in_progress'], true)) {
            $error = 'Design approval is only available once the shop accepts the order.';
        } elseif(empty($order['design_file'])) {
            $error = 'There is no design file to approve yet.';
        } elseif((int) $order['design_approved'] === 1) {
            $error = 'This design has already been approved.';
        } else {
            $approve_stmt = $pdo->prepare("
                UPDATE orders
                SET design_approved = 1, updated_at = NOW()
                WHERE id = ? AND client_id = ?
            ");
            $approve_stmt->execute([$order_id, $client_id]);
            $success = 'Design approved. Production can begin once the shop starts work.';
        }
    } elseif($action === 'request_revision') {
        $notes = sanitize($_POST['revision_notes'] ?? '');
        if($notes === '') {
            $error = 'Please add revision notes so the shop knows what to adjust.';
        } elseif(empty($order['design_file'])) {
            $error = 'Revision requests require a shared design file.';
        } elseif(!in_array($order['status'], ['accepted', 'in_progress'], true)) {
            $error = 'Revisions are only allowed while an order is accepted or in progress.';
        } elseif((int) $order['revision_count'] >= $max_revision_count) {
            $error = 'You have reached the maximum number of revision requests for this order.';
        } else {
            $revision_stmt = $pdo->prepare("
                UPDATE orders
                SET revision_count = revision_count + 1,
                    revision_notes = ?,
                    revision_requested_at = NOW(),
                    design_approved = 0,
                    updated_at = NOW()
                WHERE id = ? AND client_id = ?
            ");
            $revision_stmt->execute([$notes, $order_id, $client_id]);
            $success = 'Revision request sent to the shop.';
        }
        } elseif($action === 'accept_price') {
        if($order['status'] !== 'pending') {
            $error = 'Price acceptance is only available for pending orders.';
        } elseif($order['price'] === null) {
            $error = 'No price quote is available to accept yet.';
        } else {
            $accept_stmt = $pdo->prepare("
                UPDATE orders
                SET status = 'accepted', updated_at = NOW()
                WHERE id = ? AND client_id = ?
            ");
            $accept_stmt->execute([$order_id, $client_id]);
            $success = 'Price accepted. Your order is now scheduled for production.';

            create_notification(
                $pdo,
                (int) $order['owner_id'],
                $order_id,
                'order_status',
                'Client accepted the price for order #' . $order['order_number'] . ' (' . $order['shop_name'] . ').'
            );
        }
    } elseif($action === 'reject_price') {
        if($order['status'] !== 'pending') {
            $error = 'Price rejection is only available for pending orders.';
        } elseif($order['price'] === null) {
            $error = 'No price quote is available to reject yet.';
        } else {
            $reject_stmt = $pdo->prepare("
                UPDATE orders
                SET price = NULL, updated_at = NOW()
                WHERE id = ? AND client_id = ?
            ");
            $reject_stmt->execute([$order_id, $client_id]);
            $success = 'Price rejected. The shop will send an updated quote.';

            create_notification(
                $pdo,
                (int) $order['owner_id'],
                $order_id,
                'warning',
                'Client rejected the price for order #' . $order['order_number'] . ' (' . $order['shop_name'] . '). Please send a new quote.'
            );
        }
    }
}

$query = "
    SELECT o.*, s.shop_name, s.logo
    FROM orders o
    JOIN shops s ON o.shop_id = s.id
    WHERE o.client_id = ?
";
$params = [$client_id];

if(in_array($filter, $allowed_filters, true)) {
    $query .= " AND o.status = ?";
    $params[] = $filter;
}

$query .= " ORDER BY o.created_at DESC";

$orders_stmt = $pdo->prepare($query);
$orders_stmt->execute($params);
$orders = $orders_stmt->fetchAll();

$order_photos = [];
$payment_by_order = [];
if(!empty($orders)) {
    $order_ids = array_column($orders, 'id');
    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $photos_stmt = $pdo->prepare("
        SELECT * FROM order_photos
        WHERE order_id IN ($placeholders)
        ORDER BY uploaded_at DESC
    ");
    $photos_stmt->execute($order_ids);
    $photos = $photos_stmt->fetchAll();

    foreach($photos as $photo) {
        $order_photos[$photo['order_id']][] = $photo;
    }
    
    $payments_stmt = $pdo->prepare("
        SELECT p.*
        FROM payments p
        WHERE p.order_id IN ($placeholders)
        ORDER BY p.created_at DESC
    ");
    $payments_stmt->execute($order_ids);
    $payments = $payments_stmt->fetchAll();

    foreach($payments as $payment) {
        if(!isset($payment_by_order[$payment['order_id']])) {
            $payment_by_order[$payment['order_id']] = $payment;
        }
    }
}

function status_pill($status) {
    $map = [
        'pending' => 'status-pending',
        'accepted' => 'status-accepted',
        'in_progress' => 'status-in_progress',
        'completed' => 'status-completed',
        'cancelled' => 'status-cancelled'
    ];
    $class = $map[$status] ?? 'status-pending';
    return '<span class="status-pill ' . $class . '">' . ucfirst(str_replace('_', ' ', $status)) . '</span>';
}

function payment_status_pill($status) {
    $map = [
        'unpaid' => 'payment-unpaid',
        'pending' => 'payment-pending',
        'paid' => 'payment-paid',
        'rejected' => 'payment-rejected'
    ];
    $class = $map[$status] ?? 'payment-unpaid';
    return '<span class="status-pill ' . $class . '">' . ucfirst($status) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Orders</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .filter-tabs {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .filter-tabs a {
            padding: 8px 16px;
            border-radius: 20px;
            background: #f1f5f9;
            color: #475569;
            text-decoration: none;
        }
        .filter-tabs a.active {
            background: #4f46e5;
            color: white;
        }
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            text-transform: capitalize;
        }
        .status-pending { background: #fef9c3; color: #92400e; }
        .status-accepted { background: #e0f2fe; color: #0369a1; }
        .status-in_progress { background: #e0e7ff; color: #3730a3; }
        .status-completed { background: #dcfce7; color: #166534; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        .payment-unpaid { background: #fef3c7; color: #92400e; }
        .payment-pending { background: #e0f2fe; color: #0369a1; }
        .payment-paid { background: #dcfce7; color: #166534; }
        .payment-rejected { background: #fee2e2; color: #991b1b; }
        .order-card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            background: #fff;
            margin-bottom: 16px;
        }
        .order-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
            margin-top: 12px;
            color: #64748b;
            font-size: 0.9rem;
        }
        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e2e8f0;
            border-radius: 6px;
            overflow: hidden;
            margin-top: 12px;
        }
        .progress-fill {
            height: 100%;
            background: #4f46e5;
        }
        .photo-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 10px;
            margin-top: 12px;
        }
        .photo-row img {
            width: 100%;
            height: 90px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }
        .payment-form {
            margin-top: 16px;
            border-top: 1px solid #e2e8f0;
            padding-top: 16px;
        }
        .payment-form input[type="file"] {
            display: block;
            width: 100%;
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
                    <a href="#" class="nav-link dropdown-toggle active">
                        <i class="fas fa-clipboard-list"></i> Orders
                    </a>
                    <div class="dropdown-menu">
                        <a href="place_order.php" class="dropdown-item"><i class="fas fa-plus-circle"></i> Place Order</a>
                        <a href="track_order.php" class="dropdown-item active"><i class="fas fa-route"></i> Track Orders</a>
                    </div>
                </li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle">
                        <i class="fas fa-layer-group"></i> Services
                    </a>
                    <div class="dropdown-menu">
                        <a href="customize_design.php" class="dropdown-item"><i class="fas fa-paint-brush"></i> Customize Design</a>
                        <a href="rate_provider.php" class="dropdown-item"><i class="fas fa-star"></i> Rate Provider</a>
                    </div>
                </li>
                <li><a href="notifications.php" class="nav-link">Notifications
                    <?php if($unread_notifications > 0): ?>
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
        <div class="dashboard-header">
            <h2>Track Your Orders</h2>
            <p class="text-muted">Stay updated on current progress, timelines, and shop updates.</p>
        </div>

        <?php if($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <?php if($error): ?>
            <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div>
        <?php endif; ?>
        <?php if($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
        <?php endif; ?>

        <div class="card mb-4">
            <h3>Cancellation & Revision Rules</h3>
            <ul class="text-muted mb-0">
                <li>Orders can be cancelled while they are pending or accepted and before progress exceeds <?php echo $max_cancel_progress; ?>%.</li>
                <li>Design approval is required before production starts. Approve shared designs or request changes.</li>
                <li>Each order includes up to <?php echo $max_revision_count; ?> design revision requests while the order is accepted or in progress.</li>
            </ul>
        </div>

        <div class="filter-tabs">
            <a href="track_order.php" class="<?php echo $filter === 'all' ? 'active' : ''; ?>">All</a>
            <a href="track_order.php?filter=pending" class="<?php echo $filter === 'pending' ? 'active' : ''; ?>">Pending</a>
            <a href="track_order.php?filter=accepted" class="<?php echo $filter === 'accepted' ? 'active' : ''; ?>">Accepted</a>
            <a href="track_order.php?filter=in_progress" class="<?php echo $filter === 'in_progress' ? 'active' : ''; ?>">In Progress</a>
            <a href="track_order.php?filter=completed" class="<?php echo $filter === 'completed' ? 'active' : ''; ?>">Completed</a>
            <a href="track_order.php?filter=cancelled" class="<?php echo $filter === 'cancelled' ? 'active' : ''; ?>">Cancelled</a>
        </div>

        <?php if(!empty($orders)): ?>
            <?php foreach($orders as $order): ?>
                <?php $quote_details = !empty($order['quote_details']) ? json_decode($order['quote_details'], true) : null; ?>
                <div class="order-card">
                    <div class="d-flex justify-between align-center">
                        <div>
                            <h4 class="mb-1"><?php echo htmlspecialchars($order['service_type']); ?></h4>
                            <p class="text-muted mb-0">
                                <i class="fas fa-store"></i> <?php echo htmlspecialchars($order['shop_name']); ?>
                            </p>
                        </div>
                        <div class="text-right">
                            <?php echo status_pill($order['status']); ?>
                            <div class="text-muted mt-2">#<?php echo htmlspecialchars($order['order_number']); ?></div>
                        </div>
                    </div>
                    <?php if($quote_details): ?>
                        <div class="mt-2 text-muted small">
                            <strong>Quote preferences:</strong>
                            Complexity <?php echo htmlspecialchars($quote_details['complexity'] ?? 'Standard'); ?> •
                            Add-ons <?php echo htmlspecialchars(!empty($quote_details['add_ons']) ? implode(', ', $quote_details['add_ons']) : 'None'); ?> •
                            Rush <?php echo !empty($quote_details['rush']) ? 'Yes' : 'No'; ?>
                            <?php if(isset($quote_details['estimated_total'])): ?>
                                • Est. total ₱<?php echo number_format((float) $quote_details['estimated_total'], 2); ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="order-meta">
                        <div><i class="fas fa-calendar"></i> Created: <?php echo date('M d, Y', strtotime($order['created_at'])); ?></div>
                        <div><i class="fas fa-box"></i> Quantity: <?php echo htmlspecialchars($order['quantity']); ?></div>
                        <?php if($order['price'] === null): ?>
                            <div><i class="fas fa-peso-sign"></i> Price: Awaiting quote</div>
                        <?php else: ?>
                            <div><i class="fas fa-peso-sign"></i> ₱<?php echo number_format($order['price'], 2); ?></div>
                        <?php endif; ?>
                        <?php if(!empty($order['design_file'])): ?>
                            <div><i class="fas fa-paperclip"></i>
                                <a href="../assets/uploads/designs/<?php echo htmlspecialchars($order['design_file']); ?>" target="_blank">View design file</a>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if($order['status'] === 'cancelled' && !empty($order['cancellation_reason'])): ?>
                        <div class="mt-2 text-muted">
                            <strong>Cancellation reason:</strong>
                            <div><?php echo nl2br(htmlspecialchars($order['cancellation_reason'])); ?></div>
                        </div>
                    <?php endif; ?>
                    <?php if($order['status'] === 'pending'): ?>
                        <div class="mt-3">
                            <strong>Price Quote</strong>
                            <?php if($order['price'] === null): ?>
                                <div class="text-muted small mt-2">Waiting for the shop to send a price quote.</div>
                            <?php else: ?>
                                <div class="text-muted small mt-2">
                                    The shop quoted ₱<?php echo number_format($order['price'], 2); ?>. Please accept or reject before production begins.
                                </div>
                                <div class="mt-2">
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="accept_price">
                                        <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                        <button type="submit" class="btn btn-success btn-sm">
                                            <i class="fas fa-check"></i> Accept Price
                                        </button>
                                    </form>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="reject_price">
                                        <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">
                                            <i class="fas fa-times"></i> Reject Price
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php
                        $payment = $payment_by_order[$order['id']] ?? null;
                        $payment_status = $order['payment_status'] ?? 'unpaid';
                        $latest_payment_status = $payment['status'] ?? null;
                        $can_submit_payment = in_array($order['status'], ['accepted', 'in_progress', 'completed'], true)
                            && $payment_status !== 'paid'
                            && $latest_payment_status !== 'pending';
                    ?>
                    <div class="mt-3">
                        <strong>Payment:</strong>
                        <?php echo payment_status_pill($payment_status); ?>
                        <?php if($latest_payment_status === 'pending'): ?>
                            <div class="text-muted small mt-2">Payment proof is pending verification.</div>
                        <?php elseif($latest_payment_status === 'rejected'): ?>
                            <div class="text-muted small mt-2">Payment proof was rejected. Please upload a new proof.</div>
                        <?php elseif($payment_status === 'paid'): ?>
                            <div class="text-muted small mt-2">Payment verified by the shop.</div>
                        <?php else: ?>
                            <div class="text-muted small mt-2">No payment proof submitted yet.</div>
                        <?php endif; ?>
                    </div>

                    <?php if($can_submit_payment): ?>
                        <form method="POST" enctype="multipart/form-data" class="payment-form">
                            <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                            <div class="form-group">
                                <label>Upload Payment Proof (JPG, PNG, PDF)</label>
                                <input type="file" name="payment_proof" class="form-control" required>
                            </div>
                            <button type="submit" name="submit_payment" class="btn btn-primary btn-sm">
                                <i class="fas fa-upload"></i> Submit Proof
                            </button>
                        </form>
                    <?php endif; ?>


                    <div class="mt-3">
                        <strong>Progress: <?php echo $order['progress']; ?>%</strong>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $order['progress']; ?>%;"></div>
                        </div>
                    </div>

                    <?php if(!empty($order['design_description'])): ?>
                        <p class="text-muted mt-3"><i class="fas fa-clipboard"></i> <?php echo htmlspecialchars($order['design_description']); ?></p>
                    <?php endif; ?>

                    <?php if(!empty($order['design_file'])): ?>
                        <div class="card mt-3" style="background: #f8fafc;">
                            <div class="d-flex justify-between align-center">
                                <div>
                                    <strong>Design Approval</strong>
                                    <p class="text-muted mb-0">Review the shared design before production starts.</p>
                                </div>
                                <div class="text-right">
                                    <?php if($order['design_approved']): ?>
                                        <span class="badge badge-success">Approved</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">Pending Approval</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if(!$order['design_approved']): ?>
                                <div class="mt-3">
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="approve_design">
                                        <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                        <button type="submit" class="btn btn-success btn-sm">
                                            <i class="fas fa-check"></i> Approve Design
                                        </button>
                                    </form>
                                    <form method="POST" class="mt-2">
                                        <input type="hidden" name="action" value="request_revision">
                                        <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                        <div class="form-group">
                                            <label class="text-muted">Request a revision (<?php echo (int) $order['revision_count']; ?>/<?php echo $max_revision_count; ?> used)</label>
                                            <textarea name="revision_notes" class="form-control" rows="2" placeholder="Share the updates you need..." required></textarea>
                                        </div>
                                        <button type="submit" class="btn btn-outline-primary btn-sm">
                                            <i class="fas fa-pen"></i> Request Revision
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if(in_array($order['status'], ['pending', 'accepted'], true) && (int) $order['progress'] <= $max_cancel_progress): ?>
                        <div class="card mt-3" style="background: #fef2f2;">
                            <div class="d-flex justify-between align-center">
                                <strong>Cancel Order</strong>
                                <span class="text-muted small">Allowed before work exceeds <?php echo $max_cancel_progress; ?>%.</span>
                            </div>
                            <form method="POST" class="mt-2">
                                <input type="hidden" name="action" value="cancel_order">
                                <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                <div class="form-group">
                                    <label class="text-muted">Cancellation reason (optional)</label>
                                    <textarea name="cancellation_reason" class="form-control" rows="2" placeholder="Let the shop know why you are cancelling..."></textarea>
                                </div>
                                <button type="submit" class="btn btn-danger btn-sm">
                                    <i class="fas fa-times"></i> Cancel Order
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>

                    <?php if(!empty($order_photos[$order['id']])): ?>
                        <div class="mt-3">
                            <strong>Latest Photos</strong>
                            <div class="photo-row">
                                <?php foreach(array_slice($order_photos[$order['id']], 0, 3) as $photo): ?>
                                    <img src="../assets/uploads/<?php echo htmlspecialchars($photo['photo_url']); ?>" alt="Order photo">
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="card">
                <div class="text-center p-4">
                    <i class="fas fa-route fa-3x text-muted mb-3"></i>
                    <h4>No Orders Found</h4>
                    <p class="text-muted">Orders matching this filter will appear here.</p>
                    <a href="place_order.php" class="btn btn-primary">Place an Order</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
