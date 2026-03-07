<?php
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/order_workflow.php';
require_once __DIR__ . '/order_helpers.php';
require_once __DIR__ . '/payment_helpers.php';
require_once __DIR__ . '/notification_functions.php';

function get_order_progress_for_status(string $status, ?string $fulfillment_status = null): int {
    return order_workflow_display_progress($status, 0, $fulfillment_status);
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

function automation_fulfillment_status_message(string $order_number, string $next_status, string $fulfillment_type): string {
    $channel = strtolower(trim($fulfillment_type)) === 'pickup' ? 'pickup' : 'delivery';

    return match($next_status) {
        FULFILLMENT_READY_FOR_PICKUP => 'Order #' . $order_number . ' is ready for pickup.',
        FULFILLMENT_OUT_FOR_DELIVERY => 'Order #' . $order_number . ' is now out for delivery.',
        FULFILLMENT_DELIVERED => 'Order #' . $order_number . ' has been marked as delivered.',
        FULFILLMENT_CLAIMED => 'Order #' . $order_number . ' has been marked as claimed.',
        FULFILLMENT_FAILED => 'We were unable to complete the ' . $channel . ' attempt for order #' . $order_number . '.',
        default => 'Order #' . $order_number . ' fulfillment is currently pending.',
    };
}

function automation_upsert_order_fulfillment(PDO $pdo, array $order, array $payload, int $actor_user_id, ?string $actor_role = null): array {
    $order_id = (int) ($order['id'] ?? 0);
    if($order_id <= 0) {
        return [false, 'Invalid order id.', null];
    }

    $fulfillment_type = (string) ($payload['fulfillment_type'] ?? 'pickup');
    $next_status = (string) ($payload['status'] ?? FULFILLMENT_PENDING);
    $courier = $payload['courier'] ?? null;
    $tracking_number = $payload['tracking_number'] ?? null;
    $pickup_location = $payload['pickup_location'] ?? null;
    $notes = $payload['notes'] ?? null;

    $existing_stmt = $pdo->prepare("SELECT * FROM order_fulfillments WHERE order_id = ? LIMIT 1");
    $existing_stmt->execute([$order_id]);
    $existing = $existing_stmt->fetch();

    $current_status = (string) ($existing['status'] ?? FULFILLMENT_PENDING);
    [$can_transition, $transition_error] = order_workflow_validate_fulfillment_status(
        $pdo,
        $order_id,
        $current_status,
        $next_status
    );
    if(!$can_transition) {
        return [false, $transition_error ?: 'Status transition is not allowed from the current state.', null];
    }

    $ready_at = $existing['ready_at'] ?? null;
    $delivered_at = $existing['delivered_at'] ?? null;
    $claimed_at = $existing['claimed_at'] ?? null;
    $now = date('Y-m-d H:i:s');

    if($next_status === FULFILLMENT_READY_FOR_PICKUP && !$ready_at) {
        $ready_at = $now;
    }
    if($next_status === FULFILLMENT_DELIVERED && !$delivered_at) {
        $delivered_at = $now;
    }
    if($next_status === FULFILLMENT_CLAIMED && !$claimed_at) {
        $claimed_at = $now;
    }

    try {
        $pdo->beginTransaction();

        if($existing) {
            $update_stmt = $pdo->prepare("
                UPDATE order_fulfillments
                SET fulfillment_type = ?,
                    status = ?,
                    courier = ?,
                    tracking_number = ?,
                    pickup_location = ?,
                    notes = ?,
                    ready_at = ?,
                    delivered_at = ?,
                    claimed_at = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $update_stmt->execute([
                $fulfillment_type,
                $next_status,
                $courier ?: null,
                $tracking_number ?: null,
                $pickup_location ?: null,
                $notes,
                $ready_at,
                $delivered_at,
                $claimed_at,
                $existing['id'],
            ]);
            $fulfillment_id = (int) $existing['id'];
        } else {
            $insert_stmt = $pdo->prepare("
                INSERT INTO order_fulfillments
                    (order_id, fulfillment_type, status, courier, tracking_number, pickup_location, notes, ready_at, delivered_at, claimed_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insert_stmt->execute([
                $order_id,
                $fulfillment_type,
                $next_status,
                $courier ?: null,
                $tracking_number ?: null,
                $pickup_location ?: null,
                $notes,
                $ready_at,
                $delivered_at,
                $claimed_at,
            ]);
            $fulfillment_id = (int) $pdo->lastInsertId();
        }

        if(!$existing || $next_status !== $current_status) {
            $history_stmt = $pdo->prepare("
                INSERT INTO order_fulfillment_history (fulfillment_id, status, notes)
                VALUES (?, ?, ?)
            ");
            $history_stmt->execute([$fulfillment_id, $next_status, $notes]);

            $message = automation_fulfillment_status_message(
                (string) ($order['order_number'] ?? $order_id),
                $next_status,
                $fulfillment_type
            );
            create_notification($pdo, (int) $order['client_id'], $order_id, 'info', $message);
        }

        if(in_array($next_status, [FULFILLMENT_DELIVERED, FULFILLMENT_CLAIMED], true)) {
            $progress = order_workflow_display_progress(
                (string) ($order['status'] ?? STATUS_COMPLETED),
                (int) ($order['progress'] ?? 0),
                $next_status
            );
            $progress_stmt = $pdo->prepare("UPDATE orders SET progress = ?, updated_at = NOW() WHERE id = ?");
            $progress_stmt->execute([$progress, $order_id]);
        }

        automation_log_audit_if_available(
            $pdo,
            $actor_user_id,
            $actor_role,
            'update_fulfillment_status',
            'order_fulfillments',
            $order_id,
            $existing ?: ['status' => FULFILLMENT_PENDING],
            [
                'fulfillment_type' => $fulfillment_type,
                'status' => $next_status,
                'courier' => $courier ?: null,
                'tracking_number' => $tracking_number ?: null,
                'pickup_location' => $pickup_location ?: null,
                'notes' => $notes,
                'ready_at' => $ready_at,
                'delivered_at' => $delivered_at,
                'claimed_at' => $claimed_at,
            ]
        );

        $pdo->commit();
    } catch(Throwable $e) {
        if($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [false, 'Failed to save fulfillment update.', null];
    }

    return [true, null, ['fulfillment_id' => $fulfillment_id, 'status' => $next_status]];
}
?>
