<?php
session_start();
require_once '../config/db.php';
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
    SELECT o.status, o.order_number, o.client_id, s.shop_name
    FROM orders o
    JOIN shops s ON o.shop_id = s.id
    WHERE o.id = ? AND o.shop_id = ?
");
$order_stmt->execute([$order_id, $shop['id']]);
$order = $order_stmt->fetch();

if(!$order || $order['status'] !== 'pending') {
    header("Location: shop_orders.php?filter=pending");
    exit();
}

$update_stmt = $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ? AND shop_id = ?");
$update_stmt->execute([$order_id, $shop['id']]);

if($order) {
    $message = sprintf(
        'Your order #%s has been cancelled by %s.',
        $order['order_number'],
        $order['shop_name']
    );
    create_notification($pdo, (int) $order['client_id'], $order_id, 'order_status', $message);
}

create_notification(
    $pdo,
    (int) $order['client_id'],
    $order_id,
    'warning',
    'Your order #' . $order['order_number'] . ' was not accepted by the shop. Please place a new request or contact the shop.'
);


log_audit(
    $pdo,
    $owner_id,
    $owner_role,
    'reject_order',
    'orders',
    $order_id,
    ['status' => $order['status'] ?? null],
    ['status' => 'cancelled']
);

header("Location: shop_orders.php?filter=cancelled&action=rejected");
exit();
