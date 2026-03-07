<?php
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/order_workflow.php';
require_once __DIR__ . '/order_helpers.php';
require_once __DIR__ . '/payment_helpers.php';
require_once __DIR__ . '/notification_functions.php';

function get_order_progress_for_status(string $status, ?string $fulfillment_status = null): int {
    $normalized_status = strtolower(trim($status));
    $normalized_fulfillment = $fulfillment_status !== null ? strtolower(trim($fulfillment_status)) : null;

    $progress_map = [
        STATUS_PENDING => 10,
        STATUS_ACCEPTED => 25,
        STATUS_IN_PROGRESS => 65,
        STATUS_COMPLETED => 90,
    ];

    if($normalized_status === STATUS_CANCELLED) {
        return 0;
    }

    if(isset($progress_map[$normalized_status])) {
        return $progress_map[$normalized_status];
    }

    if(in_array($normalized_fulfillment, [FULFILLMENT_DELIVERED, FULFILLMENT_CLAIMED], true)) {
        return 90;
    }

    return 0;
}

function automation_update_order_status(PDO $pdo, int $order_id, string $next_status, ?int $staff_id = null, ?string $notes = null, bool $record_history = true): array {
    if($order_id <= 0) {
        return [false, 'Invalid order id.'];
    }

    $order_stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
    $order_stmt->execute([$order_id]);
    $order = $order_stmt->fetch();

    if(!$order) {
        return [false, 'Order not found.'];
    }

    [$is_valid, $validation_error] = order_workflow_validate_order_status($pdo, $order, $next_status);
    if(!$is_valid) {
        return [false, $validation_error ?: 'Status transition not allowed from the current state.'];
    }

    $fulfillment_status = null;
    try {
        $fulfillment_stmt = $pdo->prepare("SELECT status FROM order_fulfillments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
        $fulfillment_stmt->execute([$order_id]);
        $fulfillment_status = $fulfillment_stmt->fetchColumn() ?: null;
    } catch(PDOException $e) {
        $fulfillment_status = null;
    }

    $progress = $next_status === STATUS_CANCELLED
        ? ((isset($order['progress']) && $order['progress'] !== null) ? (int) $order['progress'] : 0)
        : get_order_progress_for_status($next_status, $fulfillment_status);

    try {
        $update_stmt = $pdo->prepare("UPDATE orders SET status = ?, progress = ?, updated_at = NOW() WHERE id = ?");
        $update_stmt->execute([$next_status, $progress, $order_id]);

        if($record_history) {
            record_order_status_history($pdo, $order_id, $next_status, $progress, $notes, $staff_id);
        }
    } catch(PDOException $e) {
        return [false, 'Failed to update order status.'];
    }

    return [true, null];
}

function automation_notify_order_parties(PDO $pdo, int $order_id, string $type, string $client_message, ?string $owner_message = null, ?int $extra_user_id = null, ?string $extra_message = null): void {
    if($order_id <= 0 || $client_message === '') {
        return;
    }

    $order_stmt = $pdo->prepare("
        SELECT o.id, o.client_id, o.order_number, s.owner_id
        FROM orders o
        JOIN shops s ON o.shop_id = s.id
        WHERE o.id = ?
        LIMIT 1
    ");
    $order_stmt->execute([$order_id]);
    $order = $order_stmt->fetch();

    if(!$order) {
        return;
    }

    if(!empty($order['client_id'])) {
        create_notification($pdo, (int) $order['client_id'], $order_id, $type, $client_message);
    }

    if($owner_message !== null && !empty($order['owner_id'])) {
        create_notification($pdo, (int) $order['owner_id'], $order_id, $type, $owner_message);
    }

    if($extra_user_id !== null && $extra_message !== null && $extra_message !== '') {
        create_notification($pdo, $extra_user_id, $order_id, $type, $extra_message);
    }
}

function automation_sync_invoice_for_order(PDO $pdo, int $order_id): void {
    if($order_id <= 0) {
        return;
    }

    $order_stmt = $pdo->prepare("SELECT id, order_number, price, status, payment_status FROM orders WHERE id = ? LIMIT 1");
    $order_stmt->execute([$order_id]);
    $order = $order_stmt->fetch();

    if(!$order) {
        return;
    }

    $price = isset($order['price']) ? (float) $order['price'] : 0.0;
    if($price <= 0) {
        return;
    }

    $invoice_status = determine_invoice_status(
        (string) ($order['status'] ?? STATUS_PENDING),
        (string) ($order['payment_status'] ?? 'unpaid')
    );

    ensure_order_invoice(
        $pdo,
        (int) $order['id'],
        (string) $order['order_number'],
        $price,
        $invoice_status
    );
}

function automation_sync_receipt_for_payment(PDO $pdo, int $payment_id, int $issued_by): void {
    if($payment_id <= 0 || $issued_by <= 0) {
        return;
    }

    $payment_stmt = $pdo->prepare("SELECT id, status, verified_at FROM payments WHERE id = ? LIMIT 1");
    $payment_stmt->execute([$payment_id]);
    $payment = $payment_stmt->fetch();

    if(!$payment || ($payment['status'] ?? '') !== 'verified') {
        return;
    }

    $issued_at = !empty($payment['verified_at']) ? $payment['verified_at'] : date('Y-m-d H:i:s');
    ensure_payment_receipt($pdo, (int) $payment['id'], $issued_by, $issued_at);
}

function automation_sync_payment_hold_state(PDO $pdo, int $order_id): void {
    if($order_id <= 0) {
        return;
    }

    $order_stmt = $pdo->prepare("SELECT status, payment_status FROM orders WHERE id = ? LIMIT 1");
    $order_stmt->execute([$order_id]);
    $order = $order_stmt->fetch();

    if(!$order) {
        return;
    }

    payment_hold_status((string) ($order['status'] ?? STATUS_PENDING), (string) ($order['payment_status'] ?? 'unpaid'));
}

function automation_log_audit_if_available(PDO $pdo, int $actor_user_id, ?string $actor_role, string $action, string $entity, int $entity_id, array $before = [], array $after = []): void {
    if(function_exists('log_audit')) {
        log_audit($pdo, $actor_user_id, $actor_role, $action, $entity, $entity_id, $before, $after);
    }
}
?>
