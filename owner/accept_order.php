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
    SELECT o.status, o.order_number, o.client_id, o.price, o.payment_status, o.quote_details, o.assigned_to, s.shop_name
    FROM orders o
    JOIN shops s ON o.shop_id = s.id
    WHERE o.id = ? AND o.shop_id = ?
");
$order_stmt->execute([$order_id, $shop['id']]);
$order = $order_stmt->fetch();

if(!$order || order_workflow_normalize_order_status((string) ($order['status'] ?? '')) !== STATUS_PENDING) {
    header("Location: shop_orders.php?filter=pending&action=invalid_transition");
    exit();
}

$estimated_price = null;
$system_generated_estimate = null;
if(!empty($order['quote_details'])) {
    $quote_details = json_decode($order['quote_details'], true);
    if(is_array($quote_details) && isset($quote_details['estimated_total'])) {
        $estimated_total = filter_var($quote_details['estimated_total'], FILTER_VALIDATE_FLOAT);
        if($estimated_total !== false && $estimated_total > 0) {
            $estimated_price = (float) $estimated_total;
        }
    }
    
    if(is_array($quote_details)) {
        $system_candidates = [
            $quote_details['system_suggested_price'] ?? null,
            $quote_details['auto_pricing']['pricing']['total_price'] ?? null,
            $quote_details['client_estimate']['estimated_price'] ?? null,
        ];

        foreach($system_candidates as $candidate) {
            $candidate_value = filter_var($candidate, FILTER_VALIDATE_FLOAT);
            if($candidate_value !== false && $candidate_value > 0) {
                $system_generated_estimate = (float) $candidate_value;
                break;
            }
        }
    }
}

$stored_price = $order['price'];
$has_stored_price = $stored_price !== null && $stored_price !== '' && (float) $stored_price > 0;
$final_price = $has_stored_price ? (float) $stored_price : ($estimated_price ?? $system_generated_estimate);

if($final_price !== null) {
    $price_stmt = $pdo->prepare("UPDATE orders SET price = ?, updated_at = NOW() WHERE id = ? AND shop_id = ?");
    $price_stmt->execute([$final_price, $order_id, $shop['id']]);
}

$quote_stmt = $pdo->prepare("\n    UPDATE orders\n    SET quote_status = 'sent', updated_at = NOW()\n    WHERE id = ? AND shop_id = ?\n");
$quote_stmt->execute([$order_id, $shop['id']]);

record_order_progress_log_once(
    $pdo,
    $order_id,
    STATUS_PENDING,
    'Owner issued quote',
    'Owner issued or updated quote. Waiting for client approval and required payment.',
    $owner_id
);

automation_sync_invoice_for_order($pdo, $order_id);

if(function_exists('maybe_auto_assign_order')) {
    maybe_auto_assign_order($pdo, $order_id, $owner_id);
}

notify_business_event(
    $pdo,
    'order_quote_issued',
    $order_id,
    [
        'client_message' => sprintf('Quote was issued for order #%s by %s. Please review and approve to continue.', $order['order_number'], $order['shop_name']),
        'actor_id' => $owner_id,
    ]
);

messaging_auto_thread_on_order_accepted($pdo, $order_id, $owner_id);

automation_log_audit_if_available(
    $pdo,
    $owner_id,
    $owner_role,
    'order_quote_issued',
    'orders',
    $order_id,
    [
        'status' => $order['status'] ?? null,
        'price' => $stored_price !== null ? (float) $stored_price : null,
    ],
    [
        'status' => STATUS_PENDING,
        'quote_status' => 'sent',
        'price' => $final_price,
    ]
);

header("Location: shop_orders.php?filter=pending&action=quote_issued");
exit();