<?php
// config/notification_functions.php

/**
 * Create a notification log entry.
 *
 * @param PDO $pdo
 * @param int $user_id
 * @param int|null $order_id

 * @param string $type
 * @param string $message
 * @return void
 */
function normalize_notification_type(string $type): string {
    $normalized = trim(strtolower($type));

    return match($normalized) {
        'info' => 'order_status',
        default => $normalized,
    };
}

function create_notification(PDO $pdo, int $user_id, ?int $order_id, string $type, string $message): void {
    $type = normalize_notification_type($type);

    $preference_stmt = $pdo->prepare(" 
        SELECT enabled
        FROM notification_preferences
        WHERE user_id = ? AND event_key = ? AND channel = 'in_app'
        LIMIT 1
    ");
    $preference_stmt->execute([$user_id, $type]);
    $preference_enabled = $preference_stmt->fetchColumn();

    if ($preference_enabled !== false && (int) $preference_enabled !== 1) {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, order_id, type, message, read_at, created_at)
        VALUES (?, ?, ?, ?, NULL, NOW())
    ");

    $stmt->execute([$user_id, $order_id, $type, $message]);
}

function has_notification_for_order(PDO $pdo, int $user_id, int $order_id, string $type): bool {
    $type = normalize_notification_type($type);

    $stmt = $pdo->prepare(" 
        SELECT 1
        FROM notifications
        WHERE user_id = ?
          AND order_id = ?
          AND type = ?
        LIMIT 1
    ");
    $stmt->execute([$user_id, $order_id, $type]);

    return (bool) $stmt->fetchColumn();
}

function create_notification_once_for_order(PDO $pdo, int $user_id, int $order_id, string $type, string $message): void {
    if(has_notification_for_order($pdo, $user_id, $order_id, $type)) {
        return;
    }

    create_notification($pdo, $user_id, $order_id, $type, $message);
}

function has_recent_notification_by_type_and_message(PDO $pdo, int $user_id, string $type, string $message, int $lookbackHours = 24): bool {
    $type = normalize_notification_type($type);
    $lookbackHours = max(1, $lookbackHours);

    $stmt = $pdo->prepare(" 
        SELECT 1
        FROM notifications
        WHERE user_id = ?
          AND order_id IS NULL
          AND type = ?
          AND message = ?
          AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
        LIMIT 1
    ");
    $stmt->execute([$user_id, $type, $message, $lookbackHours]);

    return (bool) $stmt->fetchColumn();
}
?>