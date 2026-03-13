<?php
session_start();
require_once '../config/db.php';
require_once '../config/automation_helpers.php';
require_once '../config/domain_services.php';
require_role('client');

$client_id = (int) ($_SESSION['user']['id'] ?? 0);
$order_id = (int) ($_GET['order_id'] ?? $_POST['order_id'] ?? 0);

if($order_id <= 0) {
    header('Location: track_order.php');
    exit;
}

$order_stmt = $pdo->prepare("SELECT o.*, s.shop_name, s.logo FROM orders o JOIN shops s ON s.id=o.shop_id WHERE o.id=? AND o.client_id=? LIMIT 1");
$order_stmt->execute([$order_id, $client_id]);
$order = $order_stmt->fetch(PDO::FETCH_ASSOC);

if(!$order) {
    header('Location: track_order.php');
    exit;
}

$quote = quote_get_latest_for_order($pdo, $order_id);

if($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if($action === 'approve_quote') {
        [$ok, $err] = QuoteService::clientRespond($pdo, $order_id, $client_id, 'approved', $_SESSION['user']['role'] ?? null);
        if($ok) {
            $success = 'Quote approved. Order can now proceed to acceptance workflow.';
        } else {
            $error = $err ?: 'Unable to approve quote.';
        }
    } elseif($action === 'reject_quote') {
        [$ok, $err] = QuoteService::clientRespond($pdo, $order_id, $client_id, 'rejected', $_SESSION['user']['role'] ?? null);
        if($ok) {
            $success = 'Quote rejected and order returned for revision/review.';
        } else {
            $error = $err ?: 'Unable to reject quote.';
        }
    }

    $quote = quote_get_latest_for_order($pdo, $order_id);
    $order_stmt->execute([$order_id, $client_id]);
    $order = $order_stmt->fetch(PDO::FETCH_ASSOC);
}

$can_act = $quote && ($quote['status'] ?? '') === 'sent';
$qty_breakdown = [];
if(!empty($quote['quantity_breakdown'])) {
    $decoded = json_decode((string) $quote['quantity_breakdown'], true);
    $qty_breakdown = is_array($decoded) ? $decoded : [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pricing Quotation</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/includes/customer_navbar.php'; ?>
<div class="container py-4">
    <h2>Quotation #<?php echo htmlspecialchars($order['order_number']); ?></h2>
    <p class="text-muted">Shop: <?php echo htmlspecialchars($order['shop_name']); ?></p>

    <?php if(isset($error)): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if(isset($success)): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

    <?php if(!$quote): ?>
        <div class="card p-3">No quote has been created yet.</div>
    <?php else: ?>
        <div class="card p-3">
            <div><strong>Status:</strong> <?php echo htmlspecialchars(ucfirst($quote['status'])); ?></div>
            <div><strong>Quoted price:</strong> ₱<?php echo number_format((float) $quote['quoted_price'], 2); ?></div>
            <div><strong>Base price:</strong> <?php echo $quote['base_price'] !== null ? '₱'.number_format((float)$quote['base_price'], 2) : 'N/A'; ?></div>
            <div><strong>Design adj:</strong> <?php echo $quote['design_adjustment'] !== null ? '₱'.number_format((float)$quote['design_adjustment'], 2) : 'N/A'; ?></div>
            <div><strong>Stitch adj:</strong> <?php echo $quote['stitch_adjustment'] !== null ? '₱'.number_format((float)$quote['stitch_adjustment'], 2) : 'N/A'; ?></div>
            <div><strong>Size adj:</strong> <?php echo $quote['size_adjustment'] !== null ? '₱'.number_format((float)$quote['size_adjustment'], 2) : 'N/A'; ?></div>
            <div><strong>Rush fee:</strong> <?php echo $quote['rush_fee'] !== null ? '₱'.number_format((float)$quote['rush_fee'], 2) : 'N/A'; ?></div>
            <div><strong>Expires:</strong> <?php echo !empty($quote['expires_at']) ? htmlspecialchars($quote['expires_at']) : 'N/A'; ?></div>
            <div><strong>Notes/Terms:</strong> <?php echo nl2br(htmlspecialchars((string) ($quote['notes_terms'] ?? ''))); ?></div>

            <?php if(!empty($qty_breakdown)): ?>
                <h4 class="mt-2">Quantity breakdown</h4>
                <ul>
                    <?php foreach($qty_breakdown as $k => $v): ?>
                        <li><?php echo htmlspecialchars((string) $k); ?>: <?php echo htmlspecialchars(is_scalar($v) ? (string) $v : json_encode($v)); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

<?php if($can_act): ?>
            <div class="d-flex gap-2 mt-3">
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="order_id" value="<?php echo (int) $order_id; ?>">
                    <input type="hidden" name="action" value="approve_quote">
                    <button class="btn btn-primary" type="submit">Approve Quote</button>
                </form>
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="order_id" value="<?php echo (int) $order_id; ?>">
                    <input type="hidden" name="action" value="reject_quote">
                    <button class="btn btn-danger" type="submit">Reject Quote</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>