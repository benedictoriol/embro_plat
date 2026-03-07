<?php
session_start();
require_once '../config/db.php';
require_once '../config/automation_helpers.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$owner_role = $_SESSION['user']['role'] ?? null;
$order_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if($order_id <= 0) {
    header("Location: shop_orders.php");
    exit();
}

$shop_stmt = $pdo->prepare("SELECT id FROM shops WHERE owner_id = ?");
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch();

if(!$shop) {
    header("Location: create_shop.php");
    exit();
}

$order_stmt = $pdo->prepare("
    SELECT o.status, o.order_number, o.client_id, o.price, o.payment_status, o.quote_details, s.shop_name
    FROM orders o
    JOIN shops s ON o.shop_id = s.id
    WHERE o.id = ? AND o.shop_id = ?
");
$order_stmt->execute([$order_id, $shop['id']]);
$order = $order_stmt->fetch();

if(!$order || $order['status'] !== 'pending') {
    header("Location: shop_orders.php?filter=pending&action=invalid_transition");
    exit();
}

$estimated_price = null;
if(!empty($order['quote_details'])) {
    $quote_details = json_decode($order['quote_details'], true);
    if(is_array($quote_details) && isset($quote_details['estimated_total'])) {
        $estimated_total = filter_var($quote_details['estimated_total'], FILTER_VALIDATE_FLOAT);
        if($estimated_total !== false && $estimated_total > 0) {
            $estimated_price = (float) $estimated_total;
        }
    }
}

$stored_price = $order['price'];
$has_stored_price = $stored_price !== null && $stored_price !== '';
$final_price = $has_stored_price ? (float) $stored_price : $estimated_price;

if($final_price !== null) {
    $price_stmt = $pdo->prepare("UPDATE orders SET price = ?, updated_at = NOW() WHERE id = ? AND shop_id = ?");
    $price_stmt->execute([$final_price, $order_id, $shop['id']]);
}

[$status_updated, $status_error] = automation_update_order_status(
    $pdo,
    $order_id,
    STATUS_ACCEPTED,
    $owner_id,
    'Order accepted by shop.'
);
if(!$status_updated) {
    header("Location: shop_orders.php?filter=pending&action=invalid_transition");
    exit();
}

automation_sync_invoice_for_order($pdo, $order_id);

automation_notify_order_parties(
    $pdo,
    $order_id,
    'order_status',
    sprintf('Your order #%s has been accepted by %s.', $order['order_number'], $order['shop_name'])
);

automation_log_audit_if_available(
    $pdo,
    $owner_id,
    $owner_role,
    'accept_order',
    'orders',
    $order_id,
    ['status' => $order['status'] ?? null],
    ['status' => 'STATUS_ACCEPTED']
);

header("Location: shop_orders.php?filter=accepted&action=accepted");
exit();
