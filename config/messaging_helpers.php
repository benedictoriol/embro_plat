<?php

function messaging_normalize_participants(array $user_ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $user_ids), static fn($id) => $id > 0)));
    sort($ids);
    return $ids;
}

function messaging_build_thread_key(int $order_id, array $participants): string {
    $normalized = messaging_normalize_participants($participants);
    return sprintf('order:%d|participants:%s', max(0, $order_id), implode('-', $normalized));
}

function messaging_fetch_order_context(PDO $pdo, int $order_id): ?array {
    if($order_id <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("\n        SELECT o.id, o.order_number, o.client_id, o.assigned_to, o.shop_id, s.owner_id, s.shop_name
        FROM orders o
        JOIN shops s ON s.id = o.shop_id
        WHERE o.id = ?
        LIMIT 1
    ");
    $stmt->execute([$order_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function messaging_fetch_internal_members(PDO $pdo, int $shop_id): array {
    if($shop_id <= 0) {
        return [];
    }

    $stmt = $pdo->prepare("\n        SELECT ss.user_id
        FROM shop_staffs ss
        JOIN users u ON u.id = ss.user_id
        WHERE ss.shop_id = ?
          AND ss.status = 'active'
          AND ss.staff_role IN ('hr', 'staff')
          AND u.status = 'active'
    ");
    $stmt->execute([$shop_id]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function messaging_insert_system_chat(PDO $pdo, int $sender_id, int $receiver_id, string $message, int $order_id, string $thread_key, string $thread_topic, bool $is_seed = false): void {
    $stmt = $pdo->prepare("\n        INSERT INTO chats (sender_id, receiver_id, order_id, thread_key, thread_topic, is_system, is_thread_seed, message)
        VALUES (?, ?, ?, ?, ?, 1, ?, ?)
    ");
    $stmt->execute([
        $sender_id,
        $receiver_id,
        $order_id > 0 ? $order_id : null,
        $thread_key,
        $thread_topic,
        $is_seed ? 1 : 0,
        $message,
    ]);
}

function messaging_ensure_order_thread(PDO $pdo, int $order_id, int $user_a, int $user_b, int $actor_id, string $thread_topic, string $event_message): void {
    $participants = messaging_normalize_participants([$user_a, $user_b]);
    if(count($participants) !== 2 || $order_id <= 0) {
        return;
    }

    $thread_key = messaging_build_thread_key($order_id, $participants);

    $exists_stmt = $pdo->prepare("SELECT id FROM chats WHERE order_id = ? AND thread_key = ? LIMIT 1");
    $exists_stmt->execute([$order_id, $thread_key]);
    $thread_exists = (bool) $exists_stmt->fetchColumn();

    $sender_id = in_array($actor_id, $participants, true) ? $actor_id : $participants[0];
    $receiver_id = $participants[0] === $sender_id ? $participants[1] : $participants[0];

    if(!$thread_exists) {
        messaging_insert_system_chat(
            $pdo,
            $sender_id,
            $receiver_id,
            'Operational thread opened for this order to streamline coordination.',
            $order_id,
            $thread_key,
            $thread_topic,
            true
        );
    }

    $recent_stmt = $pdo->prepare("\n        SELECT id
        FROM chats
        WHERE order_id = ?
          AND thread_key = ?
          AND message = ?
          AND created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
        LIMIT 1
    ");
    $recent_stmt->execute([$order_id, $thread_key, $event_message]);

    $event_inserted = false;
    if(!$recent_stmt->fetchColumn()) {
        messaging_insert_system_chat($pdo, $sender_id, $receiver_id, $event_message, $order_id, $thread_key, $thread_topic, false);
        $event_inserted = true;
    }

    if(function_exists('log_audit') && ($event_inserted || !$thread_exists)) {
        log_audit(
            $pdo,
            $actor_id > 0 ? $actor_id : null,
            null,
            'messaging_automation_triggered',
            'orders',
            $order_id,
            [],
            [
                'thread_topic' => $thread_topic,
                'thread_created' => !$thread_exists,
                'event_message_logged' => $event_inserted,
            ],
            [
                'thread_key' => $thread_key,
                'participant_count' => count($participants),
            ]
        );
    }

    notify_in_app_participants(
        $pdo,
        $participants,
        $order_id,
        'message',
        $event_message,
        10,
        $sender_id
    );
}

function messaging_auto_thread_on_order_accepted(PDO $pdo, int $order_id, int $actor_id): void {
    $order = messaging_fetch_order_context($pdo, $order_id);
    if(!$order) {
        return;
    }

    $message = sprintf('Order #%s was accepted. Use this thread for owner-client coordination.', (string) ($order['order_number'] ?? $order_id));
    messaging_ensure_order_thread($pdo, $order_id, (int) $order['owner_id'], (int) $order['client_id'], $actor_id, 'client_owner', $message);
}

function messaging_auto_thread_on_staff_assigned(PDO $pdo, int $order_id, int $actor_id): void {
    $order = messaging_fetch_order_context($pdo, $order_id);
    if(!$order) {
        return;
    }

    $assigned_to = (int) ($order['assigned_to'] ?? 0);
    $owner_id = (int) ($order['owner_id'] ?? 0);
    $order_number = (string) ($order['order_number'] ?? $order_id);

    if($assigned_to > 0 && $owner_id > 0) {
        messaging_ensure_order_thread(
            $pdo,
            $order_id,
            $owner_id,
            $assigned_to,
            $actor_id,
            'internal_assignment',
            'Order #' . $order_number . ' was assigned. Coordinate production steps in this thread.'
        );
    }

    $internal_members = messaging_fetch_internal_members($pdo, (int) ($order['shop_id'] ?? 0));
    foreach($internal_members as $member_id) {
        if($member_id <= 0 || $member_id === $assigned_to || $member_id === $owner_id) {
            continue;
        }

        messaging_ensure_order_thread(
            $pdo,
            $order_id,
            $owner_id,
            $member_id,
            $actor_id,
            'internal_visibility',
            'Order #' . $order_number . ' has updated staffing. Keep this thread for internal visibility.'
        );
    }
}

function messaging_auto_thread_on_client_owner_discussion(PDO $pdo, int $order_id, int $actor_id, string $reason): void {
    $order = messaging_fetch_order_context($pdo, $order_id);
    if(!$order) {
        return;
    }

    $message = sprintf('Client-owner discussion requested for order #%s: %s', (string) ($order['order_number'] ?? $order_id), trim($reason));
    messaging_ensure_order_thread($pdo, $order_id, (int) $order['owner_id'], (int) $order['client_id'], $actor_id, 'client_owner', $message);
}

function messaging_auto_thread_on_qc_rework(PDO $pdo, int $order_id, int $actor_id, string $reason): void {
    $order = messaging_fetch_order_context($pdo, $order_id);
    if(!$order) {
        return;
    }

    $owner_id = (int) ($order['owner_id'] ?? 0);
    $assigned_to = (int) ($order['assigned_to'] ?? 0);
    $order_number = (string) ($order['order_number'] ?? $order_id);
    $clean_reason = trim($reason) !== '' ? trim($reason) : 'QC failed and rework is required.';

    if($assigned_to > 0 && $owner_id > 0) {
        messaging_ensure_order_thread(
            $pdo,
            $order_id,
            $owner_id,
            $assigned_to,
            $actor_id,
            'internal_qc_rework',
            'QC rework discussion for order #' . $order_number . ': ' . $clean_reason
        );
    }

    $internal_members = messaging_fetch_internal_members($pdo, (int) ($order['shop_id'] ?? 0));
    foreach($internal_members as $member_id) {
        if($member_id <= 0 || $member_id === $owner_id || $member_id === $assigned_to) {
            continue;
        }

        messaging_ensure_order_thread(
            $pdo,
            $order_id,
            $owner_id,
            $member_id,
            $actor_id,
            'internal_qc_rework',
            'QC rework visibility for order #' . $order_number . ': ' . $clean_reason
        );
    }
}
