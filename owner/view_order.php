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

$order_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if($order_id <= 0) {
    header("Location: shop_orders.php");
    exit();
}

$order_stmt = $pdo->prepare("
    SELECT o.*, 
           u.fullname AS client_name,
           u.email AS client_email,
           u.phone AS client_phone,
           s.shop_name,
           au.fullname AS assigned_name
    FROM orders o
    JOIN users u ON o.client_id = u.id
    JOIN shops s ON o.shop_id = s.id
    LEFT JOIN users au ON o.assigned_to = au.id
    WHERE o.id = ? AND o.shop_id = ?
    LIMIT 1
");
$order_stmt->execute([$order_id, $shop['id']]);
$order = $order_stmt->fetch();

if(!$order) {
    header("Location: shop_orders.php");
    exit();
}

$payment_status = $order['payment_status'] ?? 'unpaid';
$payment_class = 'payment-' . $payment_status;
$design_file = $order['design_file']
    ? '../assets/uploads/designs/' . $order['design_file']
    : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order #<?php echo htmlspecialchars($order['order_number']); ?> - <?php echo htmlspecialchars($shop['shop_name']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .order-card {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        .detail-group {
            background: #f8fafc;
            border-radius: 12px;
            padding: 16px;
        }
        .detail-group h4 {
            margin-bottom: 12px;
        }
        .detail-group p {
            margin-bottom: 8px;
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
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        .payment-unpaid { background: #fef3c7; color: #92400e; }
        .payment-pending { background: #e0f2fe; color: #0369a1; }
        .payment-paid { background: #dcfce7; color: #166534; }
        .payment-rejected { background: #fee2e2; color: #991b1b; }
        .action-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .file-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
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
            <h2>Order #<?php echo htmlspecialchars($order['order_number']); ?></h2>
            <p class="text-muted">Review order details and client information.</p>
        </div>

        <div class="action-row mb-3">
            <a href="shop_orders.php" class="btn btn-outline-primary">
                <i class="fas fa-arrow-left"></i> Back to Orders
            </a>
            <?php if($order['status'] === 'pending'): ?>
                <a href="accept_order.php?id=<?php echo $order['id']; ?>" class="btn btn-success">Accept</a>
                <a href="reject_order.php?id=<?php echo $order['id']; ?>" class="btn btn-danger">Reject</a>
            <?php endif; ?>
        </div>

        <div class="order-card">
            <div class="detail-group">
                <h4>Order Overview</h4>
                <p><strong>Service:</strong> <?php echo htmlspecialchars($order['service_type']); ?></p>
                <p><strong>Quantity:</strong> <?php echo htmlspecialchars($order['quantity']); ?></p>
                <p><strong>Created:</strong> <?php echo date('M d, Y', strtotime($order['created_at'])); ?></p>
                <p><strong>Price:</strong> ₱<?php echo number_format($order['price'], 2); ?></p>
            </div>
            <div class="detail-group">
                <h4>Status</h4>
                <p>
                    <span class="status-pill status-<?php echo htmlspecialchars($order['status']); ?>">
                        <?php echo str_replace('_', ' ', ucfirst($order['status'])); ?>
                    </span>
                </p>
                <p>
                    <span class="status-pill <?php echo htmlspecialchars($payment_class); ?>">
                        <?php echo ucfirst($payment_status); ?> payment
                    </span>
                </p>
                <p><strong>Assigned To:</strong>
                    <?php if($order['assigned_name']): ?>
                        <?php echo htmlspecialchars($order['assigned_name']); ?>
                    <?php else: ?>
                        <span class="text-muted">Unassigned</span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="detail-group">
                <h4>Client Details</h4>
                <p><strong>Name:</strong> <?php echo htmlspecialchars($order['client_name']); ?></p>
                <p><strong>Email:</strong> <?php echo htmlspecialchars($order['client_email']); ?></p>
                <?php if(!empty($order['client_phone'])): ?>
                    <p><strong>Phone:</strong> <?php echo htmlspecialchars($order['client_phone']); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <h3>Design & Notes</h3>
            <p><strong>Description:</strong></p>
            <p><?php echo nl2br(htmlspecialchars($order['design_description'] ?? 'No description provided.')); ?></p>
            <p><strong>Client Notes:</strong></p>
            <p><?php echo nl2br(htmlspecialchars($order['client_notes'] ?? 'No notes provided.')); ?></p>
            <?php if($design_file): ?>
                <p class="mt-3">
                    <a class="file-link" href="<?php echo htmlspecialchars($design_file); ?>" target="_blank" rel="noopener noreferrer">
                        <i class="fas fa-file-download"></i> Download design file
                    </a>
                </p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
