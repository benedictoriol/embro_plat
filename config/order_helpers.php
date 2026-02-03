<?php
require_once __DIR__ . '/constants.php';

function order_status_transitions(): array {
    return [
        STATUS_PENDING => [STATUS_ACCEPTED, STATUS_CANCELLED],
        STATUS_ACCEPTED => [STATUS_IN_PROGRESS, STATUS_CANCELLED],
        STATUS_IN_PROGRESS => [STATUS_COMPLETED, STATUS_CANCELLED],
        STATUS_COMPLETED => [],
        STATUS_CANCELLED => [],
    ];
}

function can_transition_order_status(string $current, string $next): bool {
    if ($current === $next) {
        return true;
    }

    $transitions = order_status_transitions();
    return in_array($next, $transitions[$current] ?? [], true);
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
    ?int $employee_id = null
): void {
    $stmt = $pdo->prepare("
        INSERT INTO order_status_history (order_id, employee_id, status, progress, notes)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$order_id, $employee_id, $status, $progress, $notes]);
}
?>