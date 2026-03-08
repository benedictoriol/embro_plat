<?php
require_once __DIR__ . '/constants.php';

function order_workflow_status_transitions(): array {
    return [
        STATUS_PENDING => [STATUS_ACCEPTED, STATUS_CANCELLED],
        STATUS_ACCEPTED => [STATUS_DIGITIZING, STATUS_IN_PROGRESS, STATUS_CANCELLED],
        STATUS_DIGITIZING => [STATUS_IN_PROGRESS, STATUS_CANCELLED],
        STATUS_IN_PROGRESS => [STATUS_COMPLETED, STATUS_CANCELLED],
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

function order_workflow_can_transition(array $transitions, string $current, string $next): bool {
    if ($current === $next) {
        return true;
    }

    return in_array($next, $transitions[$current] ?? [], true);
}

function order_workflow_can_transition_order_status(string $current, string $next): bool {
    return order_workflow_can_transition(order_workflow_status_transitions(), $current, $next);
}

function order_workflow_display_progress(string $order_status, int $current_progress = 0, ?string $fulfillment_status = null): int {
    $normalized_status = strtolower(trim($order_status));
    $normalized_fulfillment = $fulfillment_status !== null ? strtolower(trim($fulfillment_status)) : null;
    $safe_progress = max(0, min(100, $current_progress));

    if(in_array($normalized_fulfillment, [FULFILLMENT_DELIVERED, FULFILLMENT_CLAIMED], true)) {
        return 100;
    }

    return match ($normalized_status) {
        STATUS_COMPLETED => 90,
        STATUS_IN_PROGRESS => max($safe_progress, 65),
        STATUS_ACCEPTED => max($safe_progress, 25),
        STATUS_DIGITIZING => max($safe_progress, 40),
        STATUS_PENDING => max($safe_progress, 10),
        STATUS_CANCELLED => $safe_progress,
        default => $safe_progress,
    };
}

function order_workflow_current_stage_label(string $order_status, ?string $fulfillment_status = null): string {
    $normalized_status = strtolower(trim($order_status));
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
        STATUS_ACCEPTED => 'Order accepted',
        STATUS_IN_PROGRESS => 'In production',
        STATUS_DIGITIZING => 'Design digitizing',
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

function order_workflow_validate_order_status(PDO $pdo, array $order, string $next_status): array {
    $current_status = $order['status'] ?? '';
    if($current_status === '') {
        return [false, 'Current status is missing.'];
    }

    if(!order_workflow_can_transition_order_status($current_status, $next_status)) {
        return [false, 'Status transition not allowed from the current state.'];
    }

    if(
        $next_status === STATUS_IN_PROGRESS
        && $current_status !== STATUS_IN_PROGRESS
        && !order_workflow_is_design_approved($pdo, (int) $order['id'])
    ) {
        return [false, 'Design proof approval is required before production can begin.'];
    }

    if(
        $next_status === STATUS_IN_PROGRESS
        && $current_status === STATUS_ACCEPTED
        && order_workflow_requires_digitizing($order)
    ) {
        return [false, 'Digitizing stage must be completed before production begins.'];
    }

    return [true, null];
}

function order_workflow_has_qc_pass(PDO $pdo, int $order_id): bool {
    $stmt = $pdo->prepare("
        SELECT id
        FROM finished_goods
        WHERE order_id = ?
        LIMIT 1
    ");
    $stmt->execute([$order_id]);
    return (bool) $stmt->fetchColumn();
}

function order_workflow_validate_fulfillment_status(PDO $pdo, int $order_id, string $current_status, string $next_status): array {
    if(!order_workflow_can_transition(order_workflow_fulfillment_transitions(), $current_status, $next_status)) {
        return [false, 'Status transition is not allowed from the current state.'];
    }

    $requires_qc = in_array(
        $next_status,
        [FULFILLMENT_READY_FOR_PICKUP, FULFILLMENT_OUT_FOR_DELIVERY, FULFILLMENT_DELIVERED, FULFILLMENT_CLAIMED],
        true
    );
    if($requires_qc && !order_workflow_has_qc_pass($pdo, $order_id)) {
        return [false, 'QC approval is required before delivery or pickup can begin.'];
    }

    return [true, null];
}

function order_workflow_is_delivery_confirmed(PDO $pdo, int $order_id): bool {
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
