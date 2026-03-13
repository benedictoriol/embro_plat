<?php
session_start();
require_once '../config/db.php';
require_role(['owner', 'hr']);

$user_id = (int) ($_SESSION['user']['id'] ?? 0);
$user_role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
$success = '';
$error = '';

if($user_role === 'owner') {
    $shop_stmt = $pdo->prepare("SELECT id, shop_name, owner_id FROM shops WHERE owner_id = ? LIMIT 1");
    $shop_stmt->execute([$user_id]);
} else {
    $shop_stmt = $pdo->prepare("SELECT s.id, s.shop_name, s.owner_id FROM shops s JOIN shop_staffs ss ON ss.shop_id = s.id WHERE ss.user_id = ? AND ss.status = 'active' LIMIT 1");
    $shop_stmt->execute([$user_id]);
}
$shop = $shop_stmt->fetch(PDO::FETCH_ASSOC);
if(!$shop) {
    die('Shop context not found.');
}

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $exception_id = (int) ($_POST['exception_id'] ?? 0);
    $status = sanitize($_POST['status'] ?? '');
    $note = sanitize($_POST['note'] ?? '');
    $assigned_handler_id = (int) ($_POST['assigned_handler_id'] ?? 0);
    $assigned_handler_id = $assigned_handler_id > 0 ? $assigned_handler_id : null;

    if(!function_exists('order_exception_update')) {
        $error = 'Exception service is unavailable.';
    } elseif(!order_exception_update($pdo, $exception_id, $status, $note, $assigned_handler_id, $user_id, $user_role)) {
        $error = 'Unable to update exception.';
    } else {
        $success = 'Exception updated successfully.';
    }
}

$status_filter = sanitize($_GET['status'] ?? 'open');
$allowed_filters = ['open', 'in_progress', 'escalated', 'resolved', 'dismissed', 'all'];
if(!in_array($status_filter, $allowed_filters, true)) {
    $status_filter = 'open';
}

$staff_stmt = $pdo->prepare("SELECT u.id, u.fullname FROM users u JOIN shop_staffs ss ON ss.user_id = u.id WHERE ss.shop_id = ? AND ss.status = 'active' ORDER BY u.fullname ASC");
$staff_stmt->execute([(int) $shop['id']]);
$staff_members = $staff_stmt->fetchAll(PDO::FETCH_ASSOC);

$query = "
    SELECT oe.*, o.order_number, o.status AS order_status, c.fullname AS client_name, h.fullname AS handler_name
    FROM order_exceptions oe
    JOIN orders o ON o.id = oe.order_id
    JOIN shops s ON s.id = o.shop_id
    LEFT JOIN users c ON c.id = o.client_id
    LEFT JOIN users h ON h.id = oe.assigned_handler_id
    WHERE s.id = ?
";
$params = [(int) $shop['id']];
if($status_filter !== 'all') {
    $query .= " AND oe.status = ?";
    $params[] = $status_filter;
}
$query .= " ORDER BY FIELD(oe.status, 'escalated', 'open', 'in_progress', 'resolved', 'dismissed'), FIELD(oe.severity, 'critical', 'high', 'medium', 'low'), oe.updated_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$exceptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$history_map = [];
if(!empty($exceptions)) {
    $exception_ids = array_values(array_unique(array_map(static fn($e) => (int) ($e['id'] ?? 0), $exceptions)));
    $placeholders = implode(',', array_fill(0, count($exception_ids), '?'));
    $history_stmt = $pdo->prepare("SELECT eh.*, u.fullname AS actor_name FROM order_exception_history eh LEFT JOIN users u ON u.id = eh.actor_id WHERE eh.exception_id IN ({$placeholders}) ORDER BY eh.id DESC");
    $history_stmt->execute($exception_ids);
    foreach($history_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $eid = (int) ($row['exception_id'] ?? 0);
        if(!isset($history_map[$eid])) {
            $history_map[$eid] = [];
        }
        if(count($history_map[$eid]) < 3) {
            $history_map[$eid][] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exception Dashboard</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/includes/owner_navbar.php'; ?>
<div class="container">
    <div class="dashboard-header">
        <h2>Exception Dashboard</h2>
        <p class="text-muted">Central incident view for exceptions, assignments, escalations, and resolutions.</p>
    </div>

    <?php if($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if($success): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <div class="card mb-3">
        <div class="d-flex gap-2">
            <?php foreach($allowed_filters as $filter): ?>
                <a class="btn btn-sm <?php echo $filter === $status_filter ? 'btn-primary' : 'btn-outline-primary'; ?>" href="?status=<?php echo urlencode($filter); ?>"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $filter))); ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <h3>Exceptions</h3>
        <?php if(!$exceptions): ?>
            <p class="text-muted">No exceptions found for this filter.</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>Order</th><th>Type</th><th>Severity</th><th>Status</th><th>Handler</th><th>Notes</th><th>Timeline</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach($exceptions as $ex): ?>
                    <tr>
                        <td>#<?php echo htmlspecialchars($ex['order_number']); ?><br><small><?php echo htmlspecialchars($ex['client_name'] ?? 'N/A'); ?></small></td>
                        <td><?php echo htmlspecialchars(str_replace('_', ' ', $ex['exception_type'])); ?></td>
                        <td><span class="badge badge-<?php echo in_array($ex['severity'], ['high', 'critical'], true) ? 'danger' : 'warning'; ?>"><?php echo htmlspecialchars($ex['severity']); ?></span></td>
                        <td><?php echo htmlspecialchars(str_replace('_', ' ', $ex['status'])); ?></td>
                        <td><?php echo htmlspecialchars($ex['handler_name'] ?? 'Unassigned'); ?></td>
                        <td style="max-width:240px;"><?php echo nl2br(htmlspecialchars((string) ($ex['notes'] ?? ''))); ?></td>
                        <td>
                            <small>
                                Escalated: <?php echo htmlspecialchars($ex['escalated_at'] ?: '—'); ?><br>
                                Resolved: <?php echo htmlspecialchars($ex['resolved_at'] ?: '—'); ?>
                            </small>
                            <?php if(!empty($history_map[(int) $ex['id']])): ?>
                                <div class="mt-1">
                                <?php foreach($history_map[(int) $ex['id']] as $event): ?>
                                    <div><small><?php echo htmlspecialchars($event['created_at']); ?> · <?php echo htmlspecialchars($event['action']); ?><?php if(!empty($event['actor_name'])): ?> by <?php echo htmlspecialchars($event['actor_name']); ?><?php endif; ?></small></div>
                                <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="POST">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="exception_id" value="<?php echo (int) $ex['id']; ?>">
                                <select name="status" required>
                                    <?php foreach(['open','in_progress','escalated','resolved','dismissed'] as $s): ?>
                                        <option value="<?php echo $s; ?>" <?php echo $s === $ex['status'] ? 'selected' : ''; ?>><?php echo ucfirst(str_replace('_', ' ', $s)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="assigned_handler_id">
                                    <option value="">Unchanged handler</option>
                                    <?php foreach($staff_members as $staff): ?>
                                        <option value="<?php echo (int) $staff['id']; ?>"><?php echo htmlspecialchars($staff['fullname']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" name="note" placeholder="Add note">
                                <button type="submit" class="btn btn-sm btn-primary">Save</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
