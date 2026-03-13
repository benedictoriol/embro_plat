<?php
session_start();
require_once '../config/db.php';
require_role('owner');

$ownerId = (int) ($_SESSION['user']['id'] ?? 0);
$snapshot = fetch_owner_onboarding_snapshot($ownerId, $pdo);

$notice = $_SESSION['owner_gate_notice'] ?? '';
unset($_SESSION['owner_gate_notice']);

if (($snapshot['state'] ?? '') === 'approved') {
    header('Location: /owner/dashboard.php');
    exit;
}

if ($notice === '') {
    if (($snapshot['state'] ?? '') === 'rejected') {
        $notice = 'Your shop has not yet been verified. Please update your profile and wait for re-evaluation.';
    } else {
        $notice = 'Your shop profile is complete and currently awaiting system admin approval.';
    }
}

$shop = $snapshot['shop'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Awaiting Approval - Owner Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/includes/owner_navbar.php'; ?>

    <div class="container">
        <div class="card mt-4" style="max-width: 780px; margin: 0 auto;">
            <div class="card-header">
                <h2><i class="fas fa-hourglass-half"></i> Awaiting Approval</h2>
                <p class="text-muted">Your shop is currently under system admin verification.</p>
            </div>

            <div class="alert alert-warning"><?php echo htmlspecialchars($notice); ?></div>

            <?php if (is_array($shop)): ?>
                <div class="alert alert-info">
                    <strong>Shop:</strong> <?php echo htmlspecialchars((string) ($shop['shop_name'] ?? 'Unnamed shop')); ?><br>
                    <strong>Status:</strong> <?php echo htmlspecialchars(ucfirst((string) ($shop['status'] ?? 'pending'))); ?><br>
                    <strong>Submitted:</strong> <?php echo !empty($shop['profile_completed_at']) ? date('M d, Y h:i A', strtotime((string) $shop['profile_completed_at'])) : 'Not yet recorded'; ?>
                </div>
            <?php endif; ?>

            <div class="d-flex gap-2">
                <a href="shop_profile.php" class="btn btn-primary">Review Shop Profile</a>
                <a href="../auth/logout.php" class="btn btn-outline">Logout</a>
            </div>
        </div>
    </div>
</body>
</html>
