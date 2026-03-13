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
    
    $default_title = order_progress_default_title_for_status($status);
    $default_description = $notes !== null && trim($notes) !== ''
        ? trim($notes)
        : ('Order moved to ' . ucfirst(str_replace('_', ' ', order_workflow_normalize_order_status($status))) . '.');
    record_order_progress_log_once(
        $pdo,
        $order_id,
        $status,
        $default_title,
        $default_description,
        $staff_id
    );
}

function order_progress_default_title_for_status(string $status): string {
    $normalized = order_workflow_normalize_order_status($status);

    return match($normalized) {
        STATUS_PENDING => 'Order created',
        STATUS_ACCEPTED => 'Owner accepted order',
        STATUS_DIGITIZING => 'Digitizing started',
        STATUS_PRODUCTION_PENDING => 'Digitizing completed',
        STATUS_PRODUCTION => 'Production started',
        STATUS_QC_PENDING => 'Production completed',
        STATUS_PRODUCTION_REWORK => 'QC failed',
        STATUS_READY_FOR_DELIVERY => 'Ready for delivery',
        STATUS_DELIVERED => 'Order delivered',
        STATUS_COMPLETED => 'Order completed',
        STATUS_CANCELLED => 'Order cancelled',
        default => ucfirst(str_replace('_', ' ', $normalized)),
    };
}

function order_progress_log_table_exists(PDO $pdo): bool {
    static $cache = null;
    if($cache !== null) {
        return $cache;
    }

    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'order_progress_logs'");
        $cache = (bool) ($stmt && $stmt->fetchColumn());
    } catch(Throwable $e) {
        $cache = false;
    }

    return $cache;
}

function record_order_progress_log_once(
    PDO $pdo,
    int $order_id,
    string $status,
    string $title,
    ?string $description = null,
    ?int $actor_user_id = null
): void {
    if($order_id <= 0 || !order_progress_log_table_exists($pdo)) {
        return;
    }

    $normalized_status = order_workflow_normalize_order_status($status);
    $trimmed_title = trim($title);
    $trimmed_description = $description !== null ? trim($description) : null;
    if($trimmed_title === '') {
        $trimmed_title = order_progress_default_title_for_status($normalized_status);
    }

    $dedupe_stmt = $pdo->prepare("\n        SELECT id FROM order_progress_logs\n        WHERE order_id = ?\n          AND status = ?\n          AND title = ?\n          AND COALESCE(description, '') = COALESCE(?, '')\n        ORDER BY id DESC\n        LIMIT 1\n    ");
    $dedupe_stmt->execute([$order_id, $normalized_status, $trimmed_title, $trimmed_description]);
    if($dedupe_stmt->fetchColumn()) {
        return;
    }

    $insert_stmt = $pdo->prepare("\n        INSERT INTO order_progress_logs (order_id, status, title, description, actor_user_id)\n        VALUES (?, ?, ?, ?, ?)\n    ");
    $insert_stmt->execute([$order_id, $normalized_status, $trimmed_title, $trimmed_description, $actor_user_id]);
}

function fetch_order_progress_logs(PDO $pdo, array $order_ids): array {
    if(empty($order_ids) || !order_progress_log_table_exists($pdo)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $stmt = $pdo->prepare("\n        SELECT id, order_id, status, title, description, actor_user_id, created_at\n        FROM order_progress_logs\n        WHERE order_id IN ($placeholders)\n        ORDER BY created_at ASC, id ASC\n    ");
    $stmt->execute($order_ids);
    $rows = $stmt->fetchAll();

    $by_order = [];
    foreach($rows as $row) {
        $by_order[(int) $row['order_id']][] = $row;
    }

    return $by_order;
}

function ensure_progress_logs_with_fallback(array $progress_logs, array $status_history, array $order): array {
    if(!empty($progress_logs)) {
        return $progress_logs;
    }

    if(!empty($status_history)) {
        $mapped = [];
        foreach($status_history as $entry) {
            $status = (string) ($entry['status'] ?? STATUS_PENDING);
            $mapped[] = [
                'order_id' => (int) ($entry['order_id'] ?? ($order['id'] ?? 0)),
                'status' => order_workflow_normalize_order_status($status),
                'title' => order_progress_default_title_for_status($status),
                'description' => $entry['notes'] ?? null,
                'actor_user_id' => $entry['staff_id'] ?? null,
                'created_at' => (string) ($entry['created_at'] ?? $order['updated_at'] ?? $order['created_at'] ?? date('Y-m-d H:i:s')),
            ];
        }
        return $mapped;
    }

    $fallback_status = (string) ($order['status'] ?? STATUS_PENDING);
    return [[
        'order_id' => (int) ($order['id'] ?? 0),
        'status' => order_workflow_normalize_order_status($fallback_status),
        'title' => order_progress_default_title_for_status($fallback_status),
        'description' => 'Current order status.',
        'actor_user_id' => null,
        'created_at' => (string) ($order['updated_at'] ?? $order['created_at'] ?? date('Y-m-d H:i:s')),
    ]];
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


function mark_order_as_cancelled(PDO $pdo, int $order_id, int $client_id, string $reason): bool {
    if($order_id <= 0 || $client_id <= 0) {
        return false;
    }

    $stmt = $pdo->prepare("
        UPDATE orders
        SET status = ?, cancellation_reason = ?, cancelled_at = NOW(), updated_at = NOW()
        WHERE id = ? AND client_id = ?
    ");
    $stmt->execute([STATUS_CANCELLED, $reason, $order_id, $client_id]);

    return $stmt->rowCount() > 0;
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

function order_table_columns(PDO $pdo): array {
    static $cache = null;
    if($cache !== null) {
        return $cache;
    }

    $columns = [];
    $stmt = $pdo->query('SHOW COLUMNS FROM orders');
    foreach(($stmt ? $stmt->fetchAll() : []) as $row) {
        $columns[] = (string) ($row['Field'] ?? '');
    }

    $cache = array_filter($columns);
    return $cache;
}

function order_create_unified_request(PDO $pdo, array $payload): int {
    $required = ['client_id', 'shop_id', 'service_type', 'quantity'];
    foreach($required as $field) {
        if(empty($payload[$field])) {
            throw new InvalidArgumentException('Missing required order field: ' . $field);
        }
    }

    $columns = order_table_columns($pdo);
    $insert_columns = [
        'order_number', 'client_id', 'shop_id', 'service_type', 'design_description',
        'quantity', 'price', 'client_notes', 'quote_details', 'design_file', 'status', 'payment_status'
    ];
    $values = [
        $payload['order_number'] ?? ('ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6))),
        (int) $payload['client_id'],
        (int) $payload['shop_id'],
        (string) $payload['service_type'],
        $payload['design_description'] ?? null,
        max(1, (int) $payload['quantity']),
        $payload['price'] ?? null,
        $payload['client_notes'] ?? null,
        $payload['quote_details'] ?? null,
        $payload['design_file'] ?? null,
        STATUS_PENDING,
        $payload['payment_status'] ?? 'unpaid',
    ];

    $optional = [
        'design_version_id' => $payload['design_version_id'] ?? null,
        'design_approved' => $payload['design_approved'] ?? 0,
        'quote_status' => $payload['quote_status'] ?? 'requested',
        'quote_approved_at' => $payload['quote_approved_at'] ?? null,
        'required_downpayment_amount' => $payload['required_downpayment_amount'] ?? null,
        'delivery_confirmed_at' => $payload['delivery_confirmed_at'] ?? null,
        'completed_at' => $payload['completed_at'] ?? null,
    ];

    foreach($optional as $column => $value) {
        if(in_array($column, $columns, true)) {
            $insert_columns[] = $column;
            $values[] = $value;
        }
    }

    $placeholders = implode(', ', array_fill(0, count($insert_columns), '?'));
    $sql = 'INSERT INTO orders (' . implode(', ', $insert_columns) . ') VALUES (' . $placeholders . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);

    return (int) $pdo->lastInsertId();
}
?>