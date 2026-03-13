<?php
session_start();
require_once '../config/db.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];

$shop_stmt = $pdo->prepare("SELECT * FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch();

if (!$shop) {
    header("Location: create_shop.php");
    exit();
}

$shop_id = $shop['id'];

if (isset($_POST['create_hr'])) {
    $fullname = sanitize($_POST['fullname']);
    $email = sanitize($_POST['email']);
    $phone = sanitize($_POST['phone']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    try {
        if ($password !== $confirm_password) {
            $error = "Passwords do not match!";
        } elseif (strlen($password) < 8) {
            $error = "Password must be at least 8 characters long!";
        } else {
            $user_stmt = $pdo->prepare("SELECT id, role FROM users WHERE email = ?");
            $user_stmt->execute([$email]);
            $user = $user_stmt->fetch();

            if ($user) {
                if ($user['role'] !== 'hr') {
                    $error = "HR accounts must be created separately. Existing client, staff, or owner accounts cannot be promoted to HR.";
                } else {
                    $check_stmt = $pdo->prepare("SELECT id, status FROM shop_staffs WHERE user_id = ? AND shop_id = ?");
                    $check_stmt->execute([$user['id'], $shop_id]);
                    $existing = $check_stmt->fetch();

                    if (!$existing) {
                        $add_stmt = $pdo->prepare("
                            INSERT INTO shop_staffs (shop_id, user_id, staff_role, position, hired_date)
                            VALUES (?, ?, 'hr', 'HR', CURDATE())
                        ");
                        $add_stmt->execute([$shop_id, $user['id']]);

                        if(function_exists('log_audit')) {
                            log_audit(
                                $pdo,
                                $owner_id,
                                $_SESSION['user']['role'] ?? 'owner',
                                'staff_account_linked',
                                'shop_staffs',
                                (int) $pdo->lastInsertId(),
                                [],
                                ['user_id' => (int) $user['id'], 'staff_role' => 'hr', 'status' => 'active'],
                                ['shop_id' => $shop_id]
                            );
                        }

                        $success = "HR added successfully!";
                    } elseif ($existing['status'] === 'inactive') {
                        $reactivate_stmt = $pdo->prepare("
                            UPDATE shop_staffs
                            SET status = 'active', staff_role = 'hr', position = 'HR', hired_date = CURDATE()
                            WHERE id = ? AND shop_id = ?
                        ");
                        $reactivate_stmt->execute([$existing['id'], $shop_id]);

                        if(function_exists('log_audit')) {
                            log_audit(
                                $pdo,
                                $owner_id,
                                $_SESSION['user']['role'] ?? 'owner',
                                'staff_status_changed',
                                'shop_staffs',
                                (int) $existing['id'],
                                ['status' => 'inactive'],
                                ['status' => 'active', 'staff_role' => 'hr'],
                                ['shop_id' => $shop_id, 'user_id' => (int) $user['id']]
                            );
                        }

                        $success = "HR reactivated successfully!";
                    } else {
                        $error = "User is already an active HR for this shop!";
                    }
                }
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $user_stmt = $pdo->prepare("
                    INSERT INTO users (fullname, email, password, phone, role, status)
                    VALUES (?, ?, ?, ?, 'hr', 'active')
                ");
                $user_stmt->execute([$fullname, $email, $hashed_password, $phone]);
                $user_id = $pdo->lastInsertId();

                $add_stmt = $pdo->prepare("
                    INSERT INTO shop_staffs (shop_id, user_id, staff_role, position, hired_date)
                    VALUES (?, ?, 'hr', 'HR', CURDATE())
                ");
                $add_stmt->execute([$shop_id, $user_id]);

                if(function_exists('log_audit')) {
                    log_audit(
                        $pdo,
                        $owner_id,
                        $_SESSION['user']['role'] ?? 'owner',
                        'staff_account_created',
                        'users',
                        (int) $user_id,
                        [],
                        ['role' => 'hr', 'status' => 'active'],
                        ['shop_id' => $shop_id, 'staff_role' => 'hr']
                    );
                }

                $success = "HR account created and added successfully!";
            }
        }
    } catch (PDOException $e) {
        $error = "Failed to create HR: " . $e->getMessage();
    }
}

$hr_members_stmt = $pdo->prepare("
    SELECT ss.id, ss.staff_role, ss.position, ss.status, ss.hired_date, ss.created_at, u.fullname, u.email, u.phone
    FROM shop_staffs ss
    JOIN users u ON ss.user_id = u.id
    WHERE ss.shop_id = ? AND ss.staff_role = 'hr'
    ORDER BY ss.created_at DESC
");
$hr_members_stmt->execute([$shop_id]);
$hr_members = $hr_members_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create HR - <?php echo htmlspecialchars($shop['shop_name']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include __DIR__ . "/includes/owner_navbar.php"; ?>

    <div class="container">
        <div class="dashboard-header">
            <div>
                <h2>Create HR</h2>
                <p class="text-muted">Owner accounts can only create HR users for the shop.</p>
            </div>
            <a href="manage_staff.php" class="btn btn-outline-secondary">Back to Staff</a>
        </div>

        <?php if(isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if(isset($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <div class="card" style="max-width: 520px;">
            <h3>HR Details</h3>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="fullname" class="form-control" required
                           placeholder="Enter HR full name">
                </div>

                <div class="form-group">
                    <label>Email *</label>
                    <input type="email" name="email" class="form-control" required
                           placeholder="Enter HR email">
                    <small class="text-muted">HR accounts must be created with a unique email address.</small>
                </div>

                <div class="form-group">
                    <label>Phone Number *</label>
                    <input type="tel" name="phone" class="form-control" required
                           placeholder="Enter HR phone number">
                </div>

                <div class="form-group">
                    <label>Password *</label>
                    <input type="password" name="password" class="form-control" required minlength="8"
                           placeholder="At least 8 characters">
                </div>

                <div class="form-group">
                    <label>Confirm Password *</label>
                    <input type="password" name="confirm_password" class="form-control" required minlength="8"
                           placeholder="Confirm password">
                </div>

                <div class="d-flex justify-between" style="gap: 12px;">
                    <button type="submit" name="create_hr" class="btn btn-primary">Create HR</button>
                    <a href="manage_staff.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
        
        <div class="card mt-4">
            <h3>Created HR Users (<?php echo count($hr_members); ?>)</h3>
            <?php if(!empty($hr_members)): ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Contact</th>
                            <th>Hired</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($hr_members as $hr_member): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($hr_member['fullname']); ?></strong><br>
                                    <small class="text-muted"><?php echo htmlspecialchars($hr_member['email']); ?></small>
                                </td>
                                <td><span class="badge badge-info">HR</span></td>
                                <td><?php echo htmlspecialchars($hr_member['phone'] ?? 'N/A'); ?></td>
                                <td><?php echo !empty($hr_member['hired_date']) ? date('M d, Y', strtotime($hr_member['hired_date'])) : date('M d, Y', strtotime($hr_member['created_at'])); ?></td>
                                <td>
                                    <?php if($hr_member['status'] === 'active'): ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">Inactive</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="text-muted mb-0">No HR accounts assigned to this shop yet.</p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
