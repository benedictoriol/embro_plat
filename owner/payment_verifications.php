<?php
session_start();
require_once '../config/db.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$shop_stmt = $pdo->prepare("SELECT * FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch();

if(!$shop) {
    header("Location: create_shop.php");
    exit();
}

$success = null;
$error = null;

if(isset($_POST['action'], $_POST['payment_id'])) {
    $payment_id = (int) $_POST['payment_id'];
    $action = $_POST['action'];

    if(!in_array($action, ['verify', 'reject'], true)) {
        $error = 'Invalid payment action.';
    } else {
        $payment_stmt = $pdo->prepare("
            SELECT p.*, o.order_number, o.client_id, o.id as order_id
            FROM payments p
            JOIN orders o ON p.order_id = o.id
            WHERE p.id = ? AND o.shop_id = ?
        ");
        $payment_stmt->execute([$payment_id, $shop['id']]);
        $payment = $payment_stmt->fetch();

        if(!$payment) {
            $error = 'Payment record not found.';
        } else {
            $new_status = $action === 'verify' ? 'verified' : 'rejected';
            $order_payment_status = $action === 'verify' ? 'paid' : 'rejected';
            $verified_at = $action === 'verify' ? date('Y-m-d H:i:s') : null;

            $update_stmt = $pdo->prepare("
                UPDATE payments
                SET status = ?, verified_by = ?, verified_at = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $update_stmt->execute([$new_status, $owner_id, $verified_at, $payment_id]);

            $order_update_stmt = $pdo->prepare("
                UPDATE orders
                SET payment_status = ?, payment_verified_at = ?
                WHERE id = ?
            ");
            $order_update_stmt->execute([$order_payment_status, $verified_at, $payment['order_id']]);

            $message = $action === 'verify'
                ? sprintf('Payment verified for order #%s.', $payment['order_number'])
                : sprintf('Payment proof rejected for order #%s. Please resubmit.', $payment['order_number']);
            create_notification($pdo, (int) $payment['client_id'], (int) $payment['order_id'], 'payment', $message);

            $success = $action === 'verify'
                ? 'Payment verified successfully.'
                : 'Payment rejected. Client notified to resubmit proof.';
        }
    }
}

$allowed_filters = ['pending', 'verified', 'rejected'];
$filter = $_GET['filter'] ?? 'all';

$query = "
    SELECT p.*, o.order_number, o.price, u.fullname as client_name
    FROM payments p
    JOIN orders o ON p.order_id = o.id
    JOIN users u ON o.client_id = u.id
    WHERE o.shop_id = ?
";
$params = [$shop['id']];

if(in_array($filter, $allowed_filters, true)) {
    $query .= " AND p.status = ?";
    $params[] = $filter;
}

$query .= " ORDER BY p.created_at DESC";

$payments_stmt = $pdo->prepare($query);
$payments_stmt->execute($params);
$payments = $payments_stmt->fetchAll();

function payment_badge($status) {
    $map = [
        'pending' => 'payment-pending',
        'verified' => 'payment-paid',
        'rejected' => 'payment-rejected'
    ];
    $class = $map[$status] ?? 'payment-pending';
    return '<span class="status-pill ' . $class . '">' . ucfirst($status) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Verifications - <?php echo htmlspecialchars($shop['shop_name']); ?></title>
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
        .payment-pending { background: #e0f2fe; color: #0369a1; }
        .payment-paid { background: #dcfce7; color: #166534; }
        .payment-rejected { background: #fee2e2; color: #991b1b; }
        .table td form {
            display: inline-block;
        }
        .table td form + form {
            margin-left: 8px;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-receipt"></i> <?php echo htmlspecialchars($shop['shop_name']); ?>
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <li><a href="shop_profile.php" class="nav-link">Shop Profile</a></li>
                <li><a href="manage_staff.php" class="nav-link">Staff</a></li>
                <li><a href="shop_orders.php" class="nav-link">Orders</a></li>
                <li><a href="payment_verifications.php" class="nav-link active">Payments</a></li>
                <li><a href="earnings.php" class="nav-link">Earnings</a></li>
                <li><a href="../auth/logout.php" class="nav-link">Logout</a></li>
            </ul>
        </div>
    </nav>

    <div class="container">
        <div class="dashboard-header">
            <h2>Payment Verifications</h2>
            <p class="text-muted">Review client payment proofs and confirm receipts.</p>
        </div>

        <?php if($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <div class="filter-tabs">
            <a href="payment_verifications.php" class="<?php echo $filter === 'all' ? 'active' : ''; ?>">All</a>
            <a href="payment_verifications.php?filter=pending" class="<?php echo $filter === 'pending' ? 'active' : ''; ?>">Pending</a>
            <a href="payment_verifications.php?filter=verified" class="<?php echo $filter === 'verified' ? 'active' : ''; ?>">Verified</a>
            <a href="payment_verifications.php?filter=rejected" class="<?php echo $filter === 'rejected' ? 'active' : ''; ?>">Rejected</a>
        </div>

        <div class="card">
            <h3>Payments (<?php echo count($payments); ?>)</h3>
            <?php if(!empty($payments)): ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Client</th>
                            <th>Amount</th>
                            <th>Proof</th>
                            <th>Status</th>
                            <th>Submitted</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($payments as $payment): ?>
                            <tr>
                                <td>#<?php echo htmlspecialchars($payment['order_number']); ?></td>
                                <td><?php echo htmlspecialchars($payment['client_name']); ?></td>
                                <td>₱<?php echo number_format($payment['amount'] ?? $payment['price'] ?? 0, 2); ?></td>
                                <td>
                                    <a href="../assets/uploads/payments/<?php echo htmlspecialchars($payment['proof_file']); ?>" target="_blank">
                                        View proof
                                    </a>
                                </td>
                                <td><?php echo payment_badge($payment['status']); ?></td>
                                <td><?php echo date('M d, Y', strtotime($payment['created_at'])); ?></td>
                                <td>
                                    <?php if($payment['status'] === 'pending'): ?>
                                        <form method="POST">
                                            <input type="hidden" name="payment_id" value="<?php echo $payment['id']; ?>">
                                            <input type="hidden" name="action" value="verify">
                                            <button type="submit" class="btn btn-success btn-sm">
                                                <i class="fas fa-check"></i> Verify
                                            </button>
                                        </form>
                                        <form method="POST">
                                            <input type="hidden" name="payment_id" value="<?php echo $payment['id']; ?>">
                                            <input type="hidden" name="action" value="reject">
                                            <button type="submit" class="btn btn-danger btn-sm">
                                                <i class="fas fa-times"></i> Reject
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted">No actions</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="text-center p-4">
                    <i class="fas fa-receipt fa-3x text-muted mb-3"></i>
                    <h4>No Payments Found</h4>
                    <p class="text-muted">Payment proofs will appear here once clients submit them.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
