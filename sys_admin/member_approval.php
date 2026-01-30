<?php
session_start();
require_once '../config/db.php';
require_once 'partials.php';
require_role('sys_admin');

$message = '';
$messageType = 'success';
$actorId = $_SESSION['user']['id'] ?? null;
$actorRole = $_SESSION['user']['role'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

    if ($id <= 0) {
        $message = 'Invalid request received.';
        $messageType = 'danger';
    } else {
        try {
            if ($action === 'approve_user') {
                $statusStmt = $pdo->prepare("SELECT status FROM users WHERE id = ?");
                $statusStmt->execute([$id]);
                $previousStatus = $statusStmt->fetchColumn();
                $stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?");
                $stmt->execute([$id]);
                log_audit(
                    $pdo,
                    $actorId,
                    $actorRole,
                    'approve_user',
                    'users',
                    $id,
                    ['status' => $previousStatus],
                    ['status' => 'active']
                );
                $message = 'User approved successfully.';
            } elseif ($action === 'reject_user') {
                $statusStmt = $pdo->prepare("SELECT status FROM users WHERE id = ?");
                $statusStmt->execute([$id]);
                $previousStatus = $statusStmt->fetchColumn();
                $stmt = $pdo->prepare("UPDATE users SET status = 'rejected' WHERE id = ?");
                $stmt->execute([$id]);
                log_audit(
                    $pdo,
                    $actorId,
                    $actorRole,
                    'reject_user',
                    'users',
                    $id,
                    ['status' => $previousStatus],
                    ['status' => 'rejected']
                );
                $message = 'User rejected successfully.';
                $messageType = 'warning';
            } elseif ($action === 'approve_shop') {
                $shopStatusStmt = $pdo->prepare("SELECT status, owner_id FROM shops WHERE id = ?");
                $shopStatusStmt->execute([$id]);
                $shopData = $shopStatusStmt->fetch();
                $previousShopStatus = $shopData['status'] ?? null;
                $shopStmt = $pdo->prepare("SELECT owner_id FROM shops WHERE id = ?");
                $shopStmt->execute([$id]);
                $ownerId = $shopStmt->fetchColumn();

                $stmt = $pdo->prepare("UPDATE shops SET status = 'active' WHERE id = ?");
                $stmt->execute([$id]);
                log_audit(
                    $pdo,
                    $actorId,
                    $actorRole,
                    'approve_shop',
                    'shops',
                    $id,
                    ['status' => $previousShopStatus],
                    ['status' => 'active']
                );

                if ($ownerId) {
                    $ownerStatusStmt = $pdo->prepare("SELECT status FROM users WHERE id = ?");
                    $ownerStatusStmt->execute([$ownerId]);
                    $previousOwnerStatus = $ownerStatusStmt->fetchColumn();
                    $ownerStmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ?");
                    $ownerStmt->execute([$ownerId]);
                    log_audit(
                        $pdo,
                        $actorId,
                        $actorRole,
                        'activate_shop_owner',
                        'users',
                        (int) $ownerId,
                        ['status' => $previousOwnerStatus],
                        ['status' => 'active']
                    );
                }

                $message = 'Shop approved successfully.';
            } elseif ($action === 'reject_shop') {
                $statusStmt = $pdo->prepare("SELECT status FROM shops WHERE id = ?");
                $statusStmt->execute([$id]);
                $previousStatus = $statusStmt->fetchColumn();
                $stmt = $pdo->prepare("UPDATE shops SET status = 'rejected' WHERE id = ?");
                $stmt->execute([$id]);
                log_audit(
                    $pdo,
                    $actorId,
                    $actorRole,
                    'reject_shop',
                    'shops',
                    $id,
                    ['status' => $previousStatus],
                    ['status' => 'rejected']
                );
                $message = 'Shop rejected successfully.';
                $messageType = 'warning';
            } else {
                $message = 'Unsupported action requested.';
                $messageType = 'danger';
            }
        } catch (PDOException $e) {
            $message = 'Operation failed: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

$pendingUsers = $pdo->query("SELECT id, fullname, email, phone, role, created_at FROM users WHERE status = 'pending' ORDER BY created_at DESC")
    ->fetchAll();

$pendingShops = $pdo->query("
    SELECT s.id, s.shop_name, s.owner_id, s.address, s.phone, s.created_at, u.fullname as owner_name, u.email as owner_email
    FROM shops s
    JOIN users u ON s.owner_id = u.id
    WHERE s.status = 'pending'
    ORDER BY s.created_at DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Approvals - System Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .approval-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .approval-card {
            grid-column: span 6;
        }

        .approval-actions {
            display: flex;
            gap: 0.5rem;
        }

        .empty-state {
            text-align: center;
            padding: 2rem;
            color: var(--gray-500);
        }
    </style>
</head>
<body>
    <?php sys_admin_nav('member_approval'); ?>

    <div class="container">
        <div class="dashboard-header fade-in">
            <div class="d-flex justify-between align-center">
                <div>
                    <h2>Member Approvals</h2>
                    <p class="text-muted">Review user and shop requests awaiting approval.</p>
                </div>
                <span class="badge badge-warning"><i class="fas fa-user-check"></i> Pending Review</span>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <i class="fas fa-info-circle"></i> <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="approval-grid">
            <div class="card approval-card">
                <div class="card-header">
                    <h3><i class="fas fa-user-clock text-primary"></i> Pending Users</h3>
                    <p class="text-muted">Approve or reject new account requests.</p>
                </div>
                <?php if (empty($pendingUsers)): ?>
                    <div class="empty-state">
                        <i class="fas fa-user-check fa-2x mb-2"></i>
                        <p class="mb-0">No pending users at the moment.</p>
                    </div>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Role</th>
                                <th>Contact</th>
                                <th>Requested</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingUsers as $user): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user['fullname']); ?></td>
                                    <td><span class="badge badge-info"><?php echo ucfirst($user['role']); ?></span></td>
                                    <td>
                                        <div><?php echo htmlspecialchars($user['email']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($user['phone'] ?? 'N/A'); ?></small>
                                    </td>
                                    <td><?php echo $user['created_at'] ? date('M d, Y', strtotime($user['created_at'])) : '—'; ?></td>
                                    <td>
                                        <div class="approval-actions">
                                            <form method="POST">
                                                <input type="hidden" name="action" value="approve_user">
                                                <input type="hidden" name="id" value="<?php echo (int) $user['id']; ?>">
                                                <button class="btn btn-sm btn-success" type="submit">Approve</button>
                                            </form>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="reject_user">
                                                <input type="hidden" name="id" value="<?php echo (int) $user['id']; ?>">
                                                <button class="btn btn-sm btn-outline-danger" type="submit">Reject</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="card approval-card">
                <div class="card-header">
                    <h3><i class="fas fa-store-alt text-success"></i> Pending Shops</h3>
                    <p class="text-muted">Review shop profiles submitted by owners.</p>
                </div>
                <?php if (empty($pendingShops)): ?>
                    <div class="empty-state">
                        <i class="fas fa-store fa-2x mb-2"></i>
                        <p class="mb-0">No pending shops at the moment.</p>
                    </div>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Shop</th>
                                <th>Owner</th>
                                <th>Contact</th>
                                <th>Requested</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingShops as $shop): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($shop['shop_name']); ?></strong>
                                        <div class="text-muted"><?php echo htmlspecialchars($shop['address'] ?? 'Address not provided'); ?></div>
                                    </td>
                                    <td><?php echo htmlspecialchars($shop['owner_name']); ?></td>
                                    <td>
                                        <div><?php echo htmlspecialchars($shop['owner_email']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($shop['phone'] ?? 'N/A'); ?></small>
                                    </td>
                                    <td><?php echo $shop['created_at'] ? date('M d, Y', strtotime($shop['created_at'])) : '—'; ?></td>
                                    <td>
                                        <div class="approval-actions">
                                            <form method="POST">
                                                <input type="hidden" name="action" value="approve_shop">
                                                <input type="hidden" name="id" value="<?php echo (int) $shop['id']; ?>">
                                                <button class="btn btn-sm btn-success" type="submit">Approve</button>
                                            </form>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="reject_shop">
                                                <input type="hidden" name="id" value="<?php echo (int) $shop['id']; ?>">
                                                <button class="btn btn-sm btn-outline-danger" type="submit">Reject</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php sys_admin_footer(); ?>
</body>
</html>
