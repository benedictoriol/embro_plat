<?php

function ensure_client_profile_tables(PDO $pdo): void {
    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS client_profiles (\n            client_id INT(11) NOT NULL PRIMARY KEY,\n            first_name VARCHAR(100) DEFAULT NULL,\n            middle_name VARCHAR(100) DEFAULT NULL,\n            last_name VARCHAR(100) DEFAULT NULL,\n            contact_email VARCHAR(150) DEFAULT NULL,\n            billing_contact_name VARCHAR(150) DEFAULT NULL,\n            billing_phone VARCHAR(20) DEFAULT NULL,\n            billing_email VARCHAR(150) DEFAULT NULL,\n            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n            CONSTRAINT fk_client_profiles_user FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE CASCADE\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci\n    ");

    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS client_addresses (\n            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,\n            client_id INT(11) NOT NULL,\n            label VARCHAR(100) DEFAULT NULL,\n            recipient_name VARCHAR(150) DEFAULT NULL,\n            phone VARCHAR(20) DEFAULT NULL,\n            country VARCHAR(100) NOT NULL,\n            province VARCHAR(120) NOT NULL,\n            city VARCHAR(120) NOT NULL,\n            barangay VARCHAR(120) NOT NULL,\n            street_address VARCHAR(255) NOT NULL,\n            address_line2 VARCHAR(255) DEFAULT NULL,\n            postal_code VARCHAR(20) DEFAULT NULL,\n            is_default TINYINT(1) NOT NULL DEFAULT 0,\n            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n            KEY idx_client_addresses_client (client_id),\n            KEY idx_client_addresses_default (client_id, is_default),\n            CONSTRAINT fk_client_addresses_user FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE CASCADE\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci\n    ");

    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS client_payment_preferences (\n            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,\n            client_id INT(11) NOT NULL,\n            payment_method ENUM('gcash','card','cod','pickup') NOT NULL,\n            account_name VARCHAR(150) DEFAULT NULL,\n            account_identifier VARCHAR(120) DEFAULT NULL,\n            is_enabled TINYINT(1) NOT NULL DEFAULT 0,\n            is_default TINYINT(1) NOT NULL DEFAULT 0,\n            verification_status ENUM('not_required','pending','verified') NOT NULL DEFAULT 'not_required',\n            verified_at DATETIME DEFAULT NULL,\n            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n            UNIQUE KEY uq_client_payment_method (client_id, payment_method),\n            KEY idx_client_payment_default (client_id, is_default),\n            CONSTRAINT fk_client_payment_user FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE CASCADE\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci\n    ");
}

function fetch_client_profile(PDO $pdo, int $clientId): array {
    $stmt = $pdo->prepare("SELECT * FROM client_profiles WHERE client_id = ? LIMIT 1");
    $stmt->execute([$clientId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function fetch_client_addresses(PDO $pdo, int $clientId): array {
    $stmt = $pdo->prepare("SELECT * FROM client_addresses WHERE client_id = ? ORDER BY is_default DESC, updated_at DESC, id DESC");
    $stmt->execute([$clientId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fetch_client_default_address(PDO $pdo, int $clientId): ?array {
    $stmt = $pdo->prepare("SELECT * FROM client_addresses WHERE client_id = ? ORDER BY is_default DESC, updated_at DESC, id DESC LIMIT 1");
    $stmt->execute([$clientId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function fetch_client_payment_preferences(PDO $pdo, int $clientId): array {
    $stmt = $pdo->prepare("SELECT * FROM client_payment_preferences WHERE client_id = ?");
    $stmt->execute([$clientId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $map = [];
    foreach ($rows as $row) {
        $map[(string) $row['payment_method']] = $row;
    }

    foreach (['gcash', 'card', 'cod', 'pickup'] as $method) {
        if (!isset($map[$method])) {
            $map[$method] = [
                'client_id' => $clientId,
                'payment_method' => $method,
                'account_name' => null,
                'account_identifier' => null,
                'is_enabled' => in_array($method, ['cod', 'pickup'], true) ? 1 : 0,
                'is_default' => 0,
                'verification_status' => in_array($method, ['gcash', 'card'], true) ? 'pending' : 'not_required',
                'verified_at' => null,
            ];
        }
    }

    return $map;
}

function upsert_client_payment_preferences(PDO $pdo, int $clientId, array $preferences): void {
    $stmt = $pdo->prepare("\n        INSERT INTO client_payment_preferences\n            (client_id, payment_method, account_name, account_identifier, is_enabled, is_default, verification_status, verified_at)\n        VALUES (?, ?, ?, ?, ?, ?, ?, ?)\n        ON DUPLICATE KEY UPDATE\n            account_name = VALUES(account_name),\n            account_identifier = VALUES(account_identifier),\n            is_enabled = VALUES(is_enabled),\n            is_default = VALUES(is_default),\n            verification_status = VALUES(verification_status),\n            verified_at = VALUES(verified_at),\n            updated_at = CURRENT_TIMESTAMP\n    ");

    foreach ($preferences as $method => $pref) {
        $stmt->execute([
            $clientId,
            $method,
            $pref['account_name'] ?? null,
            $pref['account_identifier'] ?? null,
            (int) ($pref['is_enabled'] ?? 0),
            (int) ($pref['is_default'] ?? 0),
            $pref['verification_status'] ?? 'not_required',
            $pref['verified_at'] ?? null,
        ]);
    }
}

function normalize_full_name_from_parts(string $firstName, string $middleName, string $lastName): string {
    $parts = array_filter([
        trim($firstName),
        trim($middleName),
        trim($lastName),
    ], static fn(string $part): bool => $part !== '');

    return trim(implode(' ', $parts));
}
