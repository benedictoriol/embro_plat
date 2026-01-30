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

$success = '';
$error = '';

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $shop_name = sanitize($_POST['shop_name']);
    $shop_description = sanitize($_POST['shop_description']);
    $address = sanitize($_POST['address']);
    $phone = sanitize($_POST['phone']);
    $email = sanitize($_POST['email']);
    $business_permit = sanitize($_POST['business_permit']);

    try {
        $update_stmt = $pdo->prepare("
            UPDATE shops 
            SET shop_name = ?, shop_description = ?, address = ?, phone = ?, email = ?, business_permit = ?
            WHERE id = ?
        ");
        $update_stmt->execute([
            $shop_name,
            $shop_description,
            $address,
            $phone,
            $email,
            $business_permit,
            $shop['id']
        ]);

        $shop_stmt->execute([$owner_id]);
        $shop = $shop_stmt->fetch();
        $success = 'Shop profile updated successfully.';
    } catch(PDOException $e) {
        $error = 'Failed to update shop profile: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shop Profile - <?php echo htmlspecialchars($shop['shop_name']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <nav class="navbar">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-store"></i> <?php echo htmlspecialchars($shop['shop_name']); ?>
            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <li><a href="shop_profile.php" class="nav-link active">Shop Profile</a></li>
                <li><a href="manage_staff.php" class="nav-link">Staff</a></li>
                <li><a href="shop_orders.php" class="nav-link">Orders</a></li>
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
            <h2>Shop Profile</h2>
            <p class="text-muted">Manage your shop information and public listing details.</p>
        </div>

        <?php if($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <div class="card">
            <form method="POST">
                <div class="form-group">
                    <label>Shop Name *</label>
                    <input type="text" name="shop_name" class="form-control" required
                           value="<?php echo htmlspecialchars($shop['shop_name']); ?>">
                </div>

                <div class="form-group">
                    <label>Shop Description *</label>
                    <textarea name="shop_description" class="form-control" rows="4" required><?php echo htmlspecialchars($shop['shop_description']); ?></textarea>
                </div>

                <div class="form-group">
                    <label>Business Address *</label>
                    <textarea name="address" class="form-control" rows="3" required><?php echo htmlspecialchars($shop['address']); ?></textarea>
                </div>

                <div class="row" style="display: flex; gap: 15px;">
                    <div class="form-group" style="flex: 1;">
                        <label>Contact Phone *</label>
                        <input type="tel" name="phone" class="form-control" required
                               value="<?php echo htmlspecialchars($shop['phone']); ?>">
                    </div>

                    <div class="form-group" style="flex: 1;">
                        <label>Contact Email</label>
                        <input type="email" name="email" class="form-control"
                               value="<?php echo htmlspecialchars($shop['email']); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label>Business Permit Number</label>
                    <input type="text" name="business_permit" class="form-control"
                           value="<?php echo htmlspecialchars($shop['business_permit']); ?>">
                </div>

                <div class="row" style="display: flex; gap: 15px;">
                    <div class="card" style="flex: 1; background: #f8fafc;">
                        <h4>Shop Status</h4>
                        <p class="text-muted">Current status: <strong><?php echo ucfirst($shop['status']); ?></strong></p>
                        <p class="text-muted">Rating: <?php echo number_format($shop['rating'], 1); ?> / 5</p>
                    </div>
                    <div class="card" style="flex: 1; background: #f8fafc;">
                        <h4>Performance Snapshot</h4>
                        <p class="text-muted">Total Orders: <?php echo $shop['total_orders']; ?></p>
                        <p class="text-muted">Total Earnings: ₱<?php echo number_format($shop['total_earnings'], 2); ?></p>
                    </div>
                </div>

                <div class="text-center mt-4">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
