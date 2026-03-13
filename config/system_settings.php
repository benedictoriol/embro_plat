<?php

function system_settings_defaults(): array {
    return [
        'platform' => [
            'timezone' => ['value' => 'Asia/Manila', 'type' => 'string'],
            'theme' => ['value' => 'light', 'type' => 'string'],
        ],
        'order_workflow' => [
            'default_digest_frequency' => ['value' => 'daily', 'type' => 'string'],
            'require_design_approval' => ['value' => true, 'type' => 'bool'],
        ],
        'payment' => [
            'default_gateway' => ['value' => 'pesopay', 'type' => 'string'],
            'allow_manual_proof' => ['value' => false, 'type' => 'bool'],
            'allow_cod' => ['value' => true, 'type' => 'bool'],
        ],
        'notification' => [
            'critical_alerts_enabled' => ['value' => true, 'type' => 'bool'],
            'weekly_summary_enabled' => ['value' => true, 'type' => 'bool'],
            'stale_quote_hours' => ['value' => 24, 'type' => 'int'],
            'unpaid_order_hours' => ['value' => 24, 'type' => 'int'],
            'overdue_production_hours' => ['value' => 12, 'type' => 'int'],
            'ready_pickup_hours' => ['value' => 24, 'type' => 'int'],
            'overdue_order_hours' => ['value' => 24, 'type' => 'int'],
            'reminder_cooldown_hours' => ['value' => 24, 'type' => 'int'],
            'overdue_exception_hours' => ['value' => 12, 'type' => 'int'],
        ],
        'moderation' => [
            'auto_hide_flagged_content' => ['value' => true, 'type' => 'bool'],
        ],
        'business_rules' => [
            'min_order_quantity' => ['value' => 1, 'type' => 'int'],
            'max_order_quantity' => ['value' => 1000, 'type' => 'int'],
        ],
    ];
}

function ensure_system_settings_table(PDO $pdo): void {
    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS system_settings (\n            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,\n            setting_group VARCHAR(100) NOT NULL,\n            setting_key VARCHAR(120) NOT NULL,\n            setting_value TEXT DEFAULT NULL,\n            value_type ENUM('string','int','float','bool','json') NOT NULL DEFAULT 'string',\n            description VARCHAR(255) DEFAULT NULL,\n            updated_by INT(11) DEFAULT NULL,\n            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n            UNIQUE KEY uniq_system_setting (setting_group, setting_key),\n            KEY idx_system_settings_updated_by (updated_by),\n            CONSTRAINT fk_system_settings_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci\n    ");

    seed_system_settings_defaults($pdo);
}

function seed_system_settings_defaults(PDO $pdo): void {
    $defaults = system_settings_defaults();
    $stmt = $pdo->prepare("\n        INSERT INTO system_settings (setting_group, setting_key, setting_value, value_type, description)\n        VALUES (?, ?, ?, ?, ?)\n        ON DUPLICATE KEY UPDATE setting_group = VALUES(setting_group)\n    ");

    foreach ($defaults as $group => $items) {
        foreach ($items as $key => $meta) {
            $stmt->execute([
                $group,
                $key,
                system_setting_encode_value($meta['value'], $meta['type']),
                $meta['type'],
                null,
            ]);
        }
    }
}

function system_setting_encode_value(mixed $value, string $type): string {
    return match ($type) {
        'bool' => $value ? '1' : '0',
        'json' => json_encode($value) ?: '[]',
        default => (string) $value,
    };
}

function system_setting_decode_value(?string $value, string $type): mixed {
    if ($value === null) {
        return null;
    }

    return match ($type) {
        'int' => (int) $value,
        'float' => (float) $value,
        'bool' => in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true),
        'json' => json_decode($value, true),
        default => $value,
    };
}

function system_setting_get(PDO $pdo, string $group, string $key, mixed $fallback = null): mixed {
    $stmt = $pdo->prepare("SELECT setting_value, value_type FROM system_settings WHERE setting_group = ? AND setting_key = ? LIMIT 1");
    $stmt->execute([$group, $key]);
    $row = $stmt->fetch();

    if ($row) {
        return system_setting_decode_value($row['setting_value'], $row['value_type']);
    }

    $defaults = system_settings_defaults();
    if (isset($defaults[$group][$key])) {
        $defaultMeta = $defaults[$group][$key];
        return $defaultMeta['value'];
    }

    return $fallback;
}

function system_setting_set(PDO $pdo, string $group, string $key, mixed $value, string $type = 'string', ?int $updatedBy = null): bool {
    $stmt = $pdo->prepare("\n        INSERT INTO system_settings (setting_group, setting_key, setting_value, value_type, updated_by)\n        VALUES (?, ?, ?, ?, ?)\n        ON DUPLICATE KEY UPDATE\n            setting_value = VALUES(setting_value),\n            value_type = VALUES(value_type),\n            updated_by = VALUES(updated_by),\n            updated_at = CURRENT_TIMESTAMP\n    ");

    return $stmt->execute([$group, $key, system_setting_encode_value($value, $type), $type, $updatedBy]);
}

function system_settings_get_group(PDO $pdo, string $group): array {
    $settings = [];
    $defaults = system_settings_defaults()[$group] ?? [];

    foreach ($defaults as $key => $meta) {
        $settings[$key] = $meta['value'];
    }

    $stmt = $pdo->prepare("SELECT setting_key, setting_value, value_type FROM system_settings WHERE setting_group = ?");
    $stmt->execute([$group]);
    foreach ($stmt->fetchAll() as $row) {
        $settings[$row['setting_key']] = system_setting_decode_value($row['setting_value'], $row['value_type']);
    }

    return $settings;
}
