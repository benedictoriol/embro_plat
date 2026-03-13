<?php
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/order_workflow.php';
require_once __DIR__ . '/order_helpers.php';
require_once __DIR__ . '/payment_helpers.php';
require_once __DIR__ . '/notification_functions.php';
require_once __DIR__ . '/assignment_helpers.php';
require_once __DIR__ . '/qc_helpers.php';
require_once __DIR__ . '/scheduling_helpers.php';
require_once __DIR__ . '/inventory_helpers.php';
require_once __DIR__ . '/exception_automation_helpers.php';
require_once __DIR__ . '/../includes/analytics_service.php';

function automation_refresh_shop_metrics_if_available(PDO $pdo): void {
    if (function_exists('refresh_shop_metrics')) {
        refresh_shop_metrics($pdo);
    }
}

function get_order_progress_for_status(string $status, ?string $fulfillment_status = null): int {
    return order_workflow_display_progress($status, 0, $fulfillment_status);
}


function automation_can_start_production_with_materials(PDO $pdo, array $order): array {
    $order_id = (int) ($order['id'] ?? 0);
    $shop_id = (int) ($order['shop_id'] ?? 0);
    $order_qty = max(1, (int) ($order['quantity'] ?? 1));

    if($order_id <= 0 || $shop_id <= 0) {
        return [false, 'Invalid order context for production material checks.'];
    }

    [$requirement_ok, $requirement_error, $requirement] = automation_resolve_order_thread_requirement($pdo, $order_id, $order_qty);
    if(!$requirement_ok) {
        return [false, $requirement_error ?: 'Unable to resolve required production materials.'];
    }

    $required_qty = (float) ($requirement['estimated_thread_length_total_m'] ?? 0.0);
    if($required_qty <= 0) {
        return [false, 'Required thread quantity must be greater than zero before production can start.'];
    }

    ensure_order_material_reservations_table($pdo);

    $reservation_stmt = $pdo->prepare("
        SELECT SUM(CASE WHEN status IN ('reserved', 'consumed') THEN reserved_qty ELSE 0 END) AS reserved_qty
        FROM order_material_reservations
        WHERE order_id = ?
    ");
    $reservation_stmt->execute([$order_id]);
    $reserved_qty = (float) ($reservation_stmt->fetchColumn() ?: 0.0);

    if($reserved_qty >= $required_qty) {
        return [true, null];
    }

    $message = 'Production cannot start because reserved materials are below requirement ('
        . number_format($reserved_qty, 2)
        . ' m reserved vs '
        . number_format($required_qty, 2)
        . ' m required).';

    exception_automation_open($pdo, 'missing_materials', $order_id, [
        'notes' => $message,
        'actor_id' => 0,
        'actor_role' => 'system',
    ]);

    return [false, $message];
}

function automation_ensure_production_task(PDO $pdo, array $order, ?int $actor_user_id = null): array {
    $order_id = (int) ($order['id'] ?? 0);
    $shop_id = (int) ($order['shop_id'] ?? 0);
    $assigned_to = (int) ($order['assigned_to'] ?? 0);

    if($order_id <= 0 || $shop_id <= 0) {
        return [false, 'Invalid production task context.', null];
    }

    $assigned_by = $actor_user_id;
    if($assigned_to <= 0) {
        if($assigned_by === null || $assigned_by <= 0) {
            $owner_stmt = $pdo->prepare("SELECT owner_id FROM shops WHERE id = ? LIMIT 1");
            $owner_stmt->execute([$shop_id]);
            $assigned_by = (int) ($owner_stmt->fetchColumn() ?: 0);
        }

        if($assigned_by > 0) {
            maybe_auto_assign_order($pdo, $order_id, $assigned_by);

            $assignee_stmt = $pdo->prepare("SELECT assigned_to FROM orders WHERE id = ? LIMIT 1");
            $assignee_stmt->execute([$order_id]);
            $assigned_to = (int) ($assignee_stmt->fetchColumn() ?: 0);
        }
    }

    if($assigned_to <= 0) {
        return [false, 'Production task cannot be created until an assignee is available.', null];
    }

    $schedule_stmt = $pdo->prepare("SELECT id FROM job_schedule WHERE order_id = ? AND staff_id = ? ORDER BY id DESC LIMIT 1");
    $schedule_stmt->execute([$order_id, $assigned_to]);
    $schedule_id = (int) ($schedule_stmt->fetchColumn() ?: 0);

    if($schedule_id <= 0) {
        $scheduled_date = !empty($order['scheduled_date']) ? (string) $order['scheduled_date'] : date('Y-m-d');
        $task_description = 'Production task auto-created from workflow readiness.';

        $insert_stmt = $pdo->prepare("
            INSERT INTO job_schedule (order_id, staff_id, scheduled_date, task_description, status)
            VALUES (?, ?, ?, ?, 'scheduled')
        ");
        $insert_stmt->execute([$order_id, $assigned_to, $scheduled_date, $task_description]);
        $schedule_id = (int) $pdo->lastInsertId();
    }

    $machine_assignment = auto_assign_order_to_machine($pdo, $shop_id, $order_id);

    return [true, null, [
        'staff_id' => $assigned_to,
        'job_schedule_id' => $schedule_id,
        'machine_assigned' => (bool) ($machine_assignment['assigned'] ?? false),
        'machine_message' => $machine_assignment['message'] ?? null,
    ]];
}

function automation_transition_order_status(
    PDO $pdo,
    int $order_id,
    string $next_status,
    ?int $actor_user_id = null,
    ?string $actor_role = null,
    ?string $notes = null,
    bool $allow_manual_override = false,
    ?string $audit_action = 'order_status_changed'
): array {
    if($order_id <= 0) {
        return [false, 'Invalid order id.', null];
    }

    $order_stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
    $order_stmt->execute([$order_id]);
    $order = $order_stmt->fetch();

    if(!$order) {
        return [false, 'Order not found.', null];
    }

    $current_status = order_workflow_normalize_order_status((string) ($order['status'] ?? ''));
    $next_status = order_workflow_normalize_order_status($next_status);

    [$is_valid, $validation_error] = order_workflow_validate_order_status($pdo, $order, $next_status, $allow_manual_override);
    if(!$is_valid) {
        return [false, $validation_error ?: 'Status transition not allowed from the current state.', null];
    }

    $order_qty = max(1, (int) ($order['quantity'] ?? 1));
    $shop_id = (int) ($order['shop_id'] ?? 0);

    if(in_array($next_status, [STATUS_ACCEPTED, STATUS_PRODUCTION_PENDING], true)) {
        $strict_reservation = $next_status === STATUS_PRODUCTION_PENDING;
        [$reservation_ok, $reservation_error] = automation_reserve_thread_inventory_for_order(
            $pdo,
            $shop_id,
            $order_id,
            $order_qty,
            $strict_reservation
        );

        if(!$reservation_ok && $strict_reservation) {
            return [false, $reservation_error ?: 'Insufficient inventory to prepare production.', null];
        }
    }

    if($next_status === STATUS_PRODUCTION) {
        [$materials_ready, $materials_error] = automation_can_start_production_with_materials($pdo, $order);
        if(!$materials_ready) {
            return [false, $materials_error ?: 'Required materials are unavailable for production start.', null];
        }
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

        $operational_meta = [];

    try {
        $pdo->beginTransaction();

        $update_stmt = $pdo->prepare("UPDATE orders SET status = ?, progress = ?, updated_at = NOW() WHERE id = ?");
        $update_stmt->execute([$next_status, $progress, $order_id]);

        if(in_array($next_status, [STATUS_PRODUCTION_PENDING, STATUS_PRODUCTION_REWORK], true)) {
            [$task_ok, $task_error, $task_meta] = automation_ensure_production_task($pdo, $order, $actor_user_id);
            if(!$task_ok) {
                return [false, $task_error ?: 'Unable to create production task after readiness.', null];
            }
            $operational_meta['production_task'] = $task_meta;
        }

        if($next_status === STATUS_PRODUCTION) {
            [$inventory_ok, $inventory_error, $inventory_log] = automation_log_production_start_inventory($pdo, $shop_id, $order_id, $order_qty);
            if(!$inventory_ok) {
                return [false, $inventory_error ?: 'Unable to consume reserved materials at production start.', null];
            }
            $operational_meta['production_inventory'] = $inventory_log;

            if(function_exists('order_exception_resolve')) {
                order_exception_resolve($pdo, $order_id, 'materials_unavailable', 'Materials consumed and production started.');
            }
        }

            if($next_status === STATUS_QC_PENDING) {
            [$qc_ok, $qc_error, $qc_id] = qc_create_pending_record($pdo, $order_id, 'Queued for quality control after production completion.');
            if(!$qc_ok) {
                return [false, $qc_error ?: 'Unable to create QC task after production completion.', null];
            }
            $operational_meta['qc_task_id'] = $qc_id;
        }

        if($next_status === STATUS_READY_FOR_DELIVERY) {
            [$fg_ok, $fg_error, $fg_id] = automation_ensure_finished_goods_record($pdo, $order_id, $shop_id, null, 'stored');
            if(!$fg_ok) {
                return [false, $fg_error ?: 'Unable to mark finished goods as ready for fulfillment.', null];
            }
            $operational_meta['finished_goods_id'] = $fg_id;

            if(function_exists('order_exception_resolve')) {
                order_exception_resolve($pdo, $order_id, 'qc_failed', 'QC passed and order is ready for fulfillment.');
            }
        }

        if($next_status === STATUS_PRODUCTION_REWORK && function_exists('order_exception_open')) {
            order_exception_open($pdo, $order_id, 'qc_failed', 'high', 'QC failed and order was moved to production rework.');
        }

        if($next_status === STATUS_COMPLETED) {
            $complete_stmt = $pdo->prepare("UPDATE orders SET completed_at = COALESCE(completed_at, NOW()), updated_at = NOW() WHERE id = ?");
            $complete_stmt->execute([$order_id]);
        }

        record_order_status_history($pdo, $order_id, $next_status, $progress, $notes, $actor_user_id);

        if(function_exists('update_order_estimated_completion')) {
            update_order_estimated_completion($pdo, $order_id);
        }

        if($audit_action !== null && $audit_action !== '') {
            automation_log_audit_if_available(
                $pdo,
                (int) ($actor_user_id ?? 0),
                $actor_role,
                $audit_action,
                'orders',
                $order_id,
                [
                    'status' => $current_status,
                    'progress' => $order['progress'] ?? null,
                ],
                [
                    'status' => $next_status,
                    'progress' => $progress,
                    'manual_override' => $allow_manual_override,
                    'operational_meta' => $operational_meta,
                ]
            );
        }
    
        $pdo->commit();
    } catch(Throwable $e) {
        if($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [false, 'Failed to update order status.', null];
    }

    if(function_exists('sync_production_queue')) {
        sync_production_queue($pdo);
    }

    automation_refresh_shop_metrics_if_available($pdo);

    return [true, null, ['from' => $current_status, 'to' => $next_status, 'progress' => $progress, 'meta' => $operational_meta]];
}

function automation_update_order_status(PDO $pdo, int $order_id, string $next_status, ?int $staff_id = null, ?string $notes = null): array {
    [$ok, $error] = automation_transition_order_status($pdo, $order_id, $next_status, $staff_id, null, $notes, false, null);
    return [$ok, $error];
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

    if(!empty($order['client_id']) && $client_message !== '') {
        create_notification_recent_once_for_order($pdo, (int) $order['client_id'], $order_id, $type, $client_message);
    }

    if($owner_message !== null && $owner_message !== '' && !empty($order['owner_id'])) {
        create_notification_recent_once_for_order($pdo, (int) $order['owner_id'], $order_id, $type, $owner_message);
    }

    if($extra_user_id !== null && $extra_message !== null && $extra_message !== '') {
        create_notification_recent_once_for_order($pdo, $extra_user_id, $order_id, $type, $extra_message);
    }
}


function automation_apply_order_event_transition(
    PDO $pdo,
    int $order_id,
    string $event,
    ?int $actor_user_id = null,
    ?string $actor_role = null,
    bool $allow_manual_override = false,
    ?string $notes = null
): array {
    $event_map = [
        'digitized_uploaded' => STATUS_PRODUCTION_PENDING,
        'production_finished' => STATUS_QC_PENDING,
        'qc_passed' => STATUS_READY_FOR_DELIVERY,
        'qc_failed' => STATUS_PRODUCTION_REWORK,
        'delivery_confirmed' => STATUS_COMPLETED,
    ];

    if(!isset($event_map[$event])) {
        return [false, 'Unsupported workflow event.'];
    }

    $next_status = $event_map[$event];
    $note = $notes ?: ('Workflow auto-transition: ' . str_replace('_', ' ', $event) . '.');
    [$ok, $error] = automation_transition_order_status(
        $pdo,
        $order_id,
        $next_status,
        $actor_user_id,
        $actor_role,
        $note,
        $allow_manual_override,
        'order_workflow_auto_transition'
    );

    if($ok && $event === 'production_finished' && $next_status === STATUS_QC_PENDING) {
        qc_create_pending_record($pdo, $order_id, 'Queued for quality control after production completion.');
    }

    if($ok && $event === 'qc_failed') {
        exception_automation_open($pdo, 'qc_failure', $order_id, [
            'notes' => 'QC failure event triggered automated exception creation.',
            'actor_id' => (int) ($actor_user_id ?? 0),
            'actor_role' => $actor_role ?? 'system',
        ]);
    }

    if($ok && $event === 'qc_passed' && function_exists('order_exception_resolve')) {
        order_exception_resolve($pdo, $order_id, 'qc_failed', 'QC passed through workflow auto-transition.');
    }

    return [$ok, $error, $next_status];
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


function automation_finalize_order_cancellation(PDO $pdo, array $order, int $client_id, string $reason, ?string $actor_role = null): void {
    $order_id = (int) ($order['id'] ?? 0);
    if($order_id <= 0 || $client_id <= 0) {
        return;
    }

    $previous_status = (string) ($order['status'] ?? STATUS_PENDING);
    $progress = (int) ($order['progress'] ?? 0);

    record_order_status_history_once($pdo, $order_id, STATUS_CANCELLED, $progress, $reason, $client_id);
    automation_sync_invoice_for_order($pdo, $order_id);
    automation_sync_payment_hold_state($pdo, $order_id, $client_id, $actor_role, 'order_cancelled');

    notify_order_cancellation_parties(
        $pdo,
        $order_id,
        $client_id,
        isset($order['owner_id']) ? (int) $order['owner_id'] : null,
        (string) ($order['order_number'] ?? '')
    );

    automation_log_audit_if_available(
        $pdo,
        $client_id,
        $actor_role,
        'order_cancelled',
        'orders',
        $order_id,
        [
            'status' => $previous_status,
            'progress' => $order['progress'] ?? null,
            'payment_status' => $order['payment_status'] ?? null,
        ],
        [
            'status' => STATUS_CANCELLED,
            'progress' => $order['progress'] ?? null,
            'payment_status' => $order['payment_status'] ?? null,
            'cancellation_reason' => $reason,
        ]
    );
    
    automation_refresh_shop_metrics_if_available($pdo);
}

function automation_request_refund_for_cancelled_paid_order(PDO $pdo, array $order, int $client_id, string $reason): bool {
    $order_id = (int) ($order['id'] ?? 0);
    if($order_id <= 0 || ($order['payment_status'] ?? 'unpaid') !== 'paid') {
        return false;
    }

    if(!has_pending_refund_request($pdo, $order_id)) {
        $refund_stmt = $pdo->prepare("
            SELECT id, amount FROM payments
            WHERE order_id = ? AND status = 'paid'
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $refund_stmt->execute([$order_id]);
        $payment = $refund_stmt->fetch();

        $refund_amount = (float) ($payment['amount'] ?? $order['price'] ?? 0);
        $refund_insert = $pdo->prepare("
            INSERT INTO payment_refunds (order_id, payment_id, amount, reason, requested_by, status, requested_at)
            VALUES (?, ?, ?, ?, ?, 'pending', NOW())
        ");
        $refund_insert->execute([
            $order_id,
            $payment['id'] ?? null,
            $refund_amount,
            $reason,
            $client_id
        ]);
    }

    $refund_order_stmt = $pdo->prepare("
        UPDATE orders
        SET payment_status = 'partially_paid', updated_at = NOW()
        WHERE id = ? AND client_id = ?
    ");
    $refund_order_stmt->execute([$order_id, $client_id]);

    automation_sync_invoice_for_order($pdo, $order_id);
    automation_sync_payment_hold_state($pdo, $order_id, $client_id, null, 'refund_requested');

    if(!empty($order['owner_id'])) {
        automation_notify_order_parties(
            $pdo,
            $order_id,
            'payment',
            '',
            'Refund requested for order #' . ($order['order_number'] ?? '') . ' after cancellation.'
        );
    }

    automation_refresh_shop_metrics_if_available($pdo);

    return true;
}



function automation_sync_payment_release_state(PDO $pdo, int $order_id, ?int $actor_user_id = null, ?string $actor_role = null, ?string $context = null): array {
    if($order_id <= 0) {
        return [false, 'Invalid order id.', null];
    }

    $order_stmt = $pdo->prepare("
        SELECT id, order_number, client_id, status, payment_status, payment_release_status, payment_released_at
        FROM orders
        WHERE id = ?
        LIMIT 1
    ");
    $order_stmt->execute([$order_id]);
    $order = $order_stmt->fetch();

    if(!$order) {
        return [false, 'Order not found.', null];
    }

    $payment_status = strtolower((string) ($order['payment_status'] ?? 'unpaid'));
    $current_release = strtolower((string) ($order['payment_release_status'] ?? 'none'));
    $order_status = order_workflow_normalize_order_status((string) ($order['status'] ?? STATUS_PENDING));
    $delivery_confirmed = order_workflow_is_delivery_confirmed($pdo, $order_id);

    $target_release = 'none';
    if($payment_status === 'pending_verification') {
        $target_release = 'awaiting_confirmation';
    } elseif($payment_status === 'paid') {
        $target_release = $delivery_confirmed ? 'released' : 'held';
    } elseif($payment_status === 'partially_paid' || $payment_status === 'refunded') {
        $target_release = 'refunded';
    }

    if($order_status === STATUS_CANCELLED && $payment_status !== 'paid') {
        $target_release = $payment_status === 'refunded' ? 'refunded' : 'none';
    }

    if(!can_transition_payment_release_status($current_release, $target_release)) {
        if(can_transition_payment_release_status($current_release, 'release_pending') && $target_release === 'released') {
            $transition_stmt = $pdo->prepare("UPDATE orders SET payment_release_status = 'release_pending', updated_at = NOW() WHERE id = ?");
            $transition_stmt->execute([$order_id]);
            $current_release = 'release_pending';
        } elseif(!can_transition_payment_release_status($current_release, $target_release)) {
            return [false, 'Payment release transition is not allowed.', null];
        }
    }

    if($current_release === $target_release) {
        return [true, null, ['status' => $current_release, 'changed' => false]];
    }

    $released_at = $order['payment_released_at'];
    if($target_release === 'released' && empty($released_at)) {
        $released_at = date('Y-m-d H:i:s');
    }

    if($target_release !== 'released' && $target_release !== 'refunded') {
        $released_at = null;
    }

    $update_stmt = $pdo->prepare("
        UPDATE orders
        SET payment_release_status = ?, payment_released_at = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $update_stmt->execute([$target_release, $released_at, $order_id]);

    automation_log_audit_if_available(
        $pdo,
        (int) ($actor_user_id ?? 0),
        $actor_role,
        'payment_release_status_changed',
        'orders',
        $order_id,
        [
            'payment_status' => $payment_status,
            'payment_release_status' => $current_release,
            'payment_released_at' => $order['payment_released_at'] ?? null,
        ],
        [
            'payment_status' => $payment_status,
            'payment_release_status' => $target_release,
            'payment_released_at' => $released_at,
            'delivery_confirmed' => $delivery_confirmed,
            'context' => $context,
        ]
    );

    if($target_release === 'released') {
        $message = sprintf('Payment for order #%s was released to the shop after fulfillment confirmation.', (string) ($order['order_number'] ?? $order_id));
        notify_business_event(
            $pdo,
            'payment_confirmed',
            $order_id,
            [
                'client_message' => $message,
                'owner_message' => $message,
                'actor_id' => $actor_user_id,
            ]
        );
    }

    return [true, null, ['status' => $target_release, 'changed' => true]];
}
function automation_sync_receipt_for_payment(PDO $pdo, int $payment_id, int $issued_by): void {
    if($payment_id <= 0 || $issued_by <= 0) {
        return;
    }

    $payment_stmt = $pdo->prepare("SELECT id, status, verified_at FROM payments WHERE id = ? LIMIT 1");
    $payment_stmt->execute([$payment_id]);
    $payment = $payment_stmt->fetch();

    if(!$payment || normalize_payment_status((string) ($payment['status'] ?? 'unpaid')) !== 'paid') {
        return;
    }

    $issued_at = !empty($payment['verified_at']) ? $payment['verified_at'] : date('Y-m-d H:i:s');
    ensure_payment_receipt($pdo, (int) $payment['id'], $issued_by, $issued_at);
}

function automation_sync_payment_hold_state(PDO $pdo, int $order_id, ?int $actor_user_id = null, ?string $actor_role = null, ?string $context = null): void {
    if($order_id <= 0) {
        return;
    }

    $order_stmt = $pdo->prepare("SELECT status, payment_status, payment_release_status FROM orders WHERE id = ? LIMIT 1");
    $order_stmt->execute([$order_id]);
    $order = $order_stmt->fetch();

    if(!$order) {
        return;
    }

    payment_hold_status((string) ($order['status'] ?? STATUS_PENDING), (string) ($order['payment_status'] ?? 'unpaid'), (string) ($order['payment_release_status'] ?? 'none'));
    automation_sync_payment_release_state($pdo, $order_id, $actor_user_id, $actor_role, $context);
}

function automation_log_audit_if_available(PDO $pdo, int $actor_user_id, ?string $actor_role, string $action, string $entity, int $entity_id, array $before = [], array $after = [], array $meta = []): void {
    if(function_exists('log_audit')) {
        log_audit($pdo, $actor_user_id, $actor_role, $action, $entity, $entity_id, $before, $after, $meta);
    }
}


function automation_notify_inventory_risk(PDO $pdo, int $shop_id, string $message, ?int $order_id = null): void {
    if($shop_id <= 0 || $message === '') {
        return;
    }

    $owner_stmt = $pdo->prepare("SELECT owner_id FROM shops WHERE id = ? LIMIT 1");
    $owner_stmt->execute([$shop_id]);
    $owner_id = (int) ($owner_stmt->fetchColumn() ?: 0);
    if($owner_id <= 0) {
        return;
    }

    if(!has_recent_notification_by_type_and_message($pdo, $owner_id, 'low_stock', $message, 6)) {
        create_notification($pdo, $owner_id, $order_id, 'low_stock', $message);
    }

    create_low_stock_supplier_drafts($pdo, $owner_id, $shop_id);
}

function automation_reserve_thread_inventory_for_order(PDO $pdo, int $shop_id, int $order_id, int $order_qty = 1, bool $strict = false): array {
    $safe_shop_id = max(0, $shop_id);
    $safe_order_id = max(0, $order_id);
    if($safe_shop_id <= 0 || $safe_order_id <= 0) {
        return [false, 'Invalid inventory reservation context.', null];
    }

    [$req_ok, $req_error, $req] = automation_resolve_order_thread_requirement($pdo, $safe_order_id, max(1, $order_qty));
    if(!$req_ok) {
        if($strict) {
            return [false, $req_error ?: 'Unable to resolve thread requirement.', null];
        }
        return [true, null, ['mode' => 'reservation_skipped', 'reason' => $req_error]];
    }

    $required_qty = (float) ($req['estimated_thread_length_total_m'] ?? 0);
    if($required_qty <= 0) {
        return [true, null, ['mode' => 'reservation_skipped', 'reason' => 'No thread requirement']];
    }

    ensure_order_material_reservations_table($pdo);

    $material_stmt = $pdo->prepare("
        SELECT id, name, current_stock
        FROM raw_materials
        WHERE shop_id = ?
          AND status = 'active'
          AND (LOWER(COALESCE(category, '')) LIKE '%thread%' OR LOWER(name) LIKE '%thread%')
        ORDER BY id ASC
        LIMIT 1
    ");
    $material_stmt->execute([$safe_shop_id]);
    $material = $material_stmt->fetch();
    if(!$material) {
        $error = 'No active thread material found in inventory.';
        if($strict) {
            return [false, $error, null];
        }
        return [true, null, ['mode' => 'reservation_skipped', 'reason' => $error]];
    }

    $existing_stmt = $pdo->prepare("
        SELECT id, reserved_qty, consumed_qty, status
        FROM order_material_reservations
        WHERE order_id = ? AND material_id = ?
        LIMIT 1
    ");
    $existing_stmt->execute([$safe_order_id, (int) $material['id']]);
    $existing = $existing_stmt->fetch();
    if($existing && in_array((string) ($existing['status'] ?? ''), ['reserved', 'consumed'], true)) {
        return [true, null, ['mode' => 'already_reserved', 'required_qty_m' => $required_qty, 'material_id' => (int) $material['id']]];
    }

    try {
        $pdo->beginTransaction();

        $lock_stmt = $pdo->prepare("SELECT current_stock FROM raw_materials WHERE id = ? AND shop_id = ? FOR UPDATE");
        $lock_stmt->execute([(int) $material['id'], $safe_shop_id]);
        $stock = $lock_stmt->fetchColumn();
        if($stock === false) {
            $pdo->rollBack();
            return [false, 'Unable to lock thread inventory record.', null];
        }

        $current_stock = (float) $stock;
        if($current_stock < $required_qty) {
            $pdo->rollBack();
            $message = 'Insufficient thread stock for reservation. Required ' . number_format($required_qty, 2) . ' m, available ' . number_format($current_stock, 2) . ' m.';
            automation_notify_inventory_risk($pdo, $safe_shop_id, $message, $safe_order_id);
            return [false, $message, ['required_qty_m' => $required_qty, 'available_qty_m' => $current_stock, 'material_id' => (int) $material['id']]];
        }

        $update_stock_stmt = $pdo->prepare("UPDATE raw_materials SET current_stock = current_stock - ? WHERE id = ? AND shop_id = ?");
        $update_stock_stmt->execute([$required_qty, (int) $material['id'], $safe_shop_id]);

        if($existing) {
            $reserve_stmt = $pdo->prepare("
                UPDATE order_material_reservations
                SET reserved_qty = ?, consumed_qty = 0, status = 'reserved', notes = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $reserve_stmt->execute([$required_qty, 'Auto-reserved on production preparation.', (int) $existing['id']]);
        } else {
            $reserve_stmt = $pdo->prepare("
                INSERT INTO order_material_reservations (order_id, shop_id, material_id, reserved_qty, consumed_qty, status, notes)
                VALUES (?, ?, ?, ?, 0, 'reserved', ?)
            ");
            $reserve_stmt->execute([$safe_order_id, $safe_shop_id, (int) $material['id'], $required_qty, 'Auto-reserved on production preparation.']);
        }

        $trx_stmt = $pdo->prepare("
            INSERT INTO inventory_transactions (shop_id, material_id, type, qty, ref_type, ref_id)
            VALUES (?, ?, 'issue', ?, 'thread_reservation', ?)
        ");
        $trx_stmt->execute([$safe_shop_id, (int) $material['id'], -$required_qty, $safe_order_id]);

        $pdo->commit();
    } catch(Throwable $e) {
        if($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [false, 'Failed to reserve thread inventory.', null];
    }


    return [true, null, ['mode' => 'reserved', 'required_qty_m' => $required_qty, 'material_id' => (int) $material['id']]];
}

function automation_consume_reserved_inventory_on_production_start(PDO $pdo, int $shop_id, int $order_id): array {
    ensure_order_material_reservations_table($pdo);

    $stmt = $pdo->prepare("
        SELECT *
        FROM order_material_reservations
        WHERE order_id = ? AND shop_id = ? AND status = 'reserved'
        ORDER BY id ASC
    ");
    $stmt->execute([$order_id, $shop_id]);
    $rows = $stmt->fetchAll();
    if(empty($rows)) {
        return [true, null, ['used_reservation' => false]];
    }

    try {
        $pdo->beginTransaction();
        foreach($rows as $row) {
            $consume_qty = max(0.0, (float) ($row['reserved_qty'] ?? 0));
            $update = $pdo->prepare("
                UPDATE order_material_reservations
                SET consumed_qty = ?, status = 'consumed', notes = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $update->execute([$consume_qty, 'Consumed at production start.', (int) $row['id']]);

            $trx = $pdo->prepare("
                INSERT INTO inventory_transactions (shop_id, material_id, type, qty, ref_type, ref_id)
                VALUES (?, ?, 'move', 0, 'reservation_consumed', ?)
            ");
            $trx->execute([(int) $row['shop_id'], (int) $row['material_id'], (int) $row['order_id']]);
        }
        $pdo->commit();
    } catch(Throwable $e) {
        if($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [false, 'Failed to consume reserved inventory.', null];
    }

    return [true, null, ['used_reservation' => true, 'reservation_count' => count($rows)]];
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

function automation_log_production_start_inventory(PDO $pdo, int $shop_id, int $order_id, int $order_qty = 1): array {
    $safe_shop_id = max(0, $shop_id);
    $safe_order_id = max(0, $order_id);
    $safe_qty = max(1, $order_qty);

    if($safe_shop_id <= 0 || $safe_order_id <= 0) {
        return [false, 'Invalid production inventory context.', null];
    }

    [$reserved_ok, $reserved_error, $reserved_log] = automation_consume_reserved_inventory_on_production_start(
        $pdo,
        $safe_shop_id,
        $safe_order_id
    );
    if(!$reserved_ok) {
        return [false, $reserved_error ?: 'Unable to consume reserved inventory.', null];
    }

    if(!empty($reserved_log['used_reservation'])) {
        return [true, null, [
            'mode' => 'reserved_consumption',
            'reservation' => $reserved_log,
            'fallback_transaction_created' => false,
        ]];
    }

    [$thread_ok, $thread_error, $thread_log] = automation_consume_thread_inventory_on_production_start(
        $pdo,
        $safe_shop_id,
        $safe_order_id,
        $safe_qty
    );

    if($thread_ok) {
        return [true, null, [
            'mode' => 'thread_consumption',
            'thread' => $thread_log,
            'fallback_transaction_created' => false,
        ]];
    }

    [$fallback_ok, $fallback_error, $fallback_created] = automation_log_inventory_transaction_once(
        $pdo,
        $safe_shop_id,
        $safe_order_id,
        'production_start_raw_issue',
        'issue',
        0.0
    );

    if(!$fallback_ok) {
        return [false, $fallback_error ?: ($thread_error ?: 'Unable to log production-start inventory transaction.'), null];
    }

    return [true, null, [
        'mode' => 'fallback_issue',
        'thread_error' => $thread_error,
        'fallback_transaction_created' => $fallback_created,
    ]];
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
        FULFILLMENT_OUT_FOR_DELIVERY => 'Order #' . $order_number . ' has been shipped and is now out for delivery.',
        FULFILLMENT_DELIVERED => 'Order #' . $order_number . ' has been delivered.',
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
    $delivery_method = trim((string) ($payload['delivery_method'] ?? ''));
    $courier = $payload['courier'] ?? null;
    $tracking_number = $payload['tracking_number'] ?? null;
    $pickup_location = $payload['pickup_location'] ?? null;
    $notes = $payload['notes'] ?? null;

    if($delivery_method === '') {
        $delivery_method = $fulfillment_type === 'pickup' ? 'store_pickup' : 'courier_delivery';
    }

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

    
    if($next_status === FULFILLMENT_OUT_FOR_DELIVERY && trim((string) $tracking_number) === '' && trim((string) $courier) === '') {
        return [false, 'Tracking reference or courier details are required before marking out for delivery.', null];
    }

    if(in_array($next_status, [FULFILLMENT_DELIVERED, FULFILLMENT_CLAIMED], true) && trim((string) $tracking_number) === '' && trim((string) $notes) === '') {
        return [false, 'Please record proof of handoff (tracking reference or handoff notes).', null];
    }

    if($next_status === FULFILLMENT_CLAIMED && strtolower((string) $actor_role) !== ROLE_CLIENT) {
        return [false, 'Pickup or delivery completion must be confirmed by the client.', null];
    }

    $ready_at = $existing['ready_at'] ?? null;
    $shipped_at = $existing['shipped_at'] ?? null;
    $delivered_at = $existing['delivered_at'] ?? null;
    $claimed_at = $existing['claimed_at'] ?? null;
    $now = date('Y-m-d H:i:s');

    if($next_status === FULFILLMENT_READY_FOR_PICKUP && !$ready_at) {
        $ready_at = $now;
    }
    if($next_status === FULFILLMENT_OUT_FOR_DELIVERY && !$shipped_at) {
        $shipped_at = $now;
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
                    delivery_method = ?,
                    status = ?,
                    courier = ?,
                    tracking_number = ?,
                    pickup_location = ?,
                    notes = ?,
                    ready_at = ?,
                    shipped_at = ?,
                    delivered_at = ?,
                    claimed_at = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $update_stmt->execute([
                $fulfillment_type,
                $delivery_method,
                $next_status,
                $courier ?: null,
                $tracking_number ?: null,
                $pickup_location ?: null,
                $notes,
                $ready_at,
                $shipped_at,
                $delivered_at,
                $claimed_at,
                $existing['id'],
            ]);
            $fulfillment_id = (int) $existing['id'];
        } else {
            $insert_stmt = $pdo->prepare("
                INSERT INTO order_fulfillments
                    (order_id, fulfillment_type, delivery_method, status, courier, tracking_number, pickup_location, notes, ready_at, shipped_at, delivered_at, claimed_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insert_stmt->execute([
                $order_id,
                $fulfillment_type,
                $delivery_method,
                $next_status,
                $courier ?: null,
                $tracking_number ?: null,
                $pickup_location ?: null,
                $notes,
                $ready_at,
                $shipped_at,
                $delivered_at,
                $claimed_at,
            ]);
            $fulfillment_id = (int) $pdo->lastInsertId();
        }

        if(!$existing || $next_status !== $current_status) {
            if($current_status === FULFILLMENT_PENDING && in_array($next_status, [FULFILLMENT_READY_FOR_PICKUP, FULFILLMENT_OUT_FOR_DELIVERY], true)) {
                $readiness_note = $fulfillment_type === 'pickup'
                    ? 'Order handoff details confirmed and marked ready for pickup.'
                    : 'Order handoff details confirmed and marked ready for delivery.';
                record_order_progress_log_once(
                    $pdo,
                    $order_id,
                    STATUS_READY_FOR_DELIVERY,
                    'Ready for delivery',
                    $readiness_note,
                    $actor_user_id
                );
            }

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
            notify_business_event(
                $pdo,
                'order_shipping_update',
                $order_id,
                [
                    'client_message' => $message,
                    'actor_id' => $actor_user_id,
                ]
            );
            if(!empty($order['owner_id'])) {
                create_notification_recent_once_for_order($pdo, (int) $order['owner_id'], $order_id, 'order_status', $owner_message, 30);
            }

            if($next_status === FULFILLMENT_OUT_FOR_DELIVERY) {
                if(function_exists('order_exception_resolve')) {
                    order_exception_resolve($pdo, $order_id, 'delivery_failed', 'Delivery resumed and moved out for delivery.');
                }
                record_order_progress_log_once(
                    $pdo,
                    $order_id,
                    STATUS_READY_FOR_DELIVERY,
                    'Shipped / Out for delivery',
                    'Order has been shipped and is now out for delivery.',
                    $actor_user_id
                );
            } elseif($next_status === FULFILLMENT_READY_FOR_PICKUP) {
                if(function_exists('order_exception_resolve')) {
                    order_exception_resolve($pdo, $order_id, 'delivery_failed', 'Order re-readied for pickup.');
                }
                record_order_progress_log_once(
                    $pdo,
                    $order_id,
                    STATUS_READY_FOR_DELIVERY,
                    'Ready for delivery',
                    'Order is packed and ready for customer pickup.',
                    $actor_user_id
                );
            }
        }

        if($next_status === FULFILLMENT_DELIVERED) {
            if(function_exists('order_exception_resolve')) {
                order_exception_resolve($pdo, $order_id, 'delivery_failed', 'Delivery completed successfully.');
            }
            record_order_progress_log_once(
                $pdo,
                $order_id,
                STATUS_DELIVERED,
                'Delivered',
                'Courier confirmed successful delivery to the client.',
                $actor_user_id
            );
            automation_notify_order_parties(
                $pdo,
                $order_id,
                'order_shipping_update',
                'Your order #' . ((string) ($order['order_number'] ?? $order_id)) . ' was marked delivered. Please confirm receipt to close the order.',
                'Delivery recorded for order #' . ((string) ($order['order_number'] ?? $order_id)) . '. Waiting for client confirmation.'
            );

            [$delivered_ok, $delivered_error] = automation_transition_order_status(
                $pdo,
                $order_id,
                STATUS_DELIVERED,
                $actor_user_id,
                $actor_role,
                'Delivery recorded and awaiting client confirmation.',
                false,
                'order_delivery_marked'
            );
            if(!$delivered_ok) {
                throw new RuntimeException($delivered_error ?: 'Failed to mark order as delivered.');
            }
}

        if($next_status === FULFILLMENT_CLAIMED) {
            if(function_exists('order_exception_resolve')) {
                order_exception_resolve($pdo, $order_id, 'delivery_failed', 'Order handoff successfully confirmed by client.');
            }
            $confirm_stmt = $pdo->prepare("UPDATE orders SET delivery_confirmed_at = COALESCE(delivery_confirmed_at, NOW()), updated_at = NOW() WHERE id = ?");
            $confirm_stmt->execute([$order_id]);

            [$delivered_ok, $delivered_error] = automation_transition_order_status(
                $pdo,
                $order_id,
                STATUS_DELIVERED,
                $actor_user_id,
                $actor_role,
                'Client confirmed handoff.',
                false,
                'order_delivery_confirmed'
            );
            if(!$delivered_ok) {
                throw new RuntimeException($delivered_error ?: 'Failed to mark order as delivered.');
            }

            [$completed_ok, $completed_error] = automation_apply_order_event_transition(
                $pdo,
                $order_id,
                'delivery_confirmed',
                $actor_user_id,
                $actor_role,
                false,
                'Client confirmed handoff and order closure.'
            );
            if(!$completed_ok) {
                throw new RuntimeException($completed_error ?: 'Failed to complete order after client confirmation.');
            }

            $progress = order_workflow_display_progress(STATUS_COMPLETED, 100, $next_status);
            $progress_stmt = $pdo->prepare("UPDATE orders SET progress = ?, completed_at = COALESCE(completed_at, NOW()), updated_at = NOW() WHERE id = ?");
            $progress_stmt->execute([$progress, $order_id]);

            $entered_reviewable_fulfillment = !in_array($current_status, [FULFILLMENT_DELIVERED, FULFILLMENT_CLAIMED], true);
            if($entered_reviewable_fulfillment && !empty($order['client_id'])) {
                $review_message = sprintf(
                    'Your order #%s is complete. You can now rate this shop and open support within the dispute window.',
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

        if($next_status === FULFILLMENT_FAILED && function_exists('order_exception_open')) {
            order_exception_open(
                $pdo,
                $order_id,
                'delivery_failed',
                'high',
                'Delivery/pickup attempt failed and requires follow-up.'
            );
        }

        automation_sync_payment_hold_state($pdo, $order_id, $actor_user_id, $actor_role, 'fulfillment_status_update');

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
                'delivery_method' => $existing['delivery_method'] ?? null,
                'courier' => $existing['courier'] ?? null,
                'tracking_number' => $existing['tracking_number'] ?? null,
            ],
            [
                'order_id' => $order_id,
                'fulfillment_type' => $fulfillment_type,
                'status' => $next_status,
                'delivery_method' => $delivery_method,
                'courier' => $courier ?: null,
                'tracking_number' => $tracking_number ?: null,
                'shipped_at' => $shipped_at,
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
