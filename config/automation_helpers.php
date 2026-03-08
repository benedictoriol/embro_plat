<?php
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/order_workflow.php';
require_once __DIR__ . '/order_helpers.php';
require_once __DIR__ . '/payment_helpers.php';
require_once __DIR__ . '/notification_functions.php';
require_once __DIR__ . '/assignment_helpers.php';

function get_order_progress_for_status(string $status, ?string $fulfillment_status = null): int {
    return order_workflow_display_progress($status, 0, $fulfillment_status);
}

function automation_update_order_status(PDO $pdo, int $order_id, string $next_status, ?int $staff_id = null, ?string $notes = null): array {    
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

        record_order_status_history($pdo, $order_id, $next_status, $progress, $notes, $staff_id);
        
        if(in_array($next_status, [STATUS_ACCEPTED, STATUS_DIGITIZING, STATUS_IN_PROGRESS], true) && empty($order['assigned_to'])) {
            $assigned_by = $staff_id;
            if($assigned_by === null || $assigned_by <= 0) {
                $owner_stmt = $pdo->prepare("SELECT owner_id FROM shops WHERE id = ? LIMIT 1");
                $owner_stmt->execute([(int) ($order['shop_id'] ?? 0)]);
                $assigned_by = (int) ($owner_stmt->fetchColumn() ?: 0);
            }

            $best_assignee = choose_best_staff_assignee($pdo, $order_id);
            if($best_assignee && $assigned_by > 0) {
                assign_order_to_staff(
                    $pdo,
                    $order_id,
                    (int) $best_assignee['user_id'],
                    $assigned_by
                );
            }
        }
    } catch(PDOException $e) {
        return [false, 'Failed to update order status.'];
    }

    return [true, null];
}

function automation_notify_order_parties(PDO $pdo, int $order_id, string $type, string $client_message, ?string $owner_message = null, ?int $extra_user_id = null, ?string $extra_message = null): void {
    if($order_id <= 0 || ($client_message === '' && $owner_message === null && $extra_message === null)) {
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

function automation_log_inventory_transaction_once(
    PDO $pdo,
    int $shop_id,
    int $order_id,
    string $event_ref_type,
    string $transaction_type,
    float $qty = 1.0,
    ?int $material_id = null
): array {
    if($shop_id <= 0 || $order_id <= 0 || $event_ref_type === '') {
        return [false, 'Invalid inventory transaction context.', false];
    }

    $allowed_types = ['issue', 'return', 'adjust', 'move', 'in', 'out'];
    if(!in_array($transaction_type, $allowed_types, true)) {
        return [false, 'Invalid inventory transaction type.', false];
    }

    if($material_id === null || $material_id <= 0) {
        $material_stmt = $pdo->prepare("SELECT id FROM raw_materials WHERE shop_id = ? ORDER BY id ASC LIMIT 1");
        $material_stmt->execute([$shop_id]);
        $material_id = $material_stmt->fetchColumn() ?: null;
    }

    if($material_id === null || $material_id <= 0) {
        return [false, null, false];
    }

    $exists_stmt = $pdo->prepare("
        SELECT id
        FROM inventory_transactions
        WHERE shop_id = ? AND ref_type = ? AND ref_id = ? AND type = ?
        LIMIT 1
    ");
    $exists_stmt->execute([$shop_id, $event_ref_type, $order_id, $transaction_type]);
    if($exists_stmt->fetchColumn()) {
        return [true, null, false];
    }

    $normalized_qty = abs($qty);
    if(in_array($transaction_type, ['issue', 'out'], true)) {
        $normalized_qty *= -1;
    }

    try {
        $insert_stmt = $pdo->prepare("
            INSERT INTO inventory_transactions (shop_id, material_id, type, qty, ref_type, ref_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $insert_stmt->execute([
            $shop_id,
            $material_id,
            $transaction_type,
            $normalized_qty,
            $event_ref_type,
            $order_id,
        ]);
    } catch(Throwable $e) {
        return [false, 'Failed to log inventory transaction.', false];
    }

    return [true, null, true];
}

function automation_estimate_thread_length_m(int $stitch_count): float {
    $safe_stitches = max(0, $stitch_count);
    if($safe_stitches <= 0) {
        return 0.0;
    }

    $thread_length_mm = $safe_stitches * 3;
    return round($thread_length_mm / 1000, 2);
}

function automation_resolve_order_thread_requirement(PDO $pdo, int $order_id, int $order_qty = 1): array {
    $safe_order_id = max(0, $order_id);
    $safe_qty = max(1, $order_qty);
    if($safe_order_id <= 0) {
        return [false, 'Invalid order id.', null];
    }

    $design_stmt = $pdo->prepare("SELECT id, stitch_count, estimated_thread_length FROM digitized_designs WHERE order_id = ? ORDER BY id DESC LIMIT 1");
    $design_stmt->execute([$safe_order_id]);
    $design = $design_stmt->fetch();

    if(!$design) {
        return [false, 'No digitized design found for this order.', null];
    }

    $stitch_count = max(0, (int) ($design['stitch_count'] ?? 0));
    if($stitch_count <= 0) {
        return [false, 'Missing stitch count for thread consumption estimate.', null];
    }

    $estimated_per_item_m = automation_estimate_thread_length_m($stitch_count);
    if($estimated_per_item_m <= 0) {
        return [false, 'Unable to estimate thread length for the order.', null];
    }

    if((float) ($design['estimated_thread_length'] ?? 0) <= 0) {
        $update_stmt = $pdo->prepare("UPDATE digitized_designs SET estimated_thread_length = ? WHERE id = ?");
        $update_stmt->execute([$estimated_per_item_m, (int) $design['id']]);
    }

    $total_required_m = round($estimated_per_item_m * $safe_qty, 2);

    return [
        true,
        null,
        [
            'design_id' => (int) $design['id'],
            'stitch_count' => $stitch_count,
            'estimated_thread_length_per_item_m' => $estimated_per_item_m,
            'estimated_thread_length_total_m' => $total_required_m,
            'order_quantity' => $safe_qty,
        ],
    ];
}

function automation_consume_thread_inventory_on_production_start(PDO $pdo, int $shop_id, int $order_id, int $order_qty = 1): array {
    $safe_shop_id = max(0, $shop_id);
    $safe_order_id = max(0, $order_id);
    if($safe_shop_id <= 0 || $safe_order_id <= 0) {
        return [false, 'Invalid production consumption context.', null];
    }

    [$requirement_ok, $requirement_error, $requirement] = automation_resolve_order_thread_requirement($pdo, $safe_order_id, $order_qty);
    if(!$requirement_ok) {
        return [false, $requirement_error, null];
    }

    $required_qty = (float) ($requirement['estimated_thread_length_total_m'] ?? 0);
    if($required_qty <= 0) {
        return [false, 'Estimated thread requirement is zero.', null];
    }

    $material_stmt = $pdo->prepare("\n        SELECT id, name, category, current_stock\n        FROM raw_materials\n        WHERE shop_id = ?\n          AND status = 'active'\n          AND (LOWER(COALESCE(category, '')) LIKE '%thread%' OR LOWER(name) LIKE '%thread%')\n        ORDER BY id ASC\n        LIMIT 1\n    ");
    $material_stmt->execute([$safe_shop_id]);
    $material = $material_stmt->fetch();

    if(!$material) {
        return [false, 'No active thread material found in inventory.', null];
    }

    $existing_stmt = $pdo->prepare("\n        SELECT id\n        FROM inventory_transactions\n        WHERE shop_id = ? AND ref_type = 'thread_consumption' AND ref_id = ? AND type = 'issue'\n        LIMIT 1\n    ");
    $existing_stmt->execute([$safe_shop_id, $safe_order_id]);
    if($existing_stmt->fetchColumn()) {
        return [true, null, ['already_logged' => true, 'required_qty_m' => $required_qty, 'material_id' => (int) $material['id']]];
    }

    try {
        $pdo->beginTransaction();

        $lock_stmt = $pdo->prepare("SELECT current_stock FROM raw_materials WHERE id = ? AND shop_id = ? FOR UPDATE");
        $lock_stmt->execute([(int) $material['id'], $safe_shop_id]);
        $locked_stock = $lock_stmt->fetchColumn();
        if($locked_stock === false) {
            $pdo->rollBack();
            return [false, 'Unable to lock thread inventory record.', null];
        }

        $current_stock = (float) $locked_stock;
        if($current_stock < $required_qty) {
            $pdo->rollBack();
            return [false, 'Insufficient thread stock. Required ' . number_format($required_qty, 2) . ' m, available ' . number_format($current_stock, 2) . ' m.', [
                'required_qty_m' => $required_qty,
                'available_qty_m' => $current_stock,
                'material_id' => (int) $material['id'],
            ]];
        }

        $update_stock_stmt = $pdo->prepare("UPDATE raw_materials SET current_stock = current_stock - ? WHERE id = ? AND shop_id = ?");
        $update_stock_stmt->execute([$required_qty, (int) $material['id'], $safe_shop_id]);

        $insert_stmt = $pdo->prepare("\n            INSERT INTO inventory_transactions (shop_id, material_id, type, qty, ref_type, ref_id)\n            VALUES (?, ?, 'issue', ?, 'thread_consumption', ?)\n        ");
        $insert_stmt->execute([
            $safe_shop_id,
            (int) $material['id'],
            -$required_qty,
            $safe_order_id,
        ]);

        $pdo->commit();
    } catch(Throwable $e) {
        if($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [false, 'Failed to deduct thread inventory.', null];
    }

    return [
        true,
        null,
        [
            'already_logged' => false,
            'required_qty_m' => $required_qty,
            'material_id' => (int) $material['id'],
        ],
    ];
}

function automation_ensure_finished_goods_record(PDO $pdo, int $order_id, int $shop_id, ?int $storage_location_id = null, string $status = 'stored'): array {
    if($order_id <= 0 || $shop_id <= 0) {
        return [false, 'Invalid order or shop id.', null, false];
    }

    $existing_stmt = $pdo->prepare("SELECT id FROM finished_goods WHERE order_id = ? LIMIT 1");
    $existing_stmt->execute([$order_id]);
    $existing_id = $existing_stmt->fetchColumn();
    if($existing_id) {
        return [true, null, (int) $existing_id, false];
    }

    $resolved_location_id = null;
    if($storage_location_id !== null && $storage_location_id > 0) {
        $location_stmt = $pdo->prepare("SELECT id FROM storage_locations WHERE id = ? AND shop_id = ? LIMIT 1");
        $location_stmt->execute([$storage_location_id, $shop_id]);
        $resolved_location_id = $location_stmt->fetchColumn();
    }

    if(!$resolved_location_id) {
        $fallback_location_stmt = $pdo->prepare("SELECT id FROM storage_locations WHERE shop_id = ? ORDER BY id ASC LIMIT 1");
        $fallback_location_stmt->execute([$shop_id]);
        $resolved_location_id = $fallback_location_stmt->fetchColumn() ?: null;
    }

    try {
        $insert_stmt = $pdo->prepare("
            INSERT INTO finished_goods (order_id, shop_id, storage_location_id, status, stored_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $insert_stmt->execute([
            $order_id,
            $shop_id,
            $resolved_location_id,
            $status,
        ]);
    } catch(Throwable $e) {
        return [false, 'Failed to create finished goods record.', null, false];
    }

    return [true, null, (int) $pdo->lastInsertId(), true];
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
    $next_status = strtolower(trim((string) ($payload['status'] ?? FULFILLMENT_PENDING)));
    $courier = $payload['courier'] ?? null;
    $tracking_number = $payload['tracking_number'] ?? null;
    $pickup_location = $payload['pickup_location'] ?? null;
    $notes = $payload['notes'] ?? null;

    $existing_stmt = $pdo->prepare("SELECT * FROM order_fulfillments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
    $existing_stmt->execute([$order_id]);
    $existing = $existing_stmt->fetch();

    $current_status = strtolower(trim((string) ($existing['status'] ?? FULFILLMENT_PENDING)));
    [$can_transition, $transition_error] = order_workflow_validate_fulfillment_status(
        $pdo,
        $order_id,
        $next_status,
        $current_status
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
            $owner_message = sprintf(
                'Fulfillment update for order #%s: %s.',
                (string) ($order['order_number'] ?? $order_id),
                strtolower(str_replace('_', ' ', $next_status))
            );
            automation_notify_order_parties($pdo, $order_id, 'order_status', $message, $owner_message);
        }

        if(in_array($next_status, [FULFILLMENT_DELIVERED, FULFILLMENT_CLAIMED], true)) {
            $progress = order_workflow_display_progress(
                (string) ($order['status'] ?? STATUS_COMPLETED),
                (int) ($order['progress'] ?? 0),
                $next_status
            );
            $progress_stmt = $pdo->prepare("UPDATE orders SET progress = ?, updated_at = NOW() WHERE id = ?");
            $progress_stmt->execute([$progress, $order_id]);
            
            $entered_reviewable_fulfillment = !in_array($current_status, [FULFILLMENT_DELIVERED, FULFILLMENT_CLAIMED], true);
            $order_is_completed = (($order['status'] ?? null) === STATUS_COMPLETED);

            if($entered_reviewable_fulfillment && $order_is_completed && !empty($order['client_id'])) {
                $review_message = sprintf(
                    'Your order #%s is complete. You can now rate this shop.',
                    (string) ($order['order_number'] ?? $order_id)
                );
                create_notification_once_for_order(
                    $pdo,
                    (int) $order['client_id'],
                    $order_id,
                    'rating_request',
                    $review_message
                );
            }
        }

        automation_log_audit_if_available(
            $pdo,
            $actor_user_id,
            $actor_role,
            'fulfillment_status_changed',
            'order_fulfillments',
            $fulfillment_id,
            [
                'order_id' => $order_id,
                'fulfillment_type' => $existing['fulfillment_type'] ?? null,
                'status' => $current_status,
            ],
            [
                'order_id' => $order_id,
                'status' => $next_status,
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
