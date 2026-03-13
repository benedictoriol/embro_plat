<?php
session_start();
require_once '../config/db.php';
require_once 'partials.php';
require_role('sys_admin');

$allowed_statuses = ['pending', 'active', 'inactive', 'rejected'];
$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $user_id = (int) ($_POST['user_id'] ?? 0);
    $new_status = $_POST['new_status'] ?? '';
    $reason = trim($_POST['reason'] ?? '');

    if (!in_array($new_status, $allowed_statuses, true)) {
        $errors[] = 'Invalid status selected.';
    }

    if ($user_id <= 0) {
        $errors[] = 'Invalid user selected.';
    }

    if (empty($errors)) {
        $user_stmt = $pdo->prepare('SELECT id, fullname, role, status FROM users WHERE id = ? LIMIT 1');
        $user_stmt->execute([$user_id]);
        $target = $user_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$target) {
            $errors[] = 'User not found.';
        } elseif (($target['role'] ?? '') === 'sys_admin') {
            $errors[] = 'System administrator accounts cannot be modified here.';
        } elseif (($target['status'] ?? '') === $new_status) {
            $errors[] = 'Selected user is already in that status.';
        } else {
            $pdo->beginTransaction();
            try {
                $update = $pdo->prepare('UPDATE users SET status = ? WHERE id = ? LIMIT 1');
                $update->execute([$new_status, $user_id]);

                if (($target['role'] ?? '') === 'owner') {
                    if ($new_status === 'active') {
                        $shop_update = $pdo->prepare("UPDATE shops SET status = 'active', rejection_reason = NULL, rejected_at = NULL WHERE owner_id = ? AND status IN ('pending','rejected','suspended')");
                        $shop_update->execute([$user_id]);
                    } elseif (in_array($new_status, ['inactive', 'rejected'], true)) {
                        $shop_status = $new_status === 'rejected' ? 'rejected' : 'suspended';
                        $shop_update = $pdo->prepare('UPDATE shops SET status = ?, rejection_reason = ?, rejected_at = CASE WHEN ? = "rejected" THEN CURRENT_TIMESTAMP ELSE rejected_at END WHERE owner_id = ? AND status IN ("pending","active","suspended","rejected")');
                        $shop_update->execute([$shop_status, $reason !== '' ? $reason : null, $shop_status, $user_id]);
                    }
                }

                log_audit(
                    $pdo,
                    (int) ($_SESSION['user']['id'] ?? 0),
                    $_SESSION['user']['role'] ?? 'sys_admin',
                    'membership_status_updated',
                    'user',
                    $user_id,
                    ['status' => $target['status']],
                    ['status' => $new_status],
                    ['reason' => $reason, 'source' => 'membership_lifecycle']
                );

                $pdo->commit();
                $success = 'Account status updated successfully.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Unable to update account status right now.';
            }
        }
    }
}

$summary = $pdo->query("SELECT
    COUNT(*) AS total_users,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_users,
    SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive_users,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_users,
    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected_users
FROM users")->fetch(PDO::FETCH_ASSOC) ?: [];

$pending_owners_stmt = $pdo->query("SELECT
    u.id,
    u.fullname,
    u.email,
    u.status AS user_status,
    u.created_at,
    s.id AS shop_id,
    s.shop_name,
    s.status AS shop_status
FROM users u
LEFT JOIN shops s ON s.owner_id = u.id
WHERE u.role = 'owner'
  AND (u.status = 'pending' OR COALESCE(s.status, 'pending') = 'pending')
ORDER BY u.created_at ASC
LIMIT 20");
$pending_owners = $pending_owners_stmt->fetchAll(PDO::FETCH_ASSOC);

$managed_users_stmt = $pdo->query("SELECT id, fullname, email, role, status, created_at, last_login
FROM users
WHERE role <> 'sys_admin'
ORDER BY FIELD(status,'pending','inactive','rejected','active'), created_at DESC
LIMIT 40");
$managed_users = $managed_users_stmt->fetchAll(PDO::FETCH_ASSOC);

$audit_stmt = $pdo->query("SELECT a.created_at, a.action, a.entity_id, a.old_values, a.new_values, u.fullname AS actor_name
FROM audit_logs a
LEFT JOIN users u ON u.id = a.actor_id
WHERE a.entity_type = 'user'
  AND a.action = 'membership_status_updated'
ORDER BY a.created_at DESC
LIMIT 20");
$membership_audits = $audit_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Lifecycle Module - System Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php sys_admin_nav('membership_lifecycle'); ?>
<div class="container">
    <div class="dashboard-header fade-in">
        <div class="d-flex justify-between align-center">
            <div>
                <h2>User & Membership Lifecycle Management</h2>
                <p class="text-muted">Manage owner approvals, user status transitions, and lifecycle audit trails.</p>
            </div>
        </div>
    </div>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

            <div class="stats-grid mb-4">
        <div class="stat-card"><h3><?php echo number_format((int) ($summary['total_users'] ?? 0)); ?></h3><p>Total users</p></div>
        <div class="stat-card"><h3><?php echo number_format((int) ($summary['pending_users'] ?? 0)); ?></h3><p>Pending</p></div>
        <div class="stat-card"><h3><?php echo number_format((int) ($summary['active_users'] ?? 0)); ?></h3><p>Active</p></div>
        <div class="stat-card"><h3><?php echo number_format((int) ($summary['inactive_users'] ?? 0)); ?></h3><p>Inactive</p></div>
    </div>

            <div class="card mb-4">
        <div class="card-header">
            <h3><i class="fas fa-store text-warning"></i> Pending owner approvals</h3>
        </div>
        <div class="table-responsive">
            <table>
                <thead><tr><th>Owner</th><th>Shop</th><th>User Status</th><th>Shop Status</th><th>Requested</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if (empty($pending_owners)): ?>
                    <tr><td colspan="6" class="text-center text-muted">No pending owner approvals.</td></tr>
                <?php else: foreach ($pending_owners as $owner): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($owner['fullname']); ?><br><small class="text-muted"><?php echo htmlspecialchars($owner['email']); ?></small></td>
                        <td><?php echo htmlspecialchars($owner['shop_name'] ?? 'No shop profile'); ?></td>
                        <td><span class="badge badge-warning"><?php echo htmlspecialchars($owner['user_status']); ?></span></td>
                        <td><span class="badge badge-info"><?php echo htmlspecialchars($owner['shop_status'] ?? 'none'); ?></span></td>
                        <td><?php echo htmlspecialchars(date('M d, Y H:i', strtotime($owner['created_at']))); ?></td>
                        <td>
                            <form method="POST" class="d-flex gap-2" style="flex-wrap:wrap;">
                                <input type="hidden" name="user_id" value="<?php echo (int) $owner['id']; ?>">
                                <input type="hidden" name="new_status" value="active">
                                <button type="submit" name="action" value="update_status" class="btn btn-sm btn-success">Approve</button>
                            </form>
                            <form method="POST" class="d-flex gap-2 mt-1" style="flex-wrap:wrap;">
                                <input type="hidden" name="user_id" value="<?php echo (int) $owner['id']; ?>">
                                <input type="hidden" name="new_status" value="rejected">
                                <input type="text" name="reason" class="form-control" placeholder="Reason" maxlength="255" required>
                                <button type="submit" name="action" value="update_status" class="btn btn-sm btn-danger">Reject</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h3><i class="fas fa-users-cog text-primary"></i> User status management</h3></div>
        <div class="table-responsive">
            <table>
                <thead><tr><th>User</th><th>Role</th><th>Status</th><th>Last Login</th><th>Change status</th></tr></thead>
                <tbody>
                <?php foreach ($managed_users as $user): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($user['fullname']); ?><br><small class="text-muted"><?php echo htmlspecialchars($user['email']); ?></small></td>
                        <td><?php echo htmlspecialchars(ucfirst($user['role'])); ?></td>
                        <td><span class="badge badge-<?php echo $user['status'] === 'active' ? 'success' : ($user['status'] === 'pending' ? 'warning' : 'secondary'); ?>"><?php echo htmlspecialchars($user['status']); ?></span></td>
                        <td><?php echo $user['last_login'] ? htmlspecialchars(date('M d, Y H:i', strtotime($user['last_login']))) : '<span class="text-muted">Never</span>'; ?></td>
                        <td>
                            <form method="POST" class="d-flex gap-2 align-center">
                                <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
                                <select name="new_status" class="form-control form-control-sm" required>
                                    <?php foreach ($allowed_statuses as $status): ?>
                                        <option value="<?php echo $status; ?>" <?php echo $status === $user['status'] ? 'selected' : ''; ?>><?php echo ucfirst($status); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" name="reason" class="form-control form-control-sm" maxlength="255" placeholder="Optional reason">
                                <button type="submit" name="action" value="update_status" class="btn btn-sm btn-primary">Save</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3><i class="fas fa-clipboard-list text-info"></i> Membership status audit log</h3></div>
        <div class="table-responsive">
            <table>
                <thead><tr><th>When</th><th>Actor</th><th>User ID</th><th>From</th><th>To</th></tr></thead>
                <tbody>
                <?php if (empty($membership_audits)): ?>
                    <tr><td colspan="5" class="text-center text-muted">No lifecycle updates have been logged yet.</td></tr>
                <?php else: foreach ($membership_audits as $log): ?>
                    <?php $old = json_decode($log['old_values'] ?? '[]', true) ?: []; $new = json_decode($log['new_values'] ?? '[]', true) ?: []; ?>
                    <tr>
                        <td><?php echo htmlspecialchars(date('M d, Y H:i', strtotime($log['created_at']))); ?></td>
                        <td><?php echo htmlspecialchars($log['actor_name'] ?? 'System'); ?></td>
                        <td>#<?php echo (int) ($log['entity_id'] ?? 0); ?></td>
                        <td><?php echo htmlspecialchars($old['status'] ?? 'n/a'); ?></td>
                        <td><?php echo htmlspecialchars($new['status'] ?? 'n/a'); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php sys_admin_footer(); ?> 
</body>
</html>
