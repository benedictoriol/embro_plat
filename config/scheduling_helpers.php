<?php

function ensure_machine_scheduling_tables(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS machines (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            shop_id INT(11) NOT NULL,
            machine_name VARCHAR(150) NOT NULL,
            max_stitches_per_hour INT(11) NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_machines_shop (shop_id),
            KEY idx_machines_status (status),
            CONSTRAINT fk_machines_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS machine_jobs (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            machine_id INT(11) NOT NULL,
            order_id INT(11) NOT NULL,
            estimated_stitches INT(11) NOT NULL,
            scheduled_start DATETIME NOT NULL,
            scheduled_end DATETIME NOT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'scheduled',
            KEY idx_machine_jobs_machine (machine_id),
            KEY idx_machine_jobs_order (order_id),
            KEY idx_machine_jobs_status (status),
            CONSTRAINT fk_machine_jobs_machine FOREIGN KEY (machine_id) REFERENCES machines(id) ON DELETE CASCADE,
            CONSTRAINT fk_machine_jobs_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
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
    ensure_machine_scheduling_tables($pdo);

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
