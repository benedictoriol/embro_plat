<?php
session_start();
require_once '../config/db.php';
require_role('client');

$client_id = (int) $_SESSION['user']['id'];
$dispute_window_days = 7;
$unread_notifications = fetch_unread_notification_count($pdo, $client_id);
$success = '';
$error = '';

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');

    if($action === 'create_ticket') {
        $order_id = (int) ($_POST['order_id'] ?? 0);
        $issue_type = sanitize($_POST['issue_type'] ?? '');
        $description = sanitize($_POST['description'] ?? '');

        $order_stmt = $pdo->prepare(" 
            SELECT o.id, o.order_number, o.status, o.completed_at, s.owner_id
            FROM orders o
            JOIN shops s ON s.id = o.shop_id
            WHERE o.id = ? AND o.client_id = ?
            LIMIT 1
        ");
        $order_stmt->execute([$order_id, $client_id]);
        $order = $order_stmt->fetch();

        if(!$order) {
            $error = 'Order not found.';
        } elseif(($order['status'] ?? '') !== 'completed' || empty($order['completed_at'])) {
            $error = 'Support tickets are available after an order is completed.';
        } elseif(strtotime((string) $order['completed_at'] . ' +' . $dispute_window_days . ' days') < time()) {
            $error = 'The support/dispute window for this completed order has expired.';
        } elseif($issue_type === '' || $description === '') {
            $error = 'Issue type and description are required.';
        } else {
            $create_stmt = $pdo->prepare(" 
                INSERT INTO support_tickets (order_id, client_id, issue_type, description, status)
                VALUES (?, ?, ?, ?, 'open')
            ");
            $create_stmt->execute([$order_id, $client_id, $issue_type, $description]);
            $ticket_id = (int) $pdo->lastInsertId();

            notify_support_ticket_update($pdo, [
                'id' => $ticket_id,
                'order_id' => $order_id,
                'order_number' => $order['order_number'] ?? (string) $order_id,
                'client_id' => $client_id,
                'owner_id' => $order['owner_id'] ?? null,
            ], 'was created by the client', $client_id);

            $success = 'Support ticket created successfully.';
        }
    } elseif($action === 'confirm_resolution') {
        $ticket_id = (int) ($_POST['ticket_id'] ?? 0);

        $ticket_stmt = $pdo->prepare(" 
            SELECT st.id, st.order_id, st.client_id, st.assigned_staff_id, st.issue_type, o.order_number, s.owner_id
            FROM support_tickets st
            JOIN orders o ON o.id = st.order_id
            JOIN shops s ON s.id = o.shop_id
            WHERE st.id = ? AND st.client_id = ? AND st.status = 'resolved'
            LIMIT 1
        ");
        $ticket_stmt->execute([$ticket_id, $client_id]);
        $ticket = $ticket_stmt->fetch();

        if(!$ticket) {
            $error = 'Resolved ticket not found.';
        } else {
            $update_stmt = $pdo->prepare("UPDATE support_tickets SET status = 'closed', resolved_at = COALESCE(resolved_at, NOW()) WHERE id = ?");
            $update_stmt->execute([$ticket_id]);

            notify_support_ticket_update($pdo, $ticket, 'was confirmed as resolved by the client', $client_id);

            if(function_exists('order_exception_resolve')) {
                order_exception_resolve($pdo, (int) ($ticket['order_id'] ?? 0), 'support_unresolved', 'Support ticket closed by client confirmation.', $client_id, 'client');
                if(stripos((string) ($ticket['issue_type'] ?? ''), 'dispute') !== false) {
                    order_exception_resolve($pdo, (int) ($ticket['order_id'] ?? 0), 'dispute_unresolved', 'Dispute ticket closed by client confirmation.', $client_id, 'client');
                }
            }

            $success = 'Ticket marked as closed. Thank you for confirming.';
        }
    }
}

$orders_stmt = $pdo->prepare(" 
    SELECT id, order_number, status, completed_at
    FROM orders
    WHERE client_id = ?
    ORDER BY created_at DESC
");
$orders_stmt->execute([$client_id]);
$orders = $orders_stmt->fetchAll();

$tickets_stmt = $pdo->prepare(" 
    SELECT st.*, o.order_number, u.fullname AS assigned_staff_name
    FROM support_tickets st
    JOIN orders o ON o.id = st.order_id
    LEFT JOIN users u ON u.id = st.assigned_staff_id
    WHERE st.client_id = ?
    ORDER BY st.created_at DESC
");
$tickets_stmt->execute([$client_id]);
$tickets = $tickets_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Support Tickets</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php require_once __DIR__ . '/includes/customer_navbar.php'; ?>
    <div class="container">
        <div class="dashboard-header">
            <h2>Order Support Tickets</h2>
            <p class="text-muted">Open a support ticket for any order and track resolution updates.</p>
        </div>

        <?php if($error): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>
        <?php if($success): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>

        <div class="card mb-4">
            <h3>Create ticket</h3>
            <form method="POST">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="create_ticket">
                <div class="form-group">
                    <label>Order</label>
                    <select name="order_id" required>
                        <option value="">Select order</option>
                        <?php foreach($orders as $order): ?>
                            <?php
                                $is_completed = ($order['status'] ?? '') === 'completed' && !empty($order['completed_at']);
                                $is_open_window = $is_completed && strtotime((string) $order['completed_at'] . ' +' . $dispute_window_days . ' days') >= time();
                            ?>
                            <option value="<?php echo (int) $order['id']; ?>" <?php echo $is_open_window ? '' : 'disabled'; ?>>
                                Order #<?php echo htmlspecialchars($order['order_number']); ?>
                                <?php echo $is_open_window ? '(support window open)' : '(support window closed)'; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Issue Type</label>
                    <input type="text" name="issue_type" required placeholder="e.g. Wrong stitch color">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="4" required></textarea>
                </div>
                <button class="btn btn-primary" type="submit">Submit Ticket</button>
            </form>
        </div>

        <div class="card">
            <h3>Your tickets</h3>
            <?php if(!$tickets): ?>
                <p class="text-muted mb-0">No support tickets yet.</p>
            <?php else: ?>
                <table class="table">
                    <thead><tr><th>ID</th><th>Order</th><th>Issue</th><th>Status</th><th>Assigned Staff</th><th>Created</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach($tickets as $ticket): ?>
                        <tr>
                            <td>#<?php echo (int) $ticket['id']; ?></td>
                            <td><?php echo htmlspecialchars($ticket['order_number']); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($ticket['issue_type']); ?></strong><br>
                                <small><?php echo htmlspecialchars($ticket['description']); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($ticket['status']))); ?></td>
                            <td><?php echo htmlspecialchars($ticket['assigned_staff_name'] ?? 'Unassigned'); ?></td>
                            <td><?php echo date('M d, Y h:i A', strtotime($ticket['created_at'])); ?></td>
                            <td>
                                <?php if($ticket['status'] === 'resolved'): ?>
                                <form method="POST">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="confirm_resolution">
                                    <input type="hidden" name="ticket_id" value="<?php echo (int) $ticket['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-success">Confirm Resolution</button>
                                </form>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
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
