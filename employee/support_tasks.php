<?php
session_start();
require_once '../config/db.php';
require_role(['staff','hr']);

$staff_id = (int) $_SESSION['user']['id'];
$success = '';
$error = '';

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ticket_id = (int) ($_POST['ticket_id'] ?? 0);
    $status = sanitize($_POST['status'] ?? '');

    $ticket_stmt = $pdo->prepare(" 
        SELECT st.id, st.order_id, st.client_id, st.assigned_staff_id, o.order_number, s.owner_id
        FROM support_tickets st
        JOIN orders o ON o.id = st.order_id
        JOIN shops s ON s.id = o.shop_id
        WHERE st.id = ? AND st.assigned_staff_id = ?
        LIMIT 1
    ");
    $ticket_stmt->execute([$ticket_id, $staff_id]);
    $ticket = $ticket_stmt->fetch();

    $allowed = ['in_progress', 'resolved'];
    if(!$ticket) {
        $error = 'Assigned ticket not found.';
    } elseif(!in_array($status, $allowed, true)) {
        $error = 'Invalid status update.';
    } else {
        if($status === 'resolved') {
            $update = $pdo->prepare("UPDATE support_tickets SET status = 'resolved', resolved_at = NOW() WHERE id = ?");
            $update->execute([$ticket_id]);
            $success = 'Ticket marked as resolved.';
            notify_support_ticket_update($pdo, $ticket, 'was marked as resolved by staff', $staff_id);
            if(function_exists('order_exception_resolve')) {
                order_exception_resolve($pdo, (int) $ticket['order_id'], 'support_unresolved', 'Support ticket resolved by assigned staff.', $staff_id, 'staff');
            }
        } else {
            $update = $pdo->prepare("UPDATE support_tickets SET status = 'in_progress' WHERE id = ?");
            $update->execute([$ticket_id]);
            $success = 'Ticket moved to in-progress.';
            notify_support_ticket_update($pdo, $ticket, 'is now in progress', $staff_id);
            if(function_exists('order_exception_open')) {
                order_exception_open($pdo, (int) $ticket['order_id'], 'support_unresolved', 'medium', 'Support ticket is being worked by assigned staff.', $staff_id, $staff_id, 'staff');
            }
        }
    }
}

$tickets_stmt = $pdo->prepare(" 
    SELECT st.*, o.order_number, c.fullname AS client_name
    FROM support_tickets st
    JOIN orders o ON o.id = st.order_id
    JOIN users c ON c.id = st.client_id
    WHERE st.assigned_staff_id = ?
    ORDER BY st.created_at DESC
");
$tickets_stmt->execute([$staff_id]);
$tickets = $tickets_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Tasks</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <?php require_once __DIR__ . '/includes/employee_navbar.php'; ?>
    <div class="container">
        <div class="dashboard-header">
            <h2>Assigned Support Tasks</h2>
            <p class="text-muted">Update ticket progress and resolution results.</p>
        </div>

        <?php if($error): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>
        <?php if($success): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>

        <div class="card">
            <h3>My ticket queue</h3>
            <?php if(!$tickets): ?>
                <p class="text-muted mb-0">No support tasks assigned to you.</p>
            <?php else: ?>
            <table class="table">
                <thead><tr><th>ID</th><th>Order</th><th>Client</th><th>Issue</th><th>Status</th><th>Update</th></tr></thead>
                <tbody>
                <?php foreach($tickets as $ticket): ?>
                    <tr>
                        <td>#<?php echo (int) $ticket['id']; ?></td>
                        <td><?php echo htmlspecialchars($ticket['order_number']); ?></td>
                        <td><?php echo htmlspecialchars($ticket['client_name']); ?></td>
                        <td><strong><?php echo htmlspecialchars($ticket['issue_type']); ?></strong><br><small><?php echo htmlspecialchars($ticket['description']); ?></small></td>
                        <td><?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($ticket['status']))); ?></td>
                        <td>
                            <form method="POST" class="d-flex gap-2 align-center">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="ticket_id" value="<?php echo (int) $ticket['id']; ?>">
                                <select name="status" required>
                                    <option value="">Select</option>
                                    <option value="in_progress">In Progress</option>
                                    <option value="resolved">Resolved</option>
                                </select>
                                <button class="btn btn-sm btn-primary" type="submit">Save</button>
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
