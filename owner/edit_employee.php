<?php
session_start();
require_once '../config/db.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$available_permissions = [
    'view_jobs' => 'View assigned jobs',
    'update_status' => 'Update job status',
    'upload_photos' => 'Upload output photos',
];

$shop_stmt = $pdo->prepare("SELECT * FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch();

if(!$shop) {
    header("Location: create_shop.php");
    exit();
}

$shop_id = $shop['id'];
$employee_id = (int) ($_GET['id'] ?? 0);
$message = '';
$message_type = 'success';

$employee_stmt = $pdo->prepare("
    SELECT se.*, u.fullname, u.email, u.phone
    FROM shop_employees se
    JOIN users u ON se.user_id = u.id
    WHERE se.id = ? AND se.shop_id = ?
");
$employee_stmt->execute([$employee_id, $shop_id]);
$employee = $employee_stmt->fetch();

if(!$employee) {
    $message = 'Employee not found for this shop.';
    $message_type = 'danger';
}

if($employee && isset($_POST['update_employee'])) {
    $fullname = sanitize($_POST['fullname'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $position = sanitize($_POST['position'] ?? '');
    $selected_permissions = $_POST['permissions'] ?? [];
    $permissions_map = [];
    foreach ($available_permissions as $permission_key => $permission_label) {
        $permissions_map[$permission_key] = in_array($permission_key, $selected_permissions, true);
    }
    $permissions = json_encode($permissions_map);

    if(!$fullname || !$email || !$position) {
        $message = 'Full name, email, and position are required.';
        $message_type = 'danger';
    } else {
        try {
            $email_stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $email_stmt->execute([$email, $employee['user_id']]);

            if($email_stmt->fetch()) {
                $message = 'That email is already in use.';
                $message_type = 'danger';
            } else {
                $update_user = $pdo->prepare("UPDATE users SET fullname = ?, email = ?, phone = ? WHERE id = ?");
                $update_user->execute([$fullname, $email, $phone, $employee['user_id']]);

                $update_employee = $pdo->prepare("UPDATE shop_employees SET position = ?, permissions = ? WHERE id = ? AND shop_id = ?");
                $update_employee->execute([$position, $permissions, $employee_id, $shop_id]);

                $message = 'Employee updated successfully!';
                $message_type = 'success';

                $employee_stmt->execute([$employee_id, $shop_id]);
                $employee = $employee_stmt->fetch();
            }
        } catch(PDOException $e) {
            $message = 'Failed to update employee: ' . $e->getMessage();
            $message_type = 'danger';
        }
    }
}

$current_permissions = [];
if($employee && !empty($employee['permissions'])) {
    $decoded_permissions = json_decode($employee['permissions'], true);
    if(is_array($decoded_permissions)) {
        $current_permissions = $decoded_permissions;
    }
}

foreach ($available_permissions as $permission_key => $permission_label) {
    if(!array_key_exists($permission_key, $current_permissions)) {
        $current_permissions[$permission_key] = false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Staff - <?php echo htmlspecialchars($shop['shop_name']); ?></title>
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
                <li><a href="shop_profile.php" class="nav-link">Shop Profile</a></li>
                <li><a href="manage_staff.php" class="nav-link active">Staff</a></li>
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
            <h2>Edit Employee</h2>
            <p class="text-muted">Update staff details and permissions.</p>
        </div>

        <?php if($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <?php if($employee): ?>
                <form method="POST">
                    <div class="form-group">
                        <label>Employee Full Name *</label>
                        <input type="text" name="fullname" class="form-control" required
                               value="<?php echo htmlspecialchars($employee['fullname']); ?>">
                    </div>

                    <div class="form-group">
                        <label>Employee Email *</label>
                        <input type="email" name="email" class="form-control" required
                               value="<?php echo htmlspecialchars($employee['email']); ?>">
                    </div>

                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="tel" name="phone" class="form-control"
                               value="<?php echo htmlspecialchars($employee['phone']); ?>">
                    </div>

                    <div class="form-group">
                        <label>Position *</label>
                        <select name="position" class="form-control" required>
                            <option value="">Select position</option>
                            <?php
                            $positions = [
                                'Designer',
                                'Embroidery Technician',
                                'Quality Control',
                                'Production Manager'
                            ];
                            foreach($positions as $role):
                            ?>
                                <option value="<?php echo $role; ?>" <?php echo $employee['position'] === $role ? 'selected' : ''; ?>>
                                    <?php echo $role; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Permissions</label>
                        <p class="text-muted mb-2">Select the permissions this staff member should have.</p>
                        <?php foreach ($available_permissions as $permission_key => $permission_label): ?>
                            <div class="form-check">
                                <input
                                    type="checkbox"
                                    class="form-check-input"
                                    id="permission_<?php echo $permission_key; ?>"
                                    name="permissions[]"
                                    value="<?php echo $permission_key; ?>"
                                    <?php echo !empty($current_permissions[$permission_key]) ? 'checked' : ''; ?>
                                >
                                <label class="form-check-label" for="permission_<?php echo $permission_key; ?>">
                                    <?php echo htmlspecialchars($permission_label); ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="d-flex justify-between align-center" style="margin-top: 1.5rem;">
                        <a href="manage_staff.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back
                        </a>
                        <button type="submit" name="update_employee" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <div class="text-center p-4">
                    <i class="fas fa-user-times fa-3x text-muted mb-3"></i>
                    <h4>Employee not found</h4>
                    <p class="text-muted">Return to staff management to pick another employee.</p>
                    <a href="manage_staff.php" class="btn btn-primary">
                        <i class="fas fa-arrow-left"></i> Back to Staff
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
