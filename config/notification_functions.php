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

function notification_table_columns(PDO $pdo): array {
    static $cache = null;

    if($cache !== null) {
        return $cache;
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM notifications");
        $columns = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        $cache = array_fill_keys(array_map('strval', $columns), true);
    } catch(Throwable $error) {
        $cache = [
            'user_id' => true,
            'order_id' => true,
            'type' => true,
            'message' => true,
            'read_at' => true,
            'created_at' => true,
        ];
    }

    return $cache;
}

function notification_unread_sql_condition(PDO $pdo, string $tableAlias = ''): string {
    $columns = notification_table_columns($pdo);
    $prefix = $tableAlias !== '' ? rtrim($tableAlias, '.') . '.' : '';

    if(isset($columns['read_at'])) {
        return $prefix . 'read_at IS NULL';
    }

    if(isset($columns['is_read'])) {
        return 'COALESCE(' . $prefix . 'is_read, 0) = 0';
    }

    return '1 = 1';
}

function mark_all_notifications_read(PDO $pdo, int $user_id): void {
    $columns = notification_table_columns($pdo);

    if(isset($columns['read_at'])) {
        $stmt = $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND " . notification_unread_sql_condition($pdo));
        $stmt->execute([$user_id]);
        return;
    }

    if(isset($columns['is_read'])) {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND " . notification_unread_sql_condition($pdo));
        $stmt->execute([$user_id]);
    }
}

function fetch_notifications_for_user(PDO $pdo, int $user_id, int $limit = 50): array {
    $limit = max(1, min(200, $limit));
    $isReadExpr = notification_unread_sql_condition($pdo, 'n');

    $stmt = $pdo->prepare("
        SELECT n.*, CASE WHEN " . $isReadExpr . " THEN 0 ELSE 1 END AS is_read
        FROM notifications n
        WHERE n.user_id = ?
        ORDER BY n.created_at DESC
        LIMIT " . $limit . "
    ");
    $stmt->execute([$user_id]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function create_notification(PDO $pdo, int $user_id, ?int $order_id, string $type, string $message): void {
    $type = normalize_notification_type($type);

    $preference_enabled = false;
    if (table_exists($pdo, 'notification_preferences')) {
        try {
            $preference_stmt = $pdo->prepare(" 
                SELECT enabled
                FROM notification_preferences
                WHERE user_id = ? AND event_key = ? AND channel = 'in_app'
                LIMIT 1
            ");
            $preference_stmt->execute([$user_id, $type]);
            $preference_enabled = $preference_stmt->fetchColumn();
        } catch (Throwable $error) {
            $preference_enabled = false;
        }
    }

    if ($preference_enabled !== false && (int) $preference_enabled !== 1) {
        return;
    }

    $columns = notification_table_columns($pdo);
    $insertColumns = ['user_id', 'type', 'message'];
    $insertValues = [$user_id, $type, $message];

    if(isset($columns['order_id'])) {
        $insertColumns[] = 'order_id';
        $insertValues[] = $order_id;
    }

    if(isset($columns['read_at'])) {
        $insertColumns[] = 'read_at';
        $insertValues[] = null;
    }

    if(isset($columns['is_read'])) {
        $insertColumns[] = 'is_read';
        $insertValues[] = 0;
    }

    if(isset($columns['created_at'])) {
        $insertColumns[] = 'created_at';
        $insertValues[] = date('Y-m-d H:i:s');
    }

    $placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));
    $sql = 'INSERT INTO notifications (' . implode(', ', $insertColumns) . ') VALUES (' . $placeholders . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($insertValues);
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

function has_recent_notification_for_order_message(PDO $pdo, int $user_id, int $order_id, string $type, string $message, int $lookbackMinutes = 5): bool {
    $type = normalize_notification_type($type);
    $lookbackMinutes = max(1, $lookbackMinutes);

    $stmt = $pdo->prepare(" 
        SELECT 1
        FROM notifications
        WHERE user_id = ?
          AND order_id = ?
          AND type = ?
          AND message = ?
          AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
        LIMIT 1
    ");
    $stmt->execute([$user_id, $order_id, $type, $message, $lookbackMinutes]);

    return (bool) $stmt->fetchColumn();
}

function create_notification_once_for_order(PDO $pdo, int $user_id, int $order_id, string $type, string $message): void {
    if(has_notification_for_order($pdo, $user_id, $order_id, $type)) {
        return;
    }

    create_notification($pdo, $user_id, $order_id, $type, $message);
}

function create_notification_recent_once_for_order(PDO $pdo, int $user_id, int $order_id, string $type, string $message, int $lookbackMinutes = 5): void {
    if(has_recent_notification_for_order_message($pdo, $user_id, $order_id, $type, $message, $lookbackMinutes)) {
        return;
    }

    create_notification($pdo, $user_id, $order_id, $type, $message);
}

function notify_order_cancellation_parties(PDO $pdo, int $order_id, int $client_id, ?int $owner_id, string $order_number): void {
    if($order_id <= 0 || $client_id <= 0 || $order_number === '') {
        return;
    }

    create_notification_once_for_order(
        $pdo,
        $client_id,
        $order_id,
        'warning',
        'Order #' . $order_number . ' was cancelled per your request.'
    );

    if(!empty($owner_id)) {
        create_notification_once_for_order(
            $pdo,
            (int) $owner_id,
            $order_id,
            'order_status',
            'Order #' . $order_number . ' was cancelled by the client.'
        );
    }
}


function notify_support_ticket_update(PDO $pdo, array $ticket, string $actionLabel, ?int $actorId = null): void {
    $ticketId = (int) ($ticket['id'] ?? 0);
    $orderId = (int) ($ticket['order_id'] ?? 0);
    $orderNumber = (string) ($ticket['order_number'] ?? $orderId);
    $clientId = (int) ($ticket['client_id'] ?? 0);
    $ownerId = !empty($ticket['owner_id']) ? (int) $ticket['owner_id'] : null;
    $staffId = !empty($ticket['assigned_staff_id']) ? (int) $ticket['assigned_staff_id'] : null;

    if($ticketId <= 0 || $orderId <= 0 || $clientId <= 0) {
        return;
    }

    $message = sprintf('Support ticket #%d for order #%s %s.', $ticketId, $orderNumber, $actionLabel);

    $targets = [$clientId];
    if($ownerId) {
        $targets[] = $ownerId;
    }
    if($staffId) {
        $targets[] = $staffId;
    }

    $targets = array_values(array_unique(array_filter($targets, static fn($id) => (int) $id > 0)));

    foreach($targets as $userId) {
        if($actorId !== null && $userId === $actorId) {
            continue;
        }
        create_notification_recent_once_for_order($pdo, $userId, $orderId, 'warning', $message, 2);
    }
}

function notify_dispute_update(PDO $pdo, int $order_id, string $actionLabel, ?int $actorId = null): void {
    $order = fetch_order_notification_context($pdo, $order_id);
    if(!$order) {
        return;
    }

    $orderNumber = (string) ($order['order_number'] ?? $order_id);
    $clientId = (int) ($order['client_id'] ?? 0);
    $ownerId = (int) ($order['owner_id'] ?? 0);
    $assignedTo = (int) ($order['assigned_to'] ?? 0);

    $message = sprintf('Dispute update for order #%s: %s.', $orderNumber, $actionLabel);
    $targets = array_values(array_unique(array_filter([$clientId, $ownerId, $assignedTo], static fn($id) => (int) $id > 0)));

    foreach($targets as $userId) {
        if($actorId !== null && $userId === $actorId) {
            continue;
        }
        create_notification_recent_once_for_order($pdo, (int) $userId, $order_id, 'warning', $message, 10);
    }
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

function fetch_order_notification_context(PDO $pdo, int $order_id): ?array {
    if($order_id <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("\n        SELECT o.id, o.order_number, o.client_id, o.assigned_to, o.shop_id, s.owner_id
        FROM orders o
        JOIN shops s ON s.id = o.shop_id
        WHERE o.id = ?
        LIMIT 1
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    return $order ?: null;
}

function fetch_shop_hr_user_ids(PDO $pdo, int $shop_id): array {
    if($shop_id <= 0) {
        return [];
    }

    $stmt = $pdo->prepare("\n        SELECT ss.user_id
        FROM shop_staffs ss
        JOIN users u ON u.id = ss.user_id
        WHERE ss.shop_id = ?
          AND ss.staff_role = 'hr'
          AND ss.status = 'active'
          AND u.status = 'active'
    ");
    $stmt->execute([$shop_id]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function notify_business_event(PDO $pdo, string $event_key, int $order_id, array $context = []): void {
    $order = fetch_order_notification_context($pdo, $order_id);
    if(!$order) {
        return;
    }

    $order_number = (string) ($order['order_number'] ?? $order_id);
    $owner_id = (int) ($order['owner_id'] ?? 0);
    $client_id = (int) ($order['client_id'] ?? 0);
    $assigned_to = (int) ($order['assigned_to'] ?? 0);
    $shop_id = (int) ($order['shop_id'] ?? 0);
    $actor_id = isset($context['actor_id']) ? (int) $context['actor_id'] : 0;

    switch($event_key) {
        case 'order_submitted':
            if($owner_id > 0) {
                create_notification_recent_once_for_order(
                    $pdo,
                    $owner_id,
                    $order_id,
                    'order_status',
                    'New order #' . $order_number . ' has been placed and is awaiting your review.',
                    30
                );
            }
            break;

        case 'order_accepted':
            if($client_id > 0) {
                create_notification_recent_once_for_order($pdo, $client_id, $order_id, 'order_status', (string) ($context['client_message'] ?? ('Your order #' . $order_number . ' was accepted.')), 30);
            }
            if($owner_id > 0 && $owner_id !== $actor_id) {
                create_notification_recent_once_for_order($pdo, $owner_id, $order_id, 'order_status', (string) ($context['owner_message'] ?? ('Order #' . $order_number . ' was accepted and can proceed.')), 30);
            }
            break;

        case 'quote_sent':
        case 'order_quote_issued':
            if($client_id > 0) {
                create_notification_recent_once_for_order(
                    $pdo,
                    $client_id,
                    $order_id,
                    'order_status',
                    (string) ($context['client_message'] ?? ('Quote sent for order #' . $order_number . '. Please review and approve.')),
                    30
                );
            }
            if($owner_id > 0 && $owner_id !== $actor_id) {
                create_notification_recent_once_for_order(
                    $pdo,
                    $owner_id,
                    $order_id,
                    'order_status',
                    (string) ($context['owner_message'] ?? ('Quote was sent for order #' . $order_number . '.')),
                    30
                );
            }
            break;

        case 'quote_awaiting_approval':
            if($client_id > 0) {
                create_notification_recent_once_for_order($pdo, $client_id, $order_id, 'order_status', (string) ($context['client_message'] ?? ('Order #' . $order_number . ' quote is awaiting your approval.')), 60);
            }
            if($owner_id > 0 && $owner_id !== $actor_id) {
                create_notification_recent_once_for_order($pdo, $owner_id, $order_id, 'order_status', (string) ($context['owner_message'] ?? ('Order #' . $order_number . ' is awaiting client quote approval.')), 60);
            }
            break;

        case 'payment_awaiting_verification':
            if($owner_id > 0) {
                create_notification_recent_once_for_order($pdo, $owner_id, $order_id, 'payment', (string) ($context['owner_message'] ?? ('Payment submitted for order #' . $order_number . ' is awaiting verification.')), 15);
            }
            if($client_id > 0 && !empty($context['notify_client'])) {
                create_notification_recent_once_for_order($pdo, $client_id, $order_id, 'payment', (string) ($context['client_message'] ?? ('Payment proof for order #' . $order_number . ' was submitted and is awaiting verification.')), 15);
            }
            break;

        case 'order_rejected':
            if($client_id > 0) {
                create_notification_recent_once_for_order($pdo, $client_id, $order_id, 'warning', (string) ($context['client_message'] ?? ('Your order #' . $order_number . ' was rejected.')), 30);
            }
            break;

        case 'staff_assigned':
            if($assigned_to > 0) {
                create_notification_recent_once_for_order($pdo, $assigned_to, $order_id, 'order_status', 'You have been assigned to order #' . $order_number . '.', 60);
            }
            break;

        case 'production_update':
            $message = (string) ($context['message'] ?? ('Production update posted for order #' . $order_number . '.'));
            if(!empty($context['notify_client']) && $client_id > 0) {
                create_notification_recent_once_for_order($pdo, $client_id, $order_id, 'order_status', $message, 10);
            }
            if(!empty($context['notify_owner']) && $owner_id > 0 && $owner_id !== $actor_id) {
                create_notification_recent_once_for_order($pdo, $owner_id, $order_id, 'order_status', $message, 10);
            }
            break;

        case 'qc_failed':
            $message = (string) ($context['message'] ?? ('Quality check failed for order #' . $order_number . '.'));
            $targets = [$owner_id, $assigned_to];
            $targets = array_merge($targets, fetch_shop_hr_user_ids($pdo, $shop_id));
            $targets = array_values(array_unique(array_filter(array_map('intval', $targets), static fn($id) => $id > 0 && $id !== $actor_id)));
            foreach($targets as $userId) {
                create_notification_recent_once_for_order($pdo, $userId, $order_id, 'warning', $message, 30);
            }
            break;

        case 'payment_confirmed':
        case 'payment_verified':
            $client_message = (string) ($context['client_message'] ?? ('Payment confirmed for order #' . $order_number . '.'));
            $owner_message = (string) ($context['owner_message'] ?? ('Payment confirmed for order #' . $order_number . '.'));
            if($client_id > 0) {
                create_notification_recent_once_for_order($pdo, $client_id, $order_id, 'payment', $client_message, 30);
            }
            if($owner_id > 0 && $owner_id !== $actor_id) {
                create_notification_recent_once_for_order($pdo, $owner_id, $order_id, 'payment', $owner_message, 30);
            }
            break;

            case 'payment_failed':
            $client_message = (string) ($context['client_message'] ?? ('Payment failed for order #' . $order_number . '. Please retry checkout.'));
            $owner_message = (string) ($context['owner_message'] ?? ('Payment failed for order #' . $order_number . '.'));
            if($client_id > 0) {
                create_notification_recent_once_for_order($pdo, $client_id, $order_id, 'payment', $client_message, 20);
            }
            if($owner_id > 0 && $owner_id !== $actor_id) {
                create_notification_recent_once_for_order($pdo, $owner_id, $order_id, 'payment', $owner_message, 20);
            }
            break;

            case 'production_started':
            $message = (string) ($context['message'] ?? ('Production started for order #' . $order_number . '.'));
            if($client_id > 0) {
                create_notification_recent_once_for_order($pdo, $client_id, $order_id, 'order_status', $message, 30);
            }
            if($owner_id > 0 && $owner_id !== $actor_id) {
                create_notification_recent_once_for_order($pdo, $owner_id, $order_id, 'order_status', $message, 30);
            }
            break;

        case 'ready_for_delivery':
            if($client_id > 0) {
                create_notification_recent_once_for_order($pdo, $client_id, $order_id, 'order_status', (string) ($context['client_message'] ?? ('Order #' . $order_number . ' is ready for delivery/pickup.')), 30);
            }
            if($owner_id > 0 && $owner_id !== $actor_id) {
                create_notification_recent_once_for_order($pdo, $owner_id, $order_id, 'order_status', (string) ($context['owner_message'] ?? ('Order #' . $order_number . ' is now ready for delivery.')), 30);
            }
            break;

        case 'overdue_order':
            $lookbackHours = max(1, (int) ($context['lookback_hours'] ?? 24));
            $message = (string) ($context['message'] ?? ('Order #' . $order_number . ' is overdue and requires attention.'));
            $targets = array_values(array_unique(array_filter([$client_id, $owner_id, $assigned_to], static fn($id) => (int) $id > 0)));
            foreach($targets as $userId) {
                if($userId === $actor_id) {
                    continue;
                }
                if(!has_recent_notification_for_order_message($pdo, $userId, $order_id, 'warning', $message, $lookbackHours * 60)) {
                    create_notification($pdo, $userId, $order_id, 'warning', $message);
                }
            }
            break;

        case 'order_shipping_update':
            if($client_id > 0) {
                create_notification_recent_once_for_order($pdo, $client_id, $order_id, 'order_status', (string) ($context['client_message'] ?? ('Order #' . $order_number . ' delivery status was updated.')), 30);
            }
            break;
    }
    
    if(function_exists('log_audit')) {
        log_audit(
            $pdo,
            $actor_id > 0 ? $actor_id : null,
            null,
            'notification_automation_triggered',
            'orders',
            $order_id,
            [],
            ['event_key' => $event_key],
            [
                'order_number' => $order_number,
                'context_keys' => array_keys($context),
            ]
        );
    }
}


function notify_sys_admins_owner_review_needed(PDO $pdo, string $message, int $lookbackHours = 6): void {
    $message = trim($message);
    if($message === '') {
        return;
    }

    $adminIds = array_values(array_unique(array_map('intval', fetch_sys_admin_ids($pdo))));
    foreach($adminIds as $adminId) {
        if($adminId <= 0) {
            continue;
        }

        if(!has_recent_notification_by_type_and_message($pdo, $adminId, 'warning', $message, $lookbackHours)) {
            create_notification($pdo, $adminId, null, 'warning', $message);
        }
    }
}

function notify_owner_approval_decision(PDO $pdo, int $ownerId, bool $approved, ?string $rejectionReason = null): void {
    if($ownerId <= 0) {
        return;
    }

    if($approved) {
        $message = 'Your shop/account has been approved. Full owner access is now available.';
        if(!has_recent_notification_by_type_and_message($pdo, $ownerId, 'account', $message, 24)) {
            create_notification($pdo, $ownerId, null, 'account', $message);
        }

        return;
    }

    $message = 'Your shop/account was rejected.';
    $safeReason = trim((string) $rejectionReason);
    if($safeReason !== '') {
        $message .= ' Reason: ' . preg_replace('/\s+/', ' ', $safeReason);
    }

    if(!has_recent_notification_by_type_and_message($pdo, $ownerId, 'warning', $message, 24)) {
        create_notification($pdo, $ownerId, null, 'warning', $message);
    }
}

function notify_in_app_participants(PDO $pdo, array $user_ids, ?int $order_id, string $type, string $message, int $lookbackMinutes = 5, ?int $actor_id = null): void {
    $unique_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids), static fn($id) => $id > 0)));
    foreach($unique_ids as $user_id) {
        if($actor_id !== null && $user_id === $actor_id) {
            continue;
        }

        if($order_id !== null && $order_id > 0) {
            create_notification_recent_once_for_order($pdo, $user_id, $order_id, $type, $message, $lookbackMinutes);
        } else {
            if(!has_recent_notification_by_type_and_message($pdo, $user_id, $type, $message, max(1, (int) ceil($lookbackMinutes / 60)))) {
                create_notification($pdo, $user_id, null, $type, $message);
            }
        }
    }
}
?>