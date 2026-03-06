<?php
if (!isset($pdo, $_SESSION['user']['id'])) {
    return;
}

$client_message_page = basename($_SERVER['PHP_SELF'] ?? '') === 'messages.php';
if ($client_message_page) {
    return;
}

$client_id = (int) $_SESSION['user']['id'];

$contacts_stmt = $pdo->prepare(" 
    SELECT
        s.id AS shop_id,
        s.shop_name,
        u.fullname AS owner_name,
        MAX(c.created_at) AS last_message_at,
        (
            SELECT c2.message
            FROM chats c2
            WHERE (c2.sender_id = :client_last_sender AND c2.receiver_id = s.owner_id)
               OR (c2.sender_id = s.owner_id AND c2.receiver_id = :client_last_receiver)
            ORDER BY c2.created_at DESC
            LIMIT 1
        ) AS last_message,
        (
            SELECT COUNT(*)
            FROM chats c3
            WHERE c3.sender_id = s.owner_id
              AND c3.receiver_id = :client_unread_receiver
              AND c3.read_status = 0
        ) AS unread_count
    FROM orders o
    JOIN shops s ON o.shop_id = s.id
    JOIN users u ON s.owner_id = u.id
    LEFT JOIN chats c
      ON (c.sender_id = :client_join_sender AND c.receiver_id = s.owner_id)
      OR (c.sender_id = s.owner_id AND c.receiver_id = :client_join_receiver)
    WHERE o.client_id = :client_where
    GROUP BY s.id, s.shop_name, u.fullname, s.owner_id
    ORDER BY (MAX(c.created_at) IS NULL) ASC, MAX(c.created_at) DESC, s.shop_name ASC
    LIMIT 10
");

$contacts_stmt->execute([
    'client_last_sender' => $client_id,
    'client_last_receiver' => $client_id,
    'client_unread_receiver' => $client_id,
    'client_join_sender' => $client_id,
    'client_join_receiver' => $client_id,
    'client_where' => $client_id,
]);

$client_quick_contacts = $contacts_stmt->fetchAll();
$total_unread = 0;
foreach ($client_quick_contacts as $contact) {
    $total_unread += (int) $contact['unread_count'];
}
?>

<style>
    body.client-with-message-dock .container {
        margin-right: 380px;
    }
    .client-message-dock {
        position: fixed;
        top: 24px;
        right: 18px;
        width: min(340px, calc(100vw - 36px));
        z-index: 1100;
        border: 1px solid #dbe4ee;
        border-radius: 14px;
        background: #fff;
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.16);
        overflow: hidden;
    }
    .client-message-dock__head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
        padding: 12px 14px;
        border-bottom: 1px solid #e2e8f0;
        background: #f8fafc;
    }
    .client-message-dock__list {
        max-height: min(60vh, 460px);
        overflow-y: auto;
    }
    .client-message-dock__item {
        display: block;
        color: inherit;
        text-decoration: none;
        padding: 12px 14px;
        border-bottom: 1px solid #eef2f7;
    }
    .client-message-dock__item:hover {
        background: #f8fafc;
    }
    .client-message-dock__meta {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 6px;
        gap: 8px;
    }
    .client-message-dock__preview {
        margin-top: 5px;
        color: #64748b;
        font-size: 12px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .client-message-dock__badge {
        min-width: 20px;
        height: 20px;
        border-radius: 999px;
        background: #0ea5e9;
        color: #fff;
        font-size: 11px;
        font-weight: 700;
        display: inline-flex;
        justify-content: center;
        align-items: center;
        padding: 0 6px;
    }
    @media (max-width: 960px) {
        body.client-with-message-dock .container {
            margin-right: auto;
        }
        .client-message-dock {
            position: static;
            width: 100%;
            margin: 12px auto 0;
        }
    }
</style>

<script>
    document.body.classList.add('client-with-message-dock');
</script>

<aside class="client-message-dock" aria-label="Client inbox quick view">
    <div class="client-message-dock__head">
        <strong><i class="fas fa-inbox"></i> Inbox</strong>
        <?php if ($total_unread > 0): ?>
            <span class="client-message-dock__badge"><?php echo (int) $total_unread; ?></span>
        <?php endif; ?>
    </div>
    <div class="client-message-dock__list">
        <?php if (!empty($client_quick_contacts)): ?>
            <?php foreach ($client_quick_contacts as $contact): ?>
                <a class="client-message-dock__item" href="messages.php?shop_id=<?php echo (int) $contact['shop_id']; ?>">
                    <strong><?php echo htmlspecialchars($contact['shop_name']); ?></strong>
                    <div class="text-muted small">Owner: <?php echo htmlspecialchars($contact['owner_name']); ?></div>
                    <div class="client-message-dock__preview"><?php echo !empty($contact['last_message']) ? htmlspecialchars($contact['last_message']) : 'No messages yet.'; ?></div>
                    <div class="client-message-dock__meta">
                        <span class="text-muted small"><?php echo !empty($contact['last_message_at']) ? date('M d, h:i A', strtotime($contact['last_message_at'])) : 'Open chat'; ?></span>
                        <?php if ((int) $contact['unread_count'] > 0): ?>
                            <span class="client-message-dock__badge"><?php echo (int) $contact['unread_count']; ?></span>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="p-3 text-muted">No conversations yet. Place an order to start chatting with shops.</div>
        <?php endif; ?>
    </div>
</aside>
