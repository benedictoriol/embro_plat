<?php
session_start();
require_once '../config/db.php';
require_role('owner');

$owner_id = (int) $_SESSION['user']['id'];
$success = '';
$error = '';

$shop_stmt = $pdo->prepare("SELECT id, shop_name FROM shops WHERE owner_id = ? LIMIT 1");
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch();

if(!$shop) {
    header('Location: create_shop.php');
    exit();
}

$shop_id = (int) $shop['id'];

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');
    $ticket_id = (int) ($_POST['ticket_id'] ?? 0);

    $ticket_stmt = $pdo->prepare(" 
        SELECT st.id, st.order_id, st.client_id, st.assigned_staff_id, st.status, o.order_number, s.owner_id
        FROM support_tickets st
        JOIN orders o ON o.id = st.order_id
        JOIN shops s ON s.id = o.shop_id
        WHERE st.id = ? AND s.owner_id = ?
        LIMIT 1
    ");
    $ticket_stmt->execute([$ticket_id, $owner_id]);
    $ticket = $ticket_stmt->fetch();

    if(!$ticket) {
        $error = 'Ticket not found.';
    } elseif($action === 'review_ticket') {
        $update = $pdo->prepare("UPDATE support_tickets SET status = 'under_review' WHERE id = ? AND status = 'open'");
        $update->execute([$ticket_id]);
        $success = 'Ticket marked as under review.';
        notify_support_ticket_update($pdo, $ticket, 'is now under owner review', $owner_id);
        if(function_exists('order_exception_open')) {
            order_exception_open($pdo, (int) $ticket['order_id'], 'support_unresolved', 'medium', 'Support ticket moved under review by owner.', (int) $ticket['assigned_staff_id'], $owner_id, 'owner');
        }
    } elseif($action === 'assign_ticket') {
        $staff_id = (int) ($_POST['staff_id'] ?? 0);
        $staff_stmt = $pdo->prepare(" 
            SELECT user_id FROM shop_staffs
            WHERE shop_id = ? AND user_id = ? AND status = 'active' AND staff_role = 'staff'
            LIMIT 1
        ");
        $staff_stmt->execute([$shop_id, $staff_id]);
        $staff = $staff_stmt->fetch();

        if(!$staff) {
            $error = 'Selected staff is not active for this shop.';
        } else {
            $update = $pdo->prepare("UPDATE support_tickets SET assigned_staff_id = ?, status = 'assigned' WHERE id = ?");
            $update->execute([$staff_id, $ticket_id]);
            $ticket['assigned_staff_id'] = $staff_id;
            $success = 'Ticket assigned successfully.';
            notify_support_ticket_update($pdo, $ticket, 'was assigned to staff for resolution', $owner_id);
            if(function_exists('order_exception_open')) {
                order_exception_open($pdo, (int) $ticket['order_id'], 'support_unresolved', 'medium', 'Support ticket assigned and waiting resolution.', $staff_id, $owner_id, 'owner');
            }
        }
    }
}

$staff_stmt = $pdo->prepare(" 
    SELECT u.id, u.fullname
    FROM shop_staffs ss
    JOIN users u ON u.id = ss.user_id
    WHERE ss.shop_id = ? AND ss.status = 'active' AND ss.staff_role = 'staff'
    ORDER BY u.fullname ASC
");
$staff_stmt->execute([$shop_id]);
$staffs = $staff_stmt->fetchAll();

$tickets_stmt = $pdo->prepare(" 
    SELECT st.*, o.order_number, c.fullname AS client_name, su.fullname AS assigned_staff_name,
           (SELECT COUNT(*) FROM order_exceptions oe WHERE oe.order_id = st.order_id AND oe.status IN ('open','in_progress','escalated')) AS exception_open_count,
           (SELECT COUNT(*) FROM order_exceptions oe WHERE oe.order_id = st.order_id AND oe.status = 'escalated') AS exception_escalated_count
    FROM support_tickets st
    JOIN orders o ON o.id = st.order_id
    JOIN shops s ON s.id = o.shop_id
    JOIN users c ON c.id = st.client_id
    LEFT JOIN users su ON su.id = st.assigned_staff_id
    WHERE s.owner_id = ?
    ORDER BY st.created_at DESC
");
$tickets_stmt->execute([$owner_id]);
$tickets = $tickets_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Management</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/includes/owner_navbar.php'; ?>
    <div class="container">
        <div class="dashboard-header">
            <h2>Support Management</h2>
            <p class="text-muted">Review order tickets and assign staff handling.</p>
        </div>

        <?php if($error): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>
        <?php if($success): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>

        <div class="card">
            <h3>Shop tickets</h3>
            <?php if(!$tickets): ?>
                <p class="text-muted mb-0">No tickets available.</p>
            <?php else: ?>
            <table class="table">
                <thead><tr><th>ID</th><th>Order</th><th>Client</th><th>Issue</th><th>Status</th><th>Assigned</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach($tickets as $ticket): ?>
                    <tr>
                        <td>#<?php echo (int) $ticket['id']; ?></td>
                        <td><?php echo htmlspecialchars($ticket['order_number']); ?></td>
                        <td><?php echo htmlspecialchars($ticket['client_name']); ?></td>
                        <td><strong><?php echo htmlspecialchars($ticket['issue_type']); ?></strong><br><small><?php echo htmlspecialchars($ticket['description']); ?></small></td>
                        <td><?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($ticket['status']))); ?>
                            <?php if((int) ($ticket['exception_open_count'] ?? 0) > 0): ?>
                                <div><span class="badge badge-warning"><?php echo (int) ($ticket['exception_open_count'] ?? 0); ?> open exceptions</span></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($ticket['assigned_staff_name'] ?? 'Unassigned'); ?></td>
                        <td>
                            <?php if($ticket['status'] === 'open'): ?>
                            <form method="POST" class="mb-1">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="review_ticket">
                                <input type="hidden" name="ticket_id" value="<?php echo (int) $ticket['id']; ?>">
                                <button class="btn btn-sm btn-outline-primary" type="submit">Mark Review</button>
                            </form>
                            <?php endif; ?>

                            <form method="POST" class="d-flex gap-2 align-center">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="assign_ticket">
                                <input type="hidden" name="ticket_id" value="<?php echo (int) $ticket['id']; ?>">
                                <select name="staff_id" required>
                                    <option value="">Assign staff</option>
                                    <?php foreach($staffs as $staff): ?>
                                        <option value="<?php echo (int) $staff['id']; ?>"><?php echo htmlspecialchars($staff['fullname']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-sm btn-primary" type="submit">Assign</button>
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
