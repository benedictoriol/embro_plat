<?php
require_once __DIR__ . '/constants.php';

function ensure_production_queue_table(PDO $pdo): void {
    // Schema is managed by migrations.
}

function production_queue_stage_statuses(): array {
    return [STATUS_DIGITIZING, STATUS_PRODUCTION_PENDING, 'production-prep', 'production_prep', 'production prep'];
}

function production_queue_assignment_required_statuses(): array {
    return [STATUS_PRODUCTION_PENDING, STATUS_PRODUCTION, STATUS_PRODUCTION_REWORK, STATUS_QC_PENDING];
}

function production_queue_normalize_status(string $status): string {
    $normalized = strtolower(trim($status));
    if($normalized === 'production-prep' || $normalized === 'production prep') {
        return STATUS_PRODUCTION_PENDING;
    }
    if($normalized === 'in_progress') {
        return STATUS_PRODUCTION;
    }

    return $normalized;
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

function production_queue_waiting_age_hours(?string $updatedAt, ?string $createdAt): int {
    $anchor = $updatedAt ?: $createdAt;
    if(empty($anchor)) {
        return 0;
    }

    $anchorTs = strtotime($anchor);
    if($anchorTs === false) {
        return 0;
    }

    return (int) max(0, floor((time() - $anchorTs) / 3600));
}

function production_queue_rush_weight(?string $quoteDetailsJson): int {
    if (empty($quoteDetailsJson)) {
        return 0;
    }

    $quoteDetails = json_decode($quoteDetailsJson, true);
    if (!is_array($quoteDetails)) {
        return 0;
    }

    $rushFlags = [
        $quoteDetails['rush'] ?? null,
        $quoteDetails['is_rush'] ?? null,
        $quoteDetails['priority'] ?? null,
    ];

    foreach($rushFlags as $flag) {
        if(is_string($flag) && strtolower(trim($flag)) === 'rush') {
            return 80;
        }
        if($flag === true || $flag === 1 || $flag === '1') {
            return 80;
        }
    }

    return 0;
}

function production_queue_due_date_weight(?string $scheduledDate): int {
    if(empty($scheduledDate)) {
        return 0;
    }

    $today = strtotime(date('Y-m-d'));
    $dueTs = strtotime(date('Y-m-d', strtotime($scheduledDate)));
    if($today === false || $dueTs === false) {
        return 0;
    }

    $daysUntilDue = (int) floor(($dueTs - $today) / 86400);
    if($daysUntilDue <= 0) {
        return 70;
    }
    if($daysUntilDue === 1) {
        return 50;
    }
    if($daysUntilDue <= 3) {
        return 30;
    }

    return 0;
}

function production_queue_status_weight(string $status): int {
    return match (production_queue_normalize_status($status)) {
        STATUS_QC_PENDING => 45,
        STATUS_PRODUCTION_REWORK => 40,
        STATUS_PRODUCTION => 35,
        STATUS_PRODUCTION_PENDING => 20,
        STATUS_DIGITIZING => 15,
        default => 0,
    };
}

function production_queue_calculate_priority(array $order): int {
    $rushOrderWeight = production_queue_rush_weight($order['quote_details'] ?? null);
    $clientPriority = (int) ($order['client_priority'] ?? 0);
    $orderAgeDays = production_queue_order_age_days($order['created_at'] ?? null);
    $waitingAgeHours = production_queue_waiting_age_hours($order['updated_at'] ?? null, $order['created_at'] ?? null);
    $dueDateWeight = production_queue_due_date_weight($order['scheduled_date'] ?? null);
    $statusWeight = production_queue_status_weight((string) ($order['status'] ?? ''));

    return $rushOrderWeight
        + $dueDateWeight
        + $statusWeight
        + $clientPriority
        + min(35, $orderAgeDays * 2)
        + min(50, $waitingAgeHours);
}

function production_queue_is_eligible_order(array $order): bool {
    $status = production_queue_normalize_status((string) ($order['status'] ?? ''));
    if(in_array($status, [STATUS_COMPLETED, STATUS_CANCELLED], true)) {
        return false;
    }

    if(!in_array($status, production_queue_stage_statuses(), true)) {
        return false;
    }

    $assignedTo = (int) ($order['assigned_to'] ?? 0);
    $requiresAssignee = in_array($status, production_queue_assignment_required_statuses(), true);
    if($requiresAssignee && $assignedTo <= 0) {
        return false;
    }

    return true;
}

function sync_production_queue(PDO $pdo): void {
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

    $candidateStmt = $pdo->prepare("\n        SELECT o.id, o.created_at, o.updated_at, o.scheduled_date, o.quote_details, o.status, o.assigned_to, {$estimatedDurationField}, {$scheduledMachineField},\n               {$clientPriorityField}\n        FROM orders o\n        WHERE o.status IN ($statusPlaceholders)\n    ");
    $candidateStmt->execute($statuses);
    $candidateOrders = $candidateStmt->fetchAll();

    $upsertStmt = $pdo->prepare("\n        INSERT INTO production_queue (order_id, priority, estimated_duration, scheduled_machine, queue_position)\n        VALUES (?, ?, ?, ?, NULL)\n        ON DUPLICATE KEY UPDATE\n            priority = VALUES(priority),\n            estimated_duration = VALUES(estimated_duration),\n            scheduled_machine = VALUES(scheduled_machine)\n    ");

    $deleteStmt = $pdo->prepare('DELETE FROM production_queue WHERE order_id = ?');

    foreach ($candidateOrders as $order) {
        if(!production_queue_is_eligible_order($order)) {
            $deleteStmt->execute([(int) $order['id']]);
            continue;
        }

        $priority = production_queue_calculate_priority($order);
        $upsertStmt->execute([
            (int) $order['id'],
            $priority,
            isset($order['estimated_duration']) ? (int) $order['estimated_duration'] : null,
            $order['scheduled_machine'] ?? null,
        ]);
    }

    $queueRowsStmt = $pdo->query("\n        SELECT id, order_id\n        FROM production_queue\n        ORDER BY priority DESC, created_at ASC, id ASC\n    ");
    $queueRows = $queueRowsStmt->fetchAll();

    $updatePositionStmt = $pdo->prepare("UPDATE production_queue SET queue_position = ? WHERE id = ?");
    $position = 1;
    $touchedOrderIds = [];
    foreach ($queueRows as $queueRow) {
        $queueId = (int) $queueRow['id'];
        $updatePositionStmt->execute([$position, $queueId]);

        $orderId = (int) ($queueRow['order_id'] ?? 0);
        if($orderId > 0) {
            $touchedOrderIds[$orderId] = true;
        }

        $position++;
    }
    
    if(function_exists('update_order_estimated_completion')) {
        foreach(array_keys($touchedOrderIds) as $orderId) {
            update_order_estimated_completion($pdo, (int) $orderId, ['skip_queue_sync' => true]);
        }
    }
}

function fetch_production_queue(PDO $pdo, int $shopId, ?int $staffUserId = null): array {
    sync_production_queue($pdo);

    $sql = "\n        SELECT pq.id, pq.order_id, pq.priority, pq.estimated_duration, pq.scheduled_machine, pq.queue_position, pq.created_at,\n               o.order_number, o.service_type, o.status, o.assigned_to, u.fullname AS client_name\n        FROM production_queue pq\n        JOIN orders o ON o.id = pq.order_id\n        JOIN users u ON u.id = o.client_id\n        WHERE o.shop_id = ?\n          AND o.status NOT IN ('completed', 'cancelled')\n    ";

    $params = [$shopId];
    if($staffUserId !== null && $staffUserId > 0) {
        $sql .= " AND o.assigned_to = ?";
        $params[] = $staffUserId;
    }

    $sql .= " ORDER BY pq.priority DESC, pq.created_at ASC, pq.id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}
