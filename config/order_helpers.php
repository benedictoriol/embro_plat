<?php
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/order_workflow.php';

function order_status_transitions(): array {
    return order_workflow_status_transitions();
}

function can_transition_order_status(string $current, string $next): bool {
    return order_workflow_can_transition_order_status($current, $next);
}

function is_terminal_order_status(string $status): bool {
    return in_array($status, [STATUS_COMPLETED, STATUS_CANCELLED], true);
}

function record_order_status_history(
    PDO $pdo,
    int $order_id,
    string $status,
    int $progress = 0,
    ?string $notes = null,
    ?int $staff_id = null
): void {
    $stmt = $pdo->prepare("
        INSERT INTO order_status_history (order_id, staff_id, status, progress, notes)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$order_id, $staff_id, $status, $progress, $notes]);
}

function record_order_status_history_once(
    PDO $pdo,
    int $order_id,
    string $status,
    int $progress = 0,
    ?string $notes = null,
    ?int $staff_id = null
): void {
    $latest_stmt = $pdo->prepare(
        "SELECT status FROM order_status_history WHERE order_id = ? ORDER BY id DESC LIMIT 1"
    );
    $latest_stmt->execute([$order_id]);
    $latest_status = $latest_stmt->fetchColumn();

    if($latest_status === $status) {
        return;
    }

    record_order_status_history($pdo, $order_id, $status, $progress, $notes, $staff_id);
}

function order_display_progress(array $order, ?string $fulfillment_status = null): int {
    return order_workflow_display_progress(
        (string) ($order['status'] ?? STATUS_PENDING),
        (int) ($order['progress'] ?? 0),
        $fulfillment_status
    );
}

function order_current_stage_label(array $order, ?string $fulfillment_status = null): string {
    return order_workflow_current_stage_label((string) ($order['status'] ?? STATUS_PENDING), $fulfillment_status);
}


function order_workflow_snapshot(array $order, array $status_history = [], ?string $fulfillment_status = null): array {
    $effective_status = (string) ($order['status'] ?? STATUS_PENDING);
    $effective_progress = (int) ($order['progress'] ?? 0);

    if(!empty($status_history)) {
        $latest_entry = end($status_history);
        if(is_array($latest_entry)) {
            if(!empty($latest_entry['status'])) {
                $effective_status = (string) $latest_entry['status'];
            }
            if(isset($latest_entry['progress']) && $latest_entry['progress'] !== null) {
                $effective_progress = (int) $latest_entry['progress'];
            }
        }
        reset($status_history);
    }

    return [
        'status' => $effective_status,
        'progress' => $effective_progress,
        'display_progress' => order_workflow_display_progress($effective_status, $effective_progress, $fulfillment_status),
        'stage_label' => order_workflow_current_stage_label($effective_status, $fulfillment_status),
    ];
}

function ensure_status_history_with_fallback(array $status_history, array $order, ?string $fallback_note = null): array {
    if(!empty($status_history)) {
        return $status_history;
    }

    return [[
        'order_id' => (int) ($order['id'] ?? 0),
        'staff_id' => null,
        'status' => (string) ($order['status'] ?? STATUS_PENDING),
        'progress' => (int) ($order['progress'] ?? 0),
        'notes' => $fallback_note,
        'created_at' => (string) ($order['updated_at'] ?? $order['created_at'] ?? date('Y-m-d H:i:s')),
    ]];
}
?>