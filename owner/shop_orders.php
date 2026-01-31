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
$allowed_filters = ['pending', 'accepted', 'in_progress', 'completed'];
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

$active_staff_stmt = $pdo->prepare("
    SELECT se.user_id, u.fullname, se.position
    FROM shop_employees se
    JOIN users u ON se.user_id = u.id
    WHERE se.shop_id = ? AND se.status = 'active'
    ORDER BY u.fullname ASC
");
$active_staff_stmt->execute([$shop_id]);
$active_staff = $active_staff_stmt->fetchAll();

if(isset($_POST['set_price'])) {
    $order_id = (int) ($_POST['order_id'] ?? 0);
    $price_input = $_POST['price'] ?? '';
    $price_value = filter_var($price_input, FILTER_VALIDATE_FLOAT);

    $order_stmt = $pdo->prepare("SELECT id, status, client_id, order_number, price FROM orders WHERE id = ? AND shop_id = ?");
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

if(isset($_POST['assign_order'])) {
    $order_id = (int) $_POST['order_id'];
    $employee_id = (int) $_POST['employee_id'];

    $order_stmt = $pdo->prepare("SELECT id, status FROM orders WHERE id = ? AND shop_id = ?");
    $order_stmt->execute([$order_id, $shop_id]);
    $order = $order_stmt->fetch();

    if(!$order) {
        $error = "Order not found for this shop.";
    } elseif(in_array($order['status'], ['completed', 'cancelled'], true)) {
        $error = "Completed or cancelled orders cannot be reassigned.";
    } else {
        if($employee_id > 0) {
            $employee_stmt = $pdo->prepare("
                SELECT se.user_id 
                FROM shop_employees se 
                WHERE se.shop_id = ? AND se.user_id = ? AND se.status = 'active'
            ");
            $employee_stmt->execute([$shop_id, $employee_id]);
            $employee = $employee_stmt->fetch();

            if($employee) {
                $assign_stmt = $pdo->prepare("UPDATE orders SET assigned_to = ? WHERE id = ? AND shop_id = ?");
                $assign_stmt->execute([$employee_id, $order_id, $shop_id]);
                $success = "Order assignment updated.";
            } else {
                $error = "Selected employee is not active for this shop.";
            }
        } else {
            $assign_stmt = $pdo->prepare("UPDATE orders SET assigned_to = NULL WHERE id = ? AND shop_id = ?");
            $assign_stmt->execute([$order_id, $shop_id]);
            $success = "Order unassigned.";
        }
    }
}

$query = "
    SELECT o.*, u.fullname as client_name, au.fullname as assigned_name 
    FROM orders o 
    JOIN users u ON o.client_id = u.id 
    LEFT JOIN users au ON o.assigned_to = au.id
    WHERE o.shop_id = ?
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
        .payment-unpaid { background: #fef3c7; color: #92400e; }
        .payment-pending { background: #e0f2fe; color: #0369a1; }
        .payment-paid { background: #dcfce7; color: #166534; }
        .payment-rejected { background: #fee2e2; color: #991b1b; }
        .assignment-form {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .assignment-form select {
            min-width: 160px;
        }
        .price-form {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .price-form input {
            width: 120px;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-store"></i> <?php echo htmlspecialchars($shop['shop_name']); ?>
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <li><a href="shop_profile.php" class="nav-link">Shop Profile</a></li>
                <li><a href="manage_staff.php" class="nav-link">Staff</a></li>
                <li><a href="shop_orders.php" class="nav-link active">Orders</a></li>
                <li><a href="payment_verifications.php" class="nav-link">Payments</a></li>
                <li><a href="earnings.php" class="nav-link">Earnings</a></li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle">
                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['user']['fullname']); ?>
                    </a>
                    <div class="dropdown-menu">
                        <a href="profile.php" class="dropdown-item"><i class="fas fa-user-cog"></i> Profile</a>
                        <a href="../auth/logout.php" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </li>
            </ul>
        </div>
    </nav>

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

        <?php if(empty($active_staff)): ?>
            <div class="alert alert-warning">
                No active staff available. Add or reactivate employees to assign orders.
            </div>
        <?php endif; ?>

        <div class="filter-tabs">
            <a href="shop_orders.php" class="<?php echo $filter === 'all' ? 'active' : ''; ?>">All</a>
            <a href="shop_orders.php?filter=pending" class="<?php echo $filter === 'pending' ? 'active' : ''; ?>">Pending</a>
            <a href="shop_orders.php?filter=accepted" class="<?php echo $filter === 'accepted' ? 'active' : ''; ?>">Accepted</a>
            <a href="shop_orders.php?filter=in_progress" class="<?php echo $filter === 'in_progress' ? 'active' : ''; ?>">In Progress</a>
            <a href="shop_orders.php?filter=completed" class="<?php echo $filter === 'completed' ? 'active' : ''; ?>">Completed</a>
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
                            <tr>
                                <td>#<?php echo htmlspecialchars($order['order_number']); ?></td>
                                <td><?php echo htmlspecialchars($order['client_name']); ?></td>
                                <td><?php echo htmlspecialchars($order['service_type']); ?></td>
                                <td>
                                    <?php if($order['status'] === 'pending'): ?>
                                        <form method="POST" class="price-form">
                                            <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                            <input type="number" name="price" class="form-control" step="0.01" min="0" placeholder="Price"
                                                value="<?php echo $order['price'] !== null ? htmlspecialchars($order['price']) : ''; ?>" required>
                                            <button type="submit" name="set_price" class="btn btn-sm btn-outline-primary">
                                                <?php echo $order['price'] !== null ? 'Update' : 'Send'; ?>
                                            </button>
                                        </form>
                                        <?php if($order['price'] !== null): ?>
                                            <div class="text-muted small">Awaiting client approval</div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        ₱<?php echo number_format($order['price'] ?? 0, 2); ?>
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
                                    ?>
                                    <span class="status-pill <?php echo htmlspecialchars($payment_class); ?>">
                                        <?php echo ucfirst($payment_status); ?>
                                    </span>
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
                                    <?php if(!in_array($order['status'], ['completed', 'cancelled'], true)): ?>
                                        <form method="POST" class="assignment-form">
                                            <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                            <select name="employee_id" class="form-control" <?php echo empty($active_staff) ? 'disabled' : ''; ?>>
                                                <option value="">Unassigned</option>
                                                <?php foreach($active_staff as $staff): ?>
                                                    <option value="<?php echo $staff['user_id']; ?>"
                                                        <?php echo ($order['assigned_to'] == $staff['user_id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($staff['fullname']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" name="assign_order" class="btn btn-sm btn-outline-primary" <?php echo empty($active_staff) ? 'disabled' : ''; ?>>
                                                Save
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted">Assignment locked</span>
                                    <?php endif; ?>
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
