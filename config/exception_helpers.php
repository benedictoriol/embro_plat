<?php

function order_exception_types(): array {
    return [
        'qc_failed',
        'production_delay',
        'material_shortage',
        'materials_unavailable',
        'delivery_failed',
        'unpaid_block',
        'customer_unresponsive',
        'quote_expired',
        'assignment_blocked',
        'support_unresolved',
        'dispute_unresolved',
    ];
}

function order_exception_blocking_types(): array {
    return [
        'qc_failed',
        'material_shortage',
        'materials_unavailable',
        'delivery_failed',
        'unpaid_block',
        'quote_expired',
        'assignment_blocked',
        'support_unresolved',
        'dispute_unresolved',
    ];
}

function ensure_order_exceptions_table(PDO $pdo): void {
    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS order_exceptions (\n            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,\n            order_id INT(11) NOT NULL,\n            exception_type VARCHAR(50) NOT NULL,\n            severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',\n            status ENUM('open','in_progress','escalated','resolved','dismissed') NOT NULL DEFAULT 'open',\n            notes TEXT DEFAULT NULL,\n            assigned_handler_id INT(11) DEFAULT NULL,\n            escalated_at DATETIME DEFAULT NULL,\n            resolved_at DATETIME DEFAULT NULL,\n            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n            KEY idx_order_exceptions_order (order_id),\n            KEY idx_order_exceptions_type_status (exception_type, status),\n            KEY idx_order_exceptions_assigned_handler (assigned_handler_id),\n            CONSTRAINT fk_order_exceptions_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,\n            CONSTRAINT fk_order_exceptions_assigned_handler FOREIGN KEY (assigned_handler_id) REFERENCES users(id) ON DELETE SET NULL\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci\n    ");

    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS order_exception_history (\n            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,\n            exception_id INT(11) NOT NULL,\n            order_id INT(11) NOT NULL,\n            action ENUM('opened','updated','status_changed','escalated','resolved','dismissed') NOT NULL,\n            previous_status VARCHAR(30) DEFAULT NULL,\n            new_status VARCHAR(30) DEFAULT NULL,\n            actor_id INT(11) DEFAULT NULL,\n            actor_role VARCHAR(40) DEFAULT NULL,\n            note TEXT DEFAULT NULL,\n            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n            KEY idx_exception_history_exception (exception_id),\n            KEY idx_exception_history_order (order_id),\n            CONSTRAINT fk_exception_history_exception FOREIGN KEY (exception_id) REFERENCES order_exceptions(id) ON DELETE CASCADE\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci\n    ");
}

function order_exception_log_history(PDO $pdo, int $exception_id, int $order_id, string $action, ?string $previous_status = null, ?string $new_status = null, ?string $note = null, ?int $actor_id = null, ?string $actor_role = null): void {
    if($exception_id <= 0 || $order_id <= 0) {
        return;
    }

    $insert = $pdo->prepare("\n        INSERT INTO order_exception_history (exception_id, order_id, action, previous_status, new_status, actor_id, actor_role, note)\n        VALUES (?, ?, ?, ?, ?, ?, ?, ?)\n    ");
    $insert->execute([$exception_id, $order_id, $action, $previous_status, $new_status, $actor_id, $actor_role, $note]);
}

function order_exception_notify(PDO $pdo, int $order_id, string $message, ?int $assigned_handler_id = null): void {
    $stmt = $pdo->prepare("\n        SELECT o.id, s.owner_id\n        FROM orders o\n        JOIN shops s ON s.id = o.shop_id\n        WHERE o.id = ?\n        LIMIT 1\n    ");
    $stmt->execute([$order_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if(!$row) {
        return;
    }

    $targets = array_values(array_unique(array_filter([
        (int) ($row['owner_id'] ?? 0),
        (int) ($assigned_handler_id ?? 0),
    ], static fn($id) => $id > 0)));

    if(function_exists('create_notification')) {
        foreach($targets as $user_id) {
            create_notification($pdo, $user_id, $order_id, 'order_status', $message);
        }
    }
}

function order_exception_open(PDO $pdo, int $order_id, string $type, string $severity = 'medium', ?string $notes = null, ?int $assigned_handler_id = null, ?int $actor_id = null, ?string $actor_role = null): void {
    if($order_id <= 0 || !in_array($type, order_exception_types(), true)) {
        return;
    }

    $normalizedSeverity = strtolower(trim($severity));
    if(!in_array($normalizedSeverity, ['low', 'medium', 'high', 'critical'], true)) {
        $normalizedSeverity = 'medium';
    }

    $check = $pdo->prepare("\n        SELECT id, status
        FROM order_exceptions
        WHERE order_id = ?
          AND exception_type = ?
          AND status IN ('open', 'in_progress', 'escalated')
        ORDER BY id DESC
        LIMIT 1
    ");
    $check->execute([$order_id, $type]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);

    if($existing) {
        $existingId = (int) ($existing['id'] ?? 0);
        $update = $pdo->prepare("\n            UPDATE order_exceptions
            SET severity = ?,
                notes = CASE WHEN ? IS NULL OR ? = '' THEN notes ELSE ? END,
                assigned_handler_id = COALESCE(?, assigned_handler_id),
                updated_at = NOW()
            WHERE id = ?
        ");
        $update->execute([$normalizedSeverity, $notes, $notes, $notes, $assigned_handler_id, $existingId]);
        order_exception_log_history($pdo, $existingId, $order_id, 'updated', (string) ($existing['status'] ?? 'open'), (string) ($existing['status'] ?? 'open'), $notes, $actor_id, $actor_role);
        return;
    }

    $insert = $pdo->prepare("\n        INSERT INTO order_exceptions (order_id, exception_type, severity, status, notes, assigned_handler_id)
        VALUES (?, ?, ?, 'open', ?, ?)
    ");
    $insert->execute([$order_id, $type, $normalizedSeverity, $notes, $assigned_handler_id]);
    $exceptionId = (int) $pdo->lastInsertId();
    order_exception_log_history($pdo, $exceptionId, $order_id, 'opened', null, 'open', $notes, $actor_id, $actor_role);
    order_exception_notify($pdo, $order_id, 'New exception opened on order #' . $order_id . ': ' . str_replace('_', ' ', $type) . '.', $assigned_handler_id);
}

function order_exception_resolve(PDO $pdo, int $order_id, string $type, ?string $resolution_notes = null, ?int $actor_id = null, ?string $actor_role = null): void {
    if($order_id <= 0 || !in_array($type, order_exception_types(), true)) {
        return;
    }

    $existing = $pdo->prepare("\n        SELECT id, status, assigned_handler_id
        FROM order_exceptions
        WHERE order_id = ?
          AND exception_type = ?
          AND status IN ('open', 'in_progress', 'escalated')
    ");
    $existing->execute([$order_id, $type]);
    $rows = $existing->fetchAll(PDO::FETCH_ASSOC);
    if(empty($rows)) {
        return;
    }

    $stmt = $pdo->prepare("\n        UPDATE order_exceptions
        SET status = 'resolved',
            resolved_at = NOW(),
            notes = CASE
                WHEN ? IS NULL OR ? = '' THEN notes
                WHEN notes IS NULL OR notes = '' THEN ?
                ELSE CONCAT(notes, '\\nResolved: ', ?)
            END,
            updated_at = NOW()
        WHERE order_id = ?
          AND exception_type = ?
          AND status IN ('open', 'in_progress', 'escalated')
    ");
    $stmt->execute([$resolution_notes, $resolution_notes, $resolution_notes, $resolution_notes, $order_id, $type]);
    
    foreach($rows as $row) {
        $exceptionId = (int) ($row['id'] ?? 0);
        order_exception_log_history($pdo, $exceptionId, $order_id, 'resolved', (string) ($row['status'] ?? 'open'), 'resolved', $resolution_notes, $actor_id, $actor_role);
        order_exception_notify($pdo, $order_id, 'Exception resolved on order #' . $order_id . ': ' . str_replace('_', ' ', $type) . '.', (int) ($row['assigned_handler_id'] ?? 0));
    }
}

function order_exception_update(PDO $pdo, int $exception_id, string $status, ?string $note = null, ?int $assigned_handler_id = null, ?int $actor_id = null, ?string $actor_role = null): bool {
    $allowed = ['open', 'in_progress', 'escalated', 'resolved', 'dismissed'];
    if($exception_id <= 0 || !in_array($status, $allowed, true)) {
        return false;
    }

    $get = $pdo->prepare("SELECT id, order_id, status, exception_type, assigned_handler_id FROM order_exceptions WHERE id = ? LIMIT 1");
    $get->execute([$exception_id]);
    $row = $get->fetch(PDO::FETCH_ASSOC);
    if(!$row) {
        return false;
    }

    $orderId = (int) ($row['order_id'] ?? 0);
    $previousStatus = (string) ($row['status'] ?? 'open');
    $handlerId = $assigned_handler_id ?: (int) ($row['assigned_handler_id'] ?? 0);

    $update = $pdo->prepare("\n        UPDATE order_exceptions
        SET status = ?,
            assigned_handler_id = COALESCE(?, assigned_handler_id),
            escalated_at = CASE WHEN ? = 'escalated' AND escalated_at IS NULL THEN NOW() ELSE escalated_at END,
            resolved_at = CASE WHEN ? IN ('resolved', 'dismissed') THEN NOW() ELSE resolved_at END,
            notes = CASE
                WHEN ? IS NULL OR ? = '' THEN notes
                WHEN notes IS NULL OR notes = '' THEN ?
                ELSE CONCAT(notes, '\\n', ?)
            END,
            updated_at = NOW()
        WHERE id = ?
    ");
    $update->execute([$status, $assigned_handler_id, $status, $status, $note, $note, $note, $note, $exception_id]);

    $action = $status === 'escalated' ? 'escalated' : ($status === 'resolved' ? 'resolved' : ($status === 'dismissed' ? 'dismissed' : 'status_changed'));
    order_exception_log_history($pdo, $exception_id, $orderId, $action, $previousStatus, $status, $note, $actor_id, $actor_role);
    order_exception_notify($pdo, $orderId, 'Exception ' . str_replace('_', ' ', (string) ($row['exception_type'] ?? 'issue')) . ' updated to ' . str_replace('_', ' ', $status) . ' for order #' . $orderId . '.', $handlerId);

    if(function_exists('log_audit')) {
        log_audit($pdo, $actor_id, $actor_role, 'exception_' . $status, 'order_exception', $exception_id, ['status' => $previousStatus], ['status' => $status, 'note' => $note, 'assigned_handler_id' => $assigned_handler_id]);
    }

    return true;
}

function order_exception_has_blocking(PDO $pdo, int $order_id): bool {
    if($order_id <= 0) {
        return false;
    }

    $blockingTypes = order_exception_blocking_types();
    $placeholders = implode(',', array_fill(0, count($blockingTypes), '?'));
    $params = array_merge([$order_id], $blockingTypes);

    $stmt = $pdo->prepare("\n        SELECT id
        FROM order_exceptions
        WHERE order_id = ?
          AND exception_type IN ({$placeholders})
          AND status IN ('open', 'in_progress', 'escalated')
          AND severity IN ('high', 'critical')
        LIMIT 1
    ");
    $stmt->execute($params);

    return (bool) $stmt->fetchColumn();
}

function order_exception_blocking_message(PDO $pdo, int $order_id): ?string {
    if($order_id <= 0) {
        return null;
    }

    $blockingTypes = order_exception_blocking_types();
    $placeholders = implode(',', array_fill(0, count($blockingTypes), '?'));
    $params = array_merge([$order_id], $blockingTypes);

    $stmt = $pdo->prepare("\n        SELECT exception_type
        FROM order_exceptions
        WHERE order_id = ?
          AND exception_type IN ({$placeholders})
          AND status IN ('open', 'in_progress', 'escalated')
          AND severity IN ('high', 'critical')
        ORDER BY FIELD(status, 'escalated', 'open', 'in_progress'), FIELD(severity, 'critical', 'high', 'medium', 'low'), id DESC
        LIMIT 1
    ");
    $stmt->execute($params);
    $type = (string) ($stmt->fetchColumn() ?: '');
    if($type === '') {
        return null;
    }

    return 'Order has an open blocking exception: ' . str_replace('_', ' ', $type) . '.';
}

function order_exception_summaries(PDO $pdo, array $order_ids): array {
    $order_ids = array_values(array_unique(array_filter(array_map('intval', $order_ids), static fn($id) => $id > 0)));
    if(empty($order_ids)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $stmt = $pdo->prepare("\n        SELECT order_id,
               COUNT(*) AS open_count,
               SUM(CASE WHEN status = 'escalated' THEN 1 ELSE 0 END) AS escalated_count,
               MAX(CASE WHEN exception_type IN ('qc_failed','material_shortage','materials_unavailable','delivery_failed','unpaid_block','quote_expired','assignment_blocked','support_unresolved','dispute_unresolved') AND severity IN ('high','critical') THEN 1 ELSE 0 END) AS has_blocking
        FROM order_exceptions
        WHERE order_id IN ({$placeholders})
          AND status IN ('open', 'in_progress', 'escalated')
        GROUP BY order_id
    ");
    $stmt->execute($order_ids);

    $map = [];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $orderId = (int) ($row['order_id'] ?? 0);
        $map[$orderId] = [
            'open_count' => (int) ($row['open_count'] ?? 0),
            'escalated_count' => (int) ($row['escalated_count'] ?? 0),
            'has_blocking' => (int) ($row['has_blocking'] ?? 0) === 1,
        ];
    }

    return $map;
}

function escalate_overdue_order_exceptions(PDO $pdo, int $age_hours = 12): int {
    $age_hours = max(1, $age_hours);

    $stmt = $pdo->prepare("\n        SELECT id, order_id, status
        FROM order_exceptions
        WHERE status IN ('open', 'in_progress')
          AND created_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)
    ");
    $stmt->execute([$age_hours]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $count = 0;
    foreach($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        $orderId = (int) ($row['order_id'] ?? 0);
        if($id <= 0 || $orderId <= 0) {
            continue;
        }

        $update = $pdo->prepare("UPDATE order_exceptions SET status = 'escalated', escalated_at = NOW(), updated_at = NOW() WHERE id = ?");
        $update->execute([$id]);
        order_exception_log_history($pdo, $id, $orderId, 'escalated', (string) ($row['status'] ?? 'open'), 'escalated', 'Escalated by SLA automation.', 0, 'system');
        order_exception_notify($pdo, $orderId, 'Exception on order #' . $orderId . ' has been escalated by SLA automation.');
        $count++;
    }

    return $count;
}
