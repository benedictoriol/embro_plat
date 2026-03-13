<?php

function ensure_orders_estimated_completion_column(PDO $pdo): void {
    // Schema is managed by migrations.
}

function estimate_order_completion(PDO $pdo, int $orderId, array $options = []): array {
    $fallbackHours = max(2.0, (float) ($options['fallback_hours'] ?? 24.0));
    $now = new DateTime('now');

    $default = [
        'estimated_completion' => (clone $now)->modify('+' . (int) round($fallbackHours * 3600) . ' seconds')->format('Y-m-d H:i:s'),
        'components' => [
            'digitizing_time_hours' => 4.0,
            'production_time_hours' => 8.0,
            'queue_wait_time_hours' => 8.0,
            'delivery_time_hours' => 4.0,
            'machine_speed' => null,
            'stitch_count' => null,
            'queue_position' => null,
            'status' => null,
        ],
        'used_fallback' => true,
    ];

    if($orderId <= 0) {
        return $default;
    }

    try {
        $orderStmt = $pdo->prepare("SELECT id, shop_id, status, progress, quote_details, scheduled_date FROM orders WHERE id = ? LIMIT 1");
        $orderStmt->execute([$orderId]);
        $order = $orderStmt->fetch();
        if(!$order) {
            return $default;
        }

        $status = strtolower(trim((string) ($order['status'] ?? 'pending')));
        if($status === 'cancelled') {
            return [
                'estimated_completion' => null,
                'components' => [
                    'digitizing_time_hours' => 0.0,
                    'production_time_hours' => 0.0,
                    'queue_wait_time_hours' => 0.0,
                    'delivery_time_hours' => 0.0,
                    'machine_speed' => null,
                    'stitch_count' => null,
                    'queue_position' => null,
                    'status' => $status,
                ],
                'used_fallback' => false,
            ];
        }

        $stitchCount = max(1, fetch_order_stitch_estimate($pdo, $orderId));

        $machineStmt = $pdo->prepare("\n            SELECT m.max_stitches_per_hour\n            FROM machine_jobs mj\n            JOIN machines m ON m.id = mj.machine_id\n            WHERE mj.order_id = ?\n            ORDER BY mj.id DESC\n            LIMIT 1\n        ");
        $machineStmt->execute([$orderId]);
        $machineSpeed = (int) ($machineStmt->fetchColumn() ?: 0);

        if($machineSpeed <= 0) {
            $avgMachineStmt = $pdo->prepare("\n                SELECT ROUND(AVG(max_stitches_per_hour))\n                FROM machines\n                WHERE shop_id = ?\n                  AND status = 'active'\n                  AND max_stitches_per_hour > 0\n            ");
            $avgMachineStmt->execute([(int) ($order['shop_id'] ?? 0)]);
            $machineSpeed = (int) ($avgMachineStmt->fetchColumn() ?: 0);
        }
        if($machineSpeed <= 0) {
            $machineSpeed = 12000;
        }

        $productionTimeHours = max(0.5, $stitchCount / $machineSpeed);

        $queuePosition = null;
        $queueStmt = $pdo->prepare("SELECT queue_position FROM production_queue WHERE order_id = ? LIMIT 1");
        $queueStmt->execute([$orderId]);
        $queuePositionRaw = $queueStmt->fetchColumn();
        if($queuePositionRaw !== false && $queuePositionRaw !== null) {
            $queuePosition = max(1, (int) $queuePositionRaw);
        }

        $queueWaitTimeHours = 0.0;
        if($queuePosition !== null) {
            $queueWaitTimeHours = max(0, $queuePosition - 1) * $productionTimeHours;
        }

        $digitizingTimeHours = 2.0 + min(8.0, $stitchCount / 10000);

        $deliveryTimeHours = 0.0;
        $fulfillmentStmt = $pdo->prepare("SELECT fulfillment_type, status FROM order_fulfillments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
        $fulfillmentStmt->execute([$orderId]);
        $fulfillment = $fulfillmentStmt->fetch();
        $fulfillmentType = strtolower((string) ($fulfillment['fulfillment_type'] ?? 'pickup'));
        $fulfillmentStatus = strtolower((string) ($fulfillment['status'] ?? 'pending'));
        if($fulfillmentType === 'delivery' && !in_array($fulfillmentStatus, ['delivered', 'claimed'], true)) {
            $deliveryTimeHours = 24.0;
        }

        $progress = max(0, min(100, (int) ($order['progress'] ?? 0)));
        $remainingProductionHours = $productionTimeHours;
        if($status === 'in_progress') {
            $remainingProductionHours = max(0.5, $productionTimeHours * (1 - ($progress / 100)));
            $digitizingTimeHours = 0.0;
            $queueWaitTimeHours = 0.0;
        } elseif($status === 'digitizing') {
            $digitizingTimeHours = max(1.0, $digitizingTimeHours * 0.4);
        } elseif($status === 'accepted' || $status === 'pending') {
            // keep all components
        } elseif($status === 'completed') {
            $digitizingTimeHours = 0.0;
            $queueWaitTimeHours = 0.0;
            $remainingProductionHours = 0.0;
        }

        $totalHours = $digitizingTimeHours + $queueWaitTimeHours + $remainingProductionHours + $deliveryTimeHours;
        if($totalHours <= 0) {
            $totalHours = $fallbackHours;
        }

        $completionAt = (clone $now)->modify('+' . (int) ceil($totalHours * 3600) . ' seconds');

        return [
            'estimated_completion' => $completionAt->format('Y-m-d H:i:s'),
            'components' => [
                'digitizing_time_hours' => round($digitizingTimeHours, 2),
                'production_time_hours' => round($remainingProductionHours, 2),
                'queue_wait_time_hours' => round($queueWaitTimeHours, 2),
                'delivery_time_hours' => round($deliveryTimeHours, 2),
                'machine_speed' => $machineSpeed,
                'stitch_count' => $stitchCount,
                'queue_position' => $queuePosition,
                'status' => $status,
            ],
            'used_fallback' => false,
        ];
    } catch(Throwable $e) {
        return $default;
    }
}

function update_order_estimated_completion(PDO $pdo, int $orderId, array $options = []): ?string {
    if($orderId <= 0) {
        return null;
    }

    if(function_exists('ensure_orders_estimated_completion_column')) {
    }
    if(function_exists('column_exists') && !column_exists($pdo, 'orders', 'estimated_completion')) {
        return null;
    }

    if(empty($options['skip_queue_sync']) && function_exists('sync_production_queue')) {
        sync_production_queue($pdo);
    }

    $estimate = estimate_order_completion($pdo, $orderId, $options);
    $estimatedCompletion = $estimate['estimated_completion'] ?? null;

    $updateStmt = $pdo->prepare("UPDATE orders SET estimated_completion = ?, updated_at = NOW() WHERE id = ?");
    $updateStmt->execute([$estimatedCompletion, $orderId]);

    return $estimatedCompletion;
}

function ensure_machine_scheduling_tables(PDO $pdo): void {
    // Schema is managed by migrations.
}

function fetch_order_stitch_estimate(PDO $pdo, int $orderId): int {
    $stmt = $pdo->prepare("SELECT stitch_count FROM digitized_designs WHERE order_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$orderId]);
    $stitchCount = (int) $stmt->fetchColumn();

    if($stitchCount > 0) {
        return $stitchCount;
    }

    $fallbackStmt = $pdo->prepare("SELECT quantity FROM orders WHERE id = ? LIMIT 1");
    $fallbackStmt->execute([$orderId]);
    $qty = max(1, (int) $fallbackStmt->fetchColumn());

    return $qty * 1000;
}

function auto_assign_order_to_machine(PDO $pdo, int $shopId, int $orderId): array {

    $existingStmt = $pdo->prepare("
        SELECT mj.*, m.machine_name
        FROM machine_jobs mj
        JOIN machines m ON m.id = mj.machine_id
        WHERE mj.order_id = ?
        ORDER BY mj.id DESC
        LIMIT 1
    ");
    $existingStmt->execute([$orderId]);
    $existing = $existingStmt->fetch();

    if($existing) {
        return [
            'assigned' => true,
            'skipped' => true,
            'message' => 'Machine schedule already exists for this order.',
            'machine_job' => $existing,
        ];
    }

    $machineStmt = $pdo->prepare("
        SELECT
            m.id,
            m.machine_name,
            m.max_stitches_per_hour,
            COALESCE(SUM(mj.estimated_stitches / NULLIF(m.max_stitches_per_hour, 0)), 0) AS workload_hours
        FROM machines m
        LEFT JOIN machine_jobs mj
            ON mj.machine_id = m.id
            AND mj.status IN ('scheduled', 'in_progress')
        WHERE m.shop_id = ?
          AND m.status = 'active'
          AND m.max_stitches_per_hour > 0
        GROUP BY m.id, m.machine_name, m.max_stitches_per_hour
        ORDER BY workload_hours ASC, m.id ASC
        LIMIT 1
    ");
    $machineStmt->execute([$shopId]);
    $machine = $machineStmt->fetch();

    if(!$machine) {
        return [
            'assigned' => false,
            'message' => 'No active machines are available for auto-assignment.',
        ];
    }

    $estimatedStitches = fetch_order_stitch_estimate($pdo, $orderId);
    $machineRate = (int) $machine['max_stitches_per_hour'];
    if($machineRate <= 0) {
        return [
            'assigned' => false,
            'message' => 'Selected machine has no valid stitch capacity.',
        ];
    }

    $nextSlotStmt = $pdo->prepare("
        SELECT MAX(scheduled_end)
        FROM machine_jobs
        WHERE machine_id = ?
          AND status IN ('scheduled', 'in_progress')
    ");
    $nextSlotStmt->execute([(int) $machine['id']]);
    $latestEnd = $nextSlotStmt->fetchColumn();

    $startAt = new DateTime('now');
    if($latestEnd) {
        $queueEnd = new DateTime($latestEnd);
        if($queueEnd > $startAt) {
            $startAt = $queueEnd;
        }
    }

    $durationHours = $estimatedStitches / $machineRate;
    $durationSeconds = max(60, (int) ceil($durationHours * 3600));
    $endAt = (clone $startAt)->modify('+' . $durationSeconds . ' seconds');

    $insertStmt = $pdo->prepare("
        INSERT INTO machine_jobs (machine_id, order_id, estimated_stitches, scheduled_start, scheduled_end, status)
        VALUES (?, ?, ?, ?, ?, 'scheduled')
    ");
    $insertStmt->execute([
        (int) $machine['id'],
        $orderId,
        $estimatedStitches,
        $startAt->format('Y-m-d H:i:s'),
        $endAt->format('Y-m-d H:i:s'),
    ]);

    if(function_exists('update_order_estimated_completion')) {
        update_order_estimated_completion($pdo, $orderId);
    }

    return [
        'assigned' => true,
        'message' => sprintf('Order auto-assigned to machine %s.', $machine['machine_name']),
        'machine_job' => [
            'id' => (int) $pdo->lastInsertId(),
            'machine_id' => (int) $machine['id'],
            'machine_name' => $machine['machine_name'],
            'estimated_stitches' => $estimatedStitches,
            'scheduled_start' => $startAt->format('Y-m-d H:i:s'),
            'scheduled_end' => $endAt->format('Y-m-d H:i:s'),
            'status' => 'scheduled',
            'duration_hours' => round($durationHours, 2),
        ],
    ];
}
