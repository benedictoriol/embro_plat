<?php

function ensure_production_queue_table(PDO $pdo): void {
    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS production_queue (\n            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,\n            order_id INT(11) NOT NULL,\n            priority INT(11) NOT NULL DEFAULT 0,\n            estimated_duration INT(11) DEFAULT NULL,\n            scheduled_machine VARCHAR(100) DEFAULT NULL,\n            queue_position INT(11) DEFAULT NULL,\n            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n            UNIQUE KEY uq_production_queue_order_id (order_id),\n            KEY idx_production_queue_priority (priority),\n            KEY idx_production_queue_position (queue_position),\n            CONSTRAINT fk_production_queue_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci\n    ");
}

function production_queue_stage_statuses(): array {
    return ['digitizing', 'production-prep', 'production_prep', 'production prep'];
}

function production_queue_order_age_days(?string $createdAt): int {
    if (empty($createdAt)) {
        return 0;
    }

    $createdAtTs = strtotime($createdAt);
    if ($createdAtTs === false) {
        return 0;
    }

    return (int) max(0, floor((time() - $createdAtTs) / 86400));
}

function production_queue_rush_weight(?string $quoteDetailsJson): int {
    if (empty($quoteDetailsJson)) {
        return 0;
    }

    $quoteDetails = json_decode($quoteDetailsJson, true);
    if (!is_array($quoteDetails)) {
        return 0;
    }

    return !empty($quoteDetails['rush']) ? 50 : 0;
}

function production_queue_calculate_priority(array $order): int {
    $rushOrderWeight = production_queue_rush_weight($order['quote_details'] ?? null);
    $clientPriority = (int) ($order['client_priority'] ?? 0);
    $orderAge = production_queue_order_age_days($order['created_at'] ?? null);

    return $rushOrderWeight + $clientPriority + $orderAge;
}

function sync_production_queue(PDO $pdo): void {
    ensure_production_queue_table($pdo);

    $statuses = production_queue_stage_statuses();
    $statusPlaceholders = implode(',', array_fill(0, count($statuses), '?'));

    $removeInactiveStmt = $pdo->prepare("\n        DELETE pq\n        FROM production_queue pq\n        LEFT JOIN orders o ON o.id = pq.order_id\n        WHERE o.id IS NULL\n           OR o.status IN ('completed', 'cancelled')\n           OR o.status NOT IN ($statusPlaceholders)\n    ");
    $removeInactiveStmt->execute($statuses);

    $hasEstimatedDuration = function_exists('column_exists') && column_exists($pdo, 'orders', 'estimated_duration');
    $hasScheduledMachine = function_exists('column_exists') && column_exists($pdo, 'orders', 'scheduled_machine');
    $hasClientPriority = function_exists('column_exists') && column_exists($pdo, 'orders', 'client_priority');

    $estimatedDurationField = $hasEstimatedDuration ? 'o.estimated_duration' : 'NULL AS estimated_duration';
    $scheduledMachineField = $hasScheduledMachine ? 'o.scheduled_machine' : 'NULL AS scheduled_machine';
    $clientPriorityField = $hasClientPriority ? 'COALESCE(o.client_priority, 0) AS client_priority' : '0 AS client_priority';

    $candidateStmt = $pdo->prepare("\n        SELECT o.id, o.created_at, o.quote_details, {$estimatedDurationField}, {$scheduledMachineField},\n               {$clientPriorityField}\n        FROM orders o\n        WHERE o.status IN ($statusPlaceholders)\n    ");
    $candidateStmt->execute($statuses);
    $candidateOrders = $candidateStmt->fetchAll();

    $upsertStmt = $pdo->prepare("\n        INSERT INTO production_queue (order_id, priority, estimated_duration, scheduled_machine, queue_position)\n        VALUES (?, ?, ?, ?, NULL)\n        ON DUPLICATE KEY UPDATE\n            priority = VALUES(priority),\n            estimated_duration = VALUES(estimated_duration),\n            scheduled_machine = VALUES(scheduled_machine)\n    ");

    foreach ($candidateOrders as $order) {
        $priority = production_queue_calculate_priority($order);
        $upsertStmt->execute([
            (int) $order['id'],
            $priority,
            isset($order['estimated_duration']) ? (int) $order['estimated_duration'] : null,
            $order['scheduled_machine'] ?? null,
        ]);
    }

    $queueRowsStmt = $pdo->query("\n        SELECT id\n        FROM production_queue\n        ORDER BY priority DESC, created_at ASC, id ASC\n    ");
    $queueRows = $queueRowsStmt->fetchAll();

    $updatePositionStmt = $pdo->prepare("UPDATE production_queue SET queue_position = ? WHERE id = ?");
    $position = 1;
    foreach ($queueRows as $queueRow) {
        $updatePositionStmt->execute([$position, (int) $queueRow['id']]);
        $position++;
    }
}

function fetch_production_queue(PDO $pdo, int $shopId): array {
    sync_production_queue($pdo);

    $stmt = $pdo->prepare("\n        SELECT pq.id, pq.order_id, pq.priority, pq.estimated_duration, pq.scheduled_machine, pq.queue_position, pq.created_at,\n               o.order_number, o.service_type, o.status, u.fullname AS client_name\n        FROM production_queue pq\n        JOIN orders o ON o.id = pq.order_id\n        JOIN users u ON u.id = o.client_id\n        WHERE o.shop_id = ?\n          AND o.status NOT IN ('completed', 'cancelled')\n        ORDER BY pq.priority DESC, pq.created_at ASC, pq.id ASC\n    ");
    $stmt->execute([$shopId]);

    return $stmt->fetchAll();
}
