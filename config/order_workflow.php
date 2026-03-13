<?php
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/exception_automation_helpers.php';

function order_workflow_status_aliases(): array {
    return [
        STATUS_IN_PROGRESS => STATUS_PRODUCTION,
        'ready_for_production' => STATUS_PRODUCTION_PENDING,
        'processing' => STATUS_PRODUCTION,
        'for_qc' => STATUS_QC_PENDING,
        'for_delivery' => STATUS_READY_FOR_DELIVERY,
        'ready_for_pickup' => STATUS_READY_FOR_DELIVERY,
        'done' => STATUS_COMPLETED,
    ];
}

function order_workflow_active_statuses(bool $include_legacy_alias = true): array {
    $statuses = [
        STATUS_PENDING,
        STATUS_ACCEPTED,
        STATUS_DIGITIZING,
        STATUS_PRODUCTION_PENDING,
        STATUS_PRODUCTION,
        STATUS_PRODUCTION_REWORK,
        STATUS_QC_PENDING,
        STATUS_READY_FOR_DELIVERY,
        STATUS_DELIVERED,
    ];

    if($include_legacy_alias) {
        $statuses[] = STATUS_IN_PROGRESS;
    }

    return $statuses;
}

function order_workflow_status_placeholders(array $statuses): string {
    if(empty($statuses)) {
        return "'pending'";
    }

    return implode(', ', array_fill(0, count($statuses), '?'));
}

function order_workflow_normalize_order_status(string $status): string {
    $normalized = strtolower(trim($status));
    $aliases = order_workflow_status_aliases();

    return $aliases[$normalized] ?? $normalized;
}

function order_workflow_status_transitions(): array {
    return [
        STATUS_PENDING => [STATUS_ACCEPTED, STATUS_CANCELLED],
        STATUS_ACCEPTED => [STATUS_DIGITIZING, STATUS_PRODUCTION_PENDING, STATUS_PRODUCTION, STATUS_CANCELLED],
        STATUS_DIGITIZING => [STATUS_PRODUCTION_PENDING, STATUS_PRODUCTION, STATUS_CANCELLED],
        STATUS_PRODUCTION_PENDING => [STATUS_PRODUCTION, STATUS_CANCELLED],
        STATUS_PRODUCTION => [STATUS_QC_PENDING, STATUS_PRODUCTION_REWORK, STATUS_CANCELLED],
        STATUS_PRODUCTION_REWORK => [STATUS_PRODUCTION, STATUS_QC_PENDING, STATUS_CANCELLED],
        STATUS_QC_PENDING => [STATUS_READY_FOR_DELIVERY, STATUS_PRODUCTION_REWORK, STATUS_CANCELLED],
        STATUS_READY_FOR_DELIVERY => [STATUS_DELIVERED, STATUS_COMPLETED, STATUS_CANCELLED],
        STATUS_DELIVERED => [STATUS_COMPLETED, STATUS_CANCELLED],
        STATUS_COMPLETED => [],
        STATUS_CANCELLED => [],
    ];
}

function order_workflow_fulfillment_transitions(): array {
    return [
        FULFILLMENT_PENDING => [FULFILLMENT_READY_FOR_PICKUP, FULFILLMENT_OUT_FOR_DELIVERY, FULFILLMENT_FAILED],
        FULFILLMENT_READY_FOR_PICKUP => [FULFILLMENT_CLAIMED, FULFILLMENT_FAILED],
        FULFILLMENT_OUT_FOR_DELIVERY => [FULFILLMENT_DELIVERED, FULFILLMENT_FAILED],
        FULFILLMENT_DELIVERED => [FULFILLMENT_CLAIMED],
        FULFILLMENT_CLAIMED => [],
        FULFILLMENT_FAILED => [],
    ];
}

function order_workflow_is_transition_allowed(array $transitions, string $current, string $next): bool {
    if ($current === $next) {
        return true;
    }

    return in_array($next, $transitions[$current] ?? [], true);
}

function order_workflow_can_transition_order_status(string $current, string $next): bool {
    $normalized_current = order_workflow_normalize_order_status($current);
    $normalized_next = order_workflow_normalize_order_status($next);
    return order_workflow_is_transition_allowed(order_workflow_status_transitions(), $normalized_current, $normalized_next);
}

function order_workflow_display_progress(string $order_status, int $current_progress = 0, ?string $fulfillment_status = null): int {
    $normalized_status = order_workflow_normalize_order_status($order_status);
    $normalized_fulfillment = $fulfillment_status !== null ? strtolower(trim($fulfillment_status)) : null;
    $safe_progress = max(0, min(100, $current_progress));

    if(in_array($normalized_fulfillment, [FULFILLMENT_DELIVERED, FULFILLMENT_CLAIMED], true)) {
        return 100;
    }

    return match ($normalized_status) {
        STATUS_PENDING => max($safe_progress, 10),
        STATUS_ACCEPTED => max($safe_progress, 20),
        STATUS_DIGITIZING => max($safe_progress, 35),
        STATUS_PRODUCTION_PENDING => max($safe_progress, 45),
        STATUS_PRODUCTION => max($safe_progress, 60),
        STATUS_PRODUCTION_REWORK => max($safe_progress, 65),
        STATUS_QC_PENDING => max($safe_progress, 75),
        STATUS_READY_FOR_DELIVERY => max($safe_progress, 85),
        STATUS_DELIVERED => max($safe_progress, 95),
        STATUS_COMPLETED => 100,
        STATUS_CANCELLED => $safe_progress,
        default => $safe_progress,
    };
}

function order_workflow_current_stage_label(string $order_status, ?string $fulfillment_status = null): string {
    $normalized_status = order_workflow_normalize_order_status($order_status);
    $normalized_fulfillment = $fulfillment_status !== null ? strtolower(trim($fulfillment_status)) : null;

    if($normalized_status === STATUS_CANCELLED) {
        return 'Cancelled';
    }

    if($normalized_fulfillment === FULFILLMENT_DELIVERED) {
        return 'Delivered';
    }

    if($normalized_fulfillment === FULFILLMENT_CLAIMED) {
        return 'Claimed';
    }

    if($normalized_status === STATUS_COMPLETED && $normalized_fulfillment === FULFILLMENT_READY_FOR_PICKUP) {
        return 'Ready for pickup';
    }

    if($normalized_status === STATUS_COMPLETED && $normalized_fulfillment === FULFILLMENT_OUT_FOR_DELIVERY) {
        return 'Out for delivery';
    }

    return match ($normalized_status) {
        STATUS_PENDING => 'Order placed',
        STATUS_ACCEPTED => 'Owner review accepted',
        STATUS_DIGITIZING => 'Digitizing',
        STATUS_PRODUCTION_PENDING => 'Ready for production',
        STATUS_PRODUCTION => 'Production',
        STATUS_PRODUCTION_REWORK => 'Production rework',
        STATUS_QC_PENDING => 'Quality control',
        STATUS_READY_FOR_DELIVERY => 'Ready for delivery',
        STATUS_DELIVERED => 'Delivered',
        STATUS_COMPLETED => 'Completed',
        STATUS_CANCELLED => 'Cancelled',
        default => ucfirst(str_replace('_', ' ', $normalized_status)),
    };
}

function order_workflow_design_pending_status(PDO $pdo): string {
    try {
        $status_column_stmt = $pdo->query("SHOW COLUMNS FROM design_approvals LIKE 'status'");
        $status_column = $status_column_stmt ? $status_column_stmt->fetch() : null;
        $column_type = strtolower((string) ($status_column['Type'] ?? ''));

        if(str_starts_with($column_type, 'enum(')) {
            preg_match_all("/'([^']+)'/", $column_type, $matches);
            $allowed_statuses = $matches[1] ?? [];
            if(in_array('pending_review', $allowed_statuses, true)) {
                return 'pending_review';
            }
        }
    } catch(PDOException $e) {
        // Fallback to the baseline pending status when schema introspection is unavailable.
    }

    return 'pending';
}

function order_workflow_is_design_approved(PDO $pdo, int $order_id): bool {
    $approval_stmt = $pdo->prepare("
        SELECT o.design_approved, da.status
        FROM orders o
        LEFT JOIN design_approvals da ON da.order_id = o.id
        WHERE o.id = ?
        LIMIT 1
    ");
    $approval_stmt->execute([$order_id]);
    $approval = $approval_stmt->fetch();

    if(!$approval) {
        return false;
    }

    return (int) $approval['design_approved'] === 1 || ($approval['status'] ?? '') === 'approved';
}


function order_workflow_requires_digitizing(array $order): bool {
    $service_type = strtolower(trim((string) ($order['service_type'] ?? '')));
    if($service_type === '') {
        return false;
    }

    return str_contains($service_type, 'embroider');
}

function order_workflow_get_transition_requirements(PDO $pdo, array $order, string $next_status, bool $allow_manual_override = false): array {
    $current_status = order_workflow_normalize_order_status((string) ($order['status'] ?? ''));
    $next_status = order_workflow_normalize_order_status($next_status);
    $order_id = (int) ($order['id'] ?? 0);

    $quote_status = strtolower(trim((string) ($order['quote_status'] ?? '')));
    $has_quote_amount = isset($order['price']) && $order['price'] !== null && (float) $order['price'] > 0;
    $quote_approved = !empty($order['quote_approved_at']) || $quote_status === 'approved';

    $is_payment_requirement_satisfied = order_workflow_is_payment_requirement_satisfied($order);
    $is_design_approved = $order_id > 0 ? order_workflow_is_design_approved($pdo, $order_id) : false;
    $requires_design_approval = (bool) system_setting_get($pdo, 'order_workflow', 'require_design_approval', true);

        $materials_available = true;
    $materials_message = null;
    if($next_status === STATUS_PRODUCTION && function_exists('automation_can_start_production_with_materials')) {
        [$materials_available, $materials_message] = automation_can_start_production_with_materials($pdo, $order);
    }

    $assignee_available = !empty($order['assigned_to']) || !empty($order['assignee_id']);
    $qc_passed = $order_id > 0 ? order_workflow_has_qc_pass($pdo, $order_id) : false;
    $delivery_ready = !empty($order['delivery_prepared_at'])
        || !empty($order['delivery_address'])
        || !empty($order['pickup_details']);

    return [
        [
            'code' => 'valid_status_transition',
            'label' => 'Status path is valid',
            'required' => !$allow_manual_override,
            'met' => $allow_manual_override || order_workflow_can_transition_order_status($current_status, $next_status),
            'message' => 'Status transition not allowed from the current state.',
            'meta' => ['current_status' => $current_status, 'target_status' => $next_status],
        ],
        [
            'code' => 'no_blocking_exception',
            'label' => 'No blocking exception exists',
            'required' => !$allow_manual_override && $order_id > 0 && function_exists('order_exception_has_blocking'),
            'met' => $allow_manual_override || $order_id <= 0 || !function_exists('order_exception_has_blocking') || !order_exception_has_blocking($pdo, $order_id),
            'message' => order_exception_blocking_message($pdo, $order_id) ?? 'Order has open blocking exceptions. Resolve them before moving stages.',
            'meta' => ['order_id' => $order_id],
        ],
        [
            'code' => 'quote_approved',
            'label' => 'Quote approved',
            'required' => $next_status === STATUS_ACCEPTED,
            'met' => $has_quote_amount && $quote_approved,
            'message' => !$has_quote_amount
                ? 'A finalized quote amount is required before accepting the order.'
                : 'Client quote approval is required before accepting the order.',
            'meta' => ['quote_status' => $quote_status, 'has_quote_amount' => $has_quote_amount],
        ],
        [
            'code' => 'payment_verified_or_deposit_sufficient',
            'label' => 'Payment verified or sufficient deposit received',
            'required' => in_array($next_status, [STATUS_PRODUCTION_PENDING, STATUS_PRODUCTION], true),
            'met' => $is_payment_requirement_satisfied,
            'message' => 'Required payment or downpayment must be verified before production can begin.',
            'meta' => ['payment_status' => $order['payment_status'] ?? null, 'payment_verified_at' => $order['payment_verified_at'] ?? null],
        ],
        [
            'code' => 'design_approved',
            'label' => 'Design approved',
            'required' => $requires_design_approval && in_array($next_status, [STATUS_PRODUCTION_PENDING, STATUS_PRODUCTION], true) && $current_status !== STATUS_PRODUCTION,
            'met' => $is_design_approved,
            'message' => 'Design proof approval is required before production can begin.',
            'meta' => ['requires_design_approval' => $requires_design_approval],
        ],
        [
            'code' => 'digitizing_completed',
            'label' => 'Digitizing completed before production',
            'required' => !$allow_manual_override && $next_status === STATUS_PRODUCTION && $current_status === STATUS_ACCEPTED && order_workflow_requires_digitizing($order),
            'met' => false,
            'message' => 'Digitizing stage must be completed before production begins.',
            'meta' => ['service_type' => $order['service_type'] ?? null],
        ],
        [
            'code' => 'materials_available_reserved',
            'label' => 'Materials available/reserved',
            'required' => $next_status === STATUS_PRODUCTION,
            'met' => $materials_available,
            'message' => $materials_message ?: 'Required materials are unavailable for production start.',
            'meta' => [],
        ],
        [
            'code' => 'assignee_available',
            'label' => 'Assignee available',
            'required' => in_array($next_status, [STATUS_PRODUCTION, STATUS_QC_PENDING], true),
            'met' => $assignee_available,
            'message' => 'An assigned staff member is required before moving this order.',
            'meta' => ['assigned_to' => $order['assigned_to'] ?? null],
        ],
        [
            'code' => 'qc_passed_for_post_production',
            'label' => 'QC result available when moving past production',
            'required' => $next_status === STATUS_READY_FOR_DELIVERY,
            'met' => $qc_passed,
            'message' => 'QC pass is required before moving to ready for delivery.',
            'meta' => ['order_id' => $order_id],
        ],
        [
            'code' => 'delivery_preparation_complete',
            'label' => 'Delivery preparation complete',
            'required' => in_array($next_status, [STATUS_DELIVERED, STATUS_COMPLETED], true),
            'met' => $delivery_ready,
            'message' => 'Delivery preparation must be completed before final delivery/completion.',
            'meta' => ['delivery_prepared_at' => $order['delivery_prepared_at'] ?? null],
        ],
        [
            'code' => 'delivery_confirmed',
            'label' => 'Delivery or pickup confirmed',
            'required' => $next_status === STATUS_COMPLETED,
            'met' => $order_id > 0 && order_workflow_is_delivery_confirmed($pdo, $order_id, $order),
            'message' => 'Delivery or pickup confirmation is required before completion.',
            'meta' => ['order_id' => $order_id],
        ],
        [
            'code' => 'final_payment_settled',
            'label' => 'Final payment settled',
            'required' => $next_status === STATUS_COMPLETED,
            'met' => order_workflow_is_final_payment_satisfied($order),
            'message' => 'Final payment must be settled before this order can be completed.',
            'meta' => ['payment_status' => $order['payment_status'] ?? null],
        ],
    ];
}

function order_workflow_get_missing_requirements(PDO $pdo, array $order, string $next_status, bool $allow_manual_override = false): array {
    $requirements = order_workflow_get_transition_requirements($pdo, $order, $next_status, $allow_manual_override);
    return array_values(array_filter($requirements, static function(array $requirement): bool {
        return !empty($requirement['required']) && empty($requirement['met']);
    }));
}

function order_workflow_can_transition(PDO $pdo, array $order, string $next_status, bool $allow_manual_override = false): array {
    $missing = order_workflow_get_missing_requirements($pdo, $order, $next_status, $allow_manual_override);
    return [empty($missing), $missing];
}

function order_workflow_apply_transition(
    PDO $pdo,
    int $order_id,
    string $target_status,
    ?array $actor = null,
    ?string $notes = null,
    bool $allow_manual_override = false
): array {
    $actor = $actor ?? [];
    $actor_id = isset($actor['id']) ? (int) $actor['id'] : null;
    $actor_role = isset($actor['role']) ? (string) $actor['role'] : null;

    if(function_exists('automation_transition_order_status')) {
        [$ok, $error, $meta] = automation_transition_order_status(
            $pdo,
            $order_id,
            $target_status,
            $actor_id,
            $actor_role,
            $notes,
            $allow_manual_override
        );

        if($ok) {
            return [true, null, ['transition' => $meta, 'missing_requirements' => []]];
        }
        
    $order_stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
        $order_stmt->execute([$order_id]);
        $order = $order_stmt->fetch() ?: ['id' => $order_id];
        $missing = order_workflow_get_missing_requirements($pdo, $order, $target_status, $allow_manual_override);
        return [false, $error, ['missing_requirements' => $missing]];
    }

    return [false, 'Workflow transition helper unavailable.', ['missing_requirements' => []]];
}

function order_workflow_validate_order_status(PDO $pdo, array $order, string $next_status, bool $allow_manual_override = false): array {
    $current_status = order_workflow_normalize_order_status((string) ($order['status'] ?? ''));
    if($current_status === '') {
        return [false, 'Current status is missing.'];
    }

    [$can_transition, $missing_requirements] = order_workflow_can_transition($pdo, $order, $next_status, $allow_manual_override);
    if($can_transition) {
        return [true, null];
    }

    $first_missing = $missing_requirements[0] ?? null;
    $order_id = (int) ($order['id'] ?? 0);
    $missing_code = (string) ($first_missing['code'] ?? '');

    if($order_id > 0) {
        if($missing_code === 'quote_approved') {
            exception_automation_open($pdo, 'stale_quotation', $order_id, ['notes' => 'Order cannot be accepted until quote is approved by client.']);
        }

    if($missing_code === 'payment_verified_or_deposit_sufficient') {
            exception_automation_open($pdo, 'overdue_payment', $order_id, ['notes' => 'Required payment/downpayment is not verified.']);
        }

    if($missing_code === 'qc_passed_for_post_production') {
            exception_automation_open($pdo, 'qc_failure', $order_id, ['notes' => 'Order attempted to move to delivery without QC pass.']);
        }

        if(in_array($missing_code, ['assignee_available', 'materials_available_for_production'], true)) {
            exception_automation_open($pdo, 'blocked_order_readiness', $order_id, ['notes' => 'Order readiness checks failed during status transition.']);
        }
    }

    return [false, (string) ($first_missing['message'] ?? 'Status transition not allowed from the current state.')];
}

function order_workflow_is_final_payment_satisfied(array $order): bool {
    $order_total = isset($order['price']) ? (float) $order['price'] : 0.0;
    if($order_total <= 0) {
        return true;
    }

    $payment_status = strtolower(trim((string) ($order['payment_status'] ?? 'unpaid')));
    if($payment_status === 'successful' || $payment_status === 'success' || $payment_status === 'completed') {
        $payment_status = 'paid';
    }

    return in_array($payment_status, ['paid', 'refunded'], true);
}

function order_workflow_is_payment_requirement_satisfied(array $order): bool {
    $payment_status = strtolower(trim((string) ($order['payment_status'] ?? 'unpaid')));
    if($payment_status === 'paid') {
        return true;
    }

    $required_downpayment = isset($order['required_downpayment_amount']) ? (float) $order['required_downpayment_amount'] : 0.0;
    if($required_downpayment <= 0) {
        return false;
    }

    return in_array($payment_status, ['pending_verification', 'partially_paid', 'paid'], true) && !empty($order['payment_verified_at']);
}

function order_workflow_has_qc_pass(PDO $pdo, int $order_id): bool {
    $order_stmt = $pdo->prepare("SELECT status FROM orders WHERE id = ? LIMIT 1");
    $order_stmt->execute([$order_id]);
    $order_status = order_workflow_normalize_order_status((string) ($order_stmt->fetchColumn() ?: ''));

    if(in_array($order_status, [STATUS_READY_FOR_DELIVERY, STATUS_DELIVERED, STATUS_COMPLETED], true)) {
        return true;
    }

    try {
        $qc_stmt = $pdo->prepare("
            SELECT id
            FROM order_quality_checks
            WHERE order_id = ? AND qc_status = 'passed'
            ORDER BY id DESC
            LIMIT 1
        ");
        $qc_stmt->execute([$order_id]);
        if($qc_stmt->fetchColumn()) {
            return true;
        }
    } catch(Throwable $e) {
        // Ignore when QC table is unavailable in legacy environments.
    }

    $stmt = $pdo->prepare("SELECT id FROM finished_goods WHERE order_id = ? LIMIT 1");
    $stmt->execute([$order_id]);
    return (bool) $stmt->fetchColumn();
}

function order_workflow_validate_fulfillment_status(PDO $pdo, int $order_id, string $next_status, ?string $current_status = null): array {
    $resolved_current_status = $current_status;

    if($resolved_current_status === null) {
        $fulfillment_stmt = $pdo->prepare("\n            SELECT status\n            FROM order_fulfillments\n            WHERE order_id = ?\n            ORDER BY id DESC\n            LIMIT 1\n        ");
        $fulfillment_stmt->execute([$order_id]);
        $resolved_current_status = $fulfillment_stmt->fetchColumn() ?: FULFILLMENT_PENDING;
    }

    $resolved_current_status = strtolower(trim((string) $resolved_current_status));
    $normalized_next_status = strtolower(trim($next_status));

    if($resolved_current_status === '') {
        $resolved_current_status = FULFILLMENT_PENDING;
    }

    if(!order_workflow_is_transition_allowed(order_workflow_fulfillment_transitions(), $resolved_current_status, $normalized_next_status)) {
        return [false, 'Status transition is not allowed from the current state.'];
    }

    $requires_qc = in_array(
        $normalized_next_status,
        [FULFILLMENT_READY_FOR_PICKUP, FULFILLMENT_OUT_FOR_DELIVERY, FULFILLMENT_DELIVERED, FULFILLMENT_CLAIMED],
        true
    );
    if($requires_qc && !order_workflow_has_qc_pass($pdo, $order_id)) {
        return [false, 'QC approval is required before delivery or pickup can begin.'];
    }

    return [true, null, $resolved_current_status];
}

function order_workflow_is_delivery_confirmed(PDO $pdo, int $order_id, ?array $order = null): bool {
    if($order !== null && !empty($order['delivery_confirmed_at'])) {
        return true;
    }

$stmt = $pdo->prepare("
        SELECT status
        FROM order_fulfillments
        WHERE order_id = ?
        LIMIT 1
    ");
    $stmt->execute([$order_id]);
    $status = $stmt->fetchColumn();

    return in_array($status, [FULFILLMENT_DELIVERED, FULFILLMENT_CLAIMED], true);
}

function order_workflow_validate_payment_release(PDO $pdo, int $order_id): array {
    if(!order_workflow_is_delivery_confirmed($pdo, $order_id)) {
        return [false, 'Delivery confirmation is required before payment release.'];
    }

    return [true, null];
}
?>
