<?php
require_once __DIR__ . '/constants.php';

function qc_table_exists(PDO $pdo): bool {
    static $cache = null;
    if($cache !== null) {
        return $cache;
    }

    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'order_quality_checks'");
        $cache = (bool) ($stmt && $stmt->fetchColumn());
    } catch(Throwable $e) {
        $cache = false;
    }

    return $cache;
}

function qc_create_pending_record(PDO $pdo, int $order_id, ?string $remarks = null): array {
    if($order_id <= 0 || !qc_table_exists($pdo)) {
        return [false, 'QC table not available.', null, false];
    }

    $latest_stmt = $pdo->prepare("SELECT id, qc_status FROM order_quality_checks WHERE order_id = ? ORDER BY id DESC LIMIT 1");
    $latest_stmt->execute([$order_id]);
    $latest = $latest_stmt->fetch();
    if($latest && ($latest['qc_status'] ?? '') === 'pending') {
        return [true, null, (int) $latest['id'], false];
    }

    $insert_stmt = $pdo->prepare("\n        INSERT INTO order_quality_checks (order_id, qc_status, remarks)\n        VALUES (?, 'pending', ?)\n    ");
    $insert_stmt->execute([$order_id, $remarks !== null && trim($remarks) !== '' ? trim($remarks) : null]);

    return [true, null, (int) $pdo->lastInsertId(), true];
}

function qc_complete_record(
    PDO $pdo,
    int $order_id,
    string $qc_status,
    ?string $remarks,
    ?int $checked_by = null,
    ?string $attachment_url = null
): array {
    if($order_id <= 0 || !in_array($qc_status, ['passed', 'failed'], true)) {
        return [false, 'Invalid QC record payload.', null, false];
    }

    if(!qc_table_exists($pdo)) {
        return [false, 'QC table not available.', null, false];
    }

    $pending_stmt = $pdo->prepare("\n        SELECT id\n        FROM order_quality_checks\n        WHERE order_id = ? AND qc_status = 'pending'\n        ORDER BY id DESC\n        LIMIT 1\n    ");
    $pending_stmt->execute([$order_id]);
    $pending_id = (int) ($pending_stmt->fetchColumn() ?: 0);

    if($pending_id > 0) {
        $update_stmt = $pdo->prepare("\n            UPDATE order_quality_checks\n            SET qc_status = ?, remarks = ?, checked_by = ?, checked_at = NOW(), attachment_url = ?, updated_at = NOW()\n            WHERE id = ?\n        ");
        $update_stmt->execute([
            $qc_status,
            $remarks !== null && trim($remarks) !== '' ? trim($remarks) : null,
            $checked_by,
            $attachment_url,
            $pending_id,
        ]);

        return [true, null, $pending_id, false];
    }

    $insert_stmt = $pdo->prepare("\n        INSERT INTO order_quality_checks (order_id, qc_status, remarks, checked_by, checked_at, attachment_url)\n        VALUES (?, ?, ?, ?, NOW(), ?)\n    ");
    $insert_stmt->execute([
        $order_id,
        $qc_status,
        $remarks !== null && trim($remarks) !== '' ? trim($remarks) : null,
        $checked_by,
        $attachment_url,
    ]);

    return [true, null, (int) $pdo->lastInsertId(), true];
}

function fetch_latest_qc_records(PDO $pdo, array $order_ids): array {
    if(empty($order_ids) || !qc_table_exists($pdo)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $stmt = $pdo->prepare("\n        SELECT oqc.*\n        FROM order_quality_checks oqc\n        INNER JOIN (\n            SELECT order_id, MAX(id) AS latest_id\n            FROM order_quality_checks\n            WHERE order_id IN ($placeholders)\n            GROUP BY order_id\n        ) latest ON latest.latest_id = oqc.id\n    ");
    $stmt->execute($order_ids);

    $rows = $stmt->fetchAll();
    $map = [];
    foreach($rows as $row) {
        $map[(int) $row['order_id']] = $row;
    }

    return $map;
}
?>
