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

$shop_id = $shop['id'];
$allowed_filters = ['pending', 'accepted', 'in_progress', 'completed', 'return', 'cancelled'];
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

if(isset($_POST['set_price'])) {
    $order_id = (int) ($_POST['order_id'] ?? 0);
    $price_input = $_POST['price'] ?? '';
    $price_value = filter_var($price_input, FILTER_VALIDATE_FLOAT);

    $order_stmt = $pdo->prepare("SELECT id, status, client_id, order_number, price, payment_status FROM orders WHERE id = ? AND shop_id = ?");
    $order_stmt->execute([$order_id, $shop_id]);
    $order = $order_stmt->fetch();

    if(!$order) {
        $error = "Order not found for this shop.";
    } elseif($order['status'] !== 'pending') {
        $error = "Prices can only be set for pending orders.";
    } elseif($price_value === false || $price_value <= 0) {
        $error = "Please enter a valid price greater than zero.";
    } else {
        $update_stmt = $pdo->prepare("UPDATE orders SET price = ?, updated_at = NOW() WHERE id = ? AND shop_id = ?");
        $update_stmt->execute([$price_value, $order_id, $shop_id]);
        $success = "Price sent to the client for approval.";

        $invoice_status = determine_invoice_status($order['status'], $order['payment_status'] ?? 'unpaid');
        ensure_order_invoice($pdo, $order_id, $order['order_number'], (float) $price_value, $invoice_status);

        create_notification(
            $pdo,
            (int) $order['client_id'],
            $order_id,
            'info',
            'A price of ₱' . number_format($price_value, 2) . ' has been set for order #' . $order['order_number'] . '. Please review and respond.'
        );

        log_audit(
            $pdo,
            $owner_id,
            $_SESSION['user']['role'] ?? null,
            'set_order_price',
            'orders',
            $order_id,
            ['price' => $order['price'] ?? null],
            ['price' => $price_value]
        );
    }
}



$query = "
    SELECT o.*, u.fullname as client_name, au.fullname as assigned_name 
    FROM orders o 
    JOIN users u ON o.client_id = u.id 
    LEFT JOIN users au ON o.assigned_to = au.id
    WHERE o.shop_id = ?
    AND (
            JSON_EXTRACT(o.quote_details, '$.requested_from_services') IS NULL
            OR JSON_EXTRACT(o.quote_details, '$.owner_request_status') = 'accepted'
          )
";
$params = [$shop_id];

if(in_array($filter, $allowed_filters, true)) {
    $query .= " AND o.status = ?";
    $params[] = $filter;
}

$query .= " ORDER BY o.created_at DESC";

$orders_stmt = $pdo->prepare($query);
$orders_stmt->execute($params);
$orders = $orders_stmt->fetchAll();

function format_quote_details(?array $quote_details): array {
    if (!$quote_details) {
        return ['summary' => 'No quote details provided.', 'estimate' => null];
    }
    $complexity = $quote_details['complexity'] ?? 'Standard';
    $add_ons = $quote_details['add_ons'] ?? [];
    $rush = !empty($quote_details['rush']);
    $estimated_total = $quote_details['estimated_total'] ?? null;
    $add_on_label = !empty($add_ons) ? implode(', ', $add_ons) : 'None';
    $summary = "Complexity: {$complexity} • Add-ons: {$add_on_label} • Rush: " . ($rush ? 'Yes' : 'No');

    return ['summary' => $summary, 'estimate' => $estimated_total];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shop Orders - <?php echo htmlspecialchars($shop['shop_name']); ?></title>
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
        .status-accepted { background: #ede9fe; color: #5b21b6; }
        .status-in_progress { background: #e0f2fe; color: #0369a1; }
        .status-completed { background: #dcfce7; color: #166534; }
        .status-return { background: #ffedd5; color: #9a3412; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        .payment-unpaid { background: #fef3c7; color: #92400e; }
        .payment-pending { background: #e0f2fe; color: #0369a1; }
        .payment-paid { background: #dcfce7; color: #166534; }
        .payment-rejected { background: #fee2e2; color: #991b1b; }
        .payment-refund_pending { background: #fef9c3; color: #92400e; }
        .payment-refunded { background: #e2e8f0; color: #475569; }
    
    </style>
</head>
<body>
    <?php include __DIR__ . "/includes/owner_navbar.php"; ?>

    <div class="container">
        <div class="dashboard-header">
            <h2>Shop Orders</h2>
            <p class="text-muted">Review and track all orders submitted to your shop.</p>
        </div>

        <?php if(isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if(isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>


        <div class="filter-tabs">
            <a href="shop_orders.php" class="<?php echo $filter === 'all' ? 'active' : ''; ?>">All</a>
            <a href="shop_orders.php?filter=pending" class="<?php echo $filter === 'pending' ? 'active' : ''; ?>">Pending</a>
            <a href="shop_orders.php?filter=accepted" class="<?php echo $filter === 'accepted' ? 'active' : ''; ?>">Accepted</a>
            <a href="shop_orders.php?filter=in_progress" class="<?php echo $filter === 'in_progress' ? 'active' : ''; ?>">In Progress</a>
            <a href="shop_orders.php?filter=completed" class="<?php echo $filter === 'completed' ? 'active' : ''; ?>">Completed</a>
            <a href="shop_orders.php?filter=return" class="<?php echo $filter === 'return' ? 'active' : ''; ?>">Return</a>
            <a href="shop_orders.php?filter=cancelled" class="<?php echo $filter === 'cancelled' ? 'active' : ''; ?>">Cancelled</a>
        </div>

        <div class="card">
            <h3>Orders (<?php echo count($orders); ?>)</h3>
            <?php if(!empty($orders)): ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Order #</th>
                            <th>Client</th>
                            <th>Service</th>
                            <th>Price</th>
                            <th>Status</th>
                            <th>Payment</th>
                            <th>Assigned To</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($orders as $order): ?>
                            <?php
                                $quote_details = !empty($order['quote_details']) ? json_decode($order['quote_details'], true) : null;
                                $quote_summary = format_quote_details($quote_details);
                            ?>
                            <tr>
                                <td>#<?php echo htmlspecialchars($order['order_number']); ?></td>
                                <td><?php echo htmlspecialchars($order['client_name']); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($order['service_type']); ?>
                                    <div class="text-muted small mt-1"><?php echo htmlspecialchars($quote_summary['summary']); ?></div>
                                    <?php if($quote_summary['estimate'] !== null): ?>
                                        <div class="text-muted small">Estimated total: ₱<?php echo number_format((float) $quote_summary['estimate'], 2); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                     <?php if($order['price'] !== null): ?>
                                        ₱<?php echo number_format($order['price'], 2); ?>
                                        <?php if($order['status'] === 'pending'): ?>
                                            <div class="text-muted small">Awaiting client approval</div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                         <span class="text-muted">Not set</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-pill status-<?php echo htmlspecialchars($order['status']); ?>">
                                        <?php echo str_replace('_', ' ', ucfirst($order['status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                        $payment_status = $order['payment_status'] ?? 'unpaid';
                                        $payment_class = 'payment-' . $payment_status;
                                        $payment_hold = payment_hold_status($order['status'] ?? STATUS_PENDING, $payment_status);
                                    ?>
                                    <span class="status-pill <?php echo htmlspecialchars($payment_class); ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $payment_status)); ?>
                                    </span>
                                    <div class="mt-1">
                                        <span class="hold-pill <?php echo htmlspecialchars($payment_hold['class']); ?>">
                                            Hold: <?php echo htmlspecialchars($payment_hold['label']); ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <?php if($order['assigned_name']): ?>
                                        <?php echo htmlspecialchars($order['assigned_name']); ?>
                                    <?php else: ?>
                                        <span class="text-muted">Unassigned</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($order['created_at'])); ?></td>
                                <td>
                                    <a href="view_order.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-outline-primary mb-2">
                                        View
                                    </a>
                                    
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="text-center p-4">
                    <i class="fas fa-receipt fa-3x text-muted mb-3"></i>
                    <h4>No Orders Found</h4>
                    <p class="text-muted">Orders matching this filter will appear here.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
