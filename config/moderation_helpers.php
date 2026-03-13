<?php

function ensure_content_moderation_schema(PDO $pdo): void {
    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS content_reports (\n            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,\n            reporter_user_id INT(11) NOT NULL,\n            target_entity_type VARCHAR(50) NOT NULL,\n            target_entity_id INT(11) NOT NULL,\n            reason VARCHAR(150) NOT NULL,\n            notes TEXT DEFAULT NULL,\n            status ENUM('pending','reviewing','resolved','dismissed') NOT NULL DEFAULT 'pending',\n            reviewed_by INT(11) DEFAULT NULL,\n            reviewed_at DATETIME DEFAULT NULL,\n            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n            KEY idx_content_reports_status (status),\n            KEY idx_content_reports_target (target_entity_type, target_entity_id),\n            KEY idx_content_reports_reporter (reporter_user_id),\n            KEY idx_content_reports_reviewed_by (reviewed_by),\n            CONSTRAINT fk_content_reports_reporter FOREIGN KEY (reporter_user_id) REFERENCES users(id) ON DELETE CASCADE,\n            CONSTRAINT fk_content_reports_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci\n    ");

    ensure_moderation_columns_on_content($pdo, 'client_community_posts');
    ensure_moderation_columns_on_content($pdo, 'community_post_comments');
}

function ensure_moderation_columns_on_content(PDO $pdo, string $table): void {
    if (!table_exists($pdo, $table)) {
        return;
    }

    $columns = [
        'is_hidden' => "TINYINT(1) NOT NULL DEFAULT 0",
        'is_removed' => "TINYINT(1) NOT NULL DEFAULT 0",
        'moderation_note' => "VARCHAR(255) DEFAULT NULL",
        'moderated_by' => "INT(11) DEFAULT NULL",
        'moderated_at' => "DATETIME DEFAULT NULL",
    ];

    foreach ($columns as $column => $definition) {
        if (!column_exists($pdo, $table, $column)) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    try {
        $pdo->exec("ALTER TABLE {$table} ADD KEY idx_{$table}_hidden (is_hidden, is_removed)");
    } catch (Throwable $e) {
        // Ignore if key already exists in environments with older patches.
    }
}

function moderation_target_config(string $entityType): ?array {
    return match ($entityType) {
        'community_post' => ['table' => 'client_community_posts', 'id_column' => 'id', 'label' => 'Community post'],
        'community_comment' => ['table' => 'community_post_comments', 'id_column' => 'id', 'label' => 'Community comment'],
        default => null,
    };
}

function moderation_fetch_target(PDO $pdo, string $entityType, int $entityId): ?array {
    $target = moderation_target_config($entityType);
    if ($target === null || $entityId <= 0 || !table_exists($pdo, $target['table'])) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM {$target['table']} WHERE {$target['id_column']} = ? LIMIT 1");
    $stmt->execute([$entityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function moderation_create_report(PDO $pdo, int $reporterId, string $entityType, int $entityId, string $reason, string $notes = ''): array {
    $reason = trim($reason);
    $notes = trim($notes);

    if ($reporterId <= 0 || $reason === '') {
        return ['ok' => false, 'message' => 'Missing required report details.'];
    }

    $target = moderation_fetch_target($pdo, $entityType, $entityId);
    if ($target === null) {
        return ['ok' => false, 'message' => 'The reported content could not be found.'];
    }

    $dupeStmt = $pdo->prepare("\n        SELECT id\n        FROM content_reports\n        WHERE reporter_user_id = ?\n          AND target_entity_type = ?\n          AND target_entity_id = ?\n          AND status IN ('pending','reviewing')\n        LIMIT 1\n    ");
    $dupeStmt->execute([$reporterId, $entityType, $entityId]);
    if ($dupeStmt->fetchColumn()) {
        return ['ok' => false, 'message' => 'You already have an active report for this content.'];
    }

    $insertStmt = $pdo->prepare("\n        INSERT INTO content_reports\n            (reporter_user_id, target_entity_type, target_entity_id, reason, notes, status, created_at, updated_at)\n        VALUES (?, ?, ?, ?, ?, 'pending', NOW(), NOW())\n    ");
    $insertStmt->execute([$reporterId, $entityType, $entityId, $reason, $notes !== '' ? $notes : null]);
    $reportId = (int) $pdo->lastInsertId();

    if (function_exists('log_audit')) {
        log_audit($pdo, $reporterId, $_SESSION['user']['role'] ?? null, 'report_submitted', 'content_report', $reportId, [], [
            'target_entity_type' => $entityType,
            'target_entity_id' => $entityId,
            'reason' => $reason,
            'notes' => $notes,
            'status' => 'pending',
        ]);
    }

    return ['ok' => true, 'report_id' => $reportId];
}

function moderation_apply_content_action(PDO $pdo, string $entityType, int $entityId, string $action, int $moderatorId, string $note = ''): bool {
    $target = moderation_target_config($entityType);
    if ($target === null || $entityId <= 0) {
        return false;
    }

    $updates = match ($action) {
        'hide' => ['is_hidden' => 1, 'is_removed' => 0],
        'remove' => ['is_hidden' => 1, 'is_removed' => 1],
        'restore' => ['is_hidden' => 0, 'is_removed' => 0],
        default => null,
    };

    if ($updates === null) {
        return false;
    }

    $stmt = $pdo->prepare("\n        UPDATE {$target['table']}\n        SET is_hidden = ?,\n            is_removed = ?,\n            moderation_note = ?,\n            moderated_by = ?,\n            moderated_at = NOW()\n        WHERE {$target['id_column']} = ?\n    ");

    return $stmt->execute([
        $updates['is_hidden'],
        $updates['is_removed'],
        $note !== '' ? $note : null,
        $moderatorId,
        $entityId,
    ]);
}
