<?php
if (!isset($pdo, $_SESSION['user']['id'])) {
    return;
}

$owner_message_page = basename($_SERVER['PHP_SELF'] ?? '') === 'messages.php';
if ($owner_message_page) {
    return;
}

$owner_id = (int) $_SESSION['user']['id'];

$contacts_stmt = $pdo->prepare(" 
    SELECT
        c.contact_id,
        c.contact_name,
        c.contact_role,
        c.shop_name,
        c.last_message_at,
        c.last_message,
        c.unread_count
    FROM (
        SELECT
            u.id AS contact_id,
            u.fullname AS contact_name,
            CASE WHEN ss.staff_role = 'hr' THEN 'HR' ELSE 'Staff' END AS contact_role,
            MAX(s.shop_name) AS shop_name,
            MAX(ch.created_at) AS last_message_at,
            (
                SELECT c2.message
                FROM chats c2
                WHERE (c2.sender_id = u.id AND c2.receiver_id = :owner_id_staff_last_in)
                   OR (c2.sender_id = :owner_id_staff_last_out AND c2.receiver_id = u.id)
                ORDER BY c2.created_at DESC
                LIMIT 1
            ) AS last_message,
            (
                SELECT COUNT(*)
                FROM chats c3
                WHERE c3.sender_id = u.id
                  AND c3.receiver_id = :owner_id_staff_unread
                  AND c3.read_status = 0
            ) AS unread_count
        FROM shops s
        JOIN shop_staffs ss ON ss.shop_id = s.id
        JOIN users u ON ss.user_id = u.id
        LEFT JOIN chats ch
          ON (ch.sender_id = u.id AND ch.receiver_id = :owner_id_staff_join_in)
          OR (ch.sender_id = :owner_id_staff_join_out AND ch.receiver_id = u.id)
        WHERE s.owner_id = :owner_id_staff_where
          AND ss.status = 'active'
        GROUP BY u.id, u.fullname, ss.staff_role

        UNION ALL

        SELECT
            u.id AS contact_id,
            u.fullname AS contact_name,
            'Client' AS contact_role,
            MAX(s.shop_name) AS shop_name,
            MAX(ch.created_at) AS last_message_at,
            (
                SELECT c2.message
                FROM chats c2
                WHERE (c2.sender_id = u.id AND c2.receiver_id = :owner_id_client_last_in)
                   OR (c2.sender_id = :owner_id_client_last_out AND c2.receiver_id = u.id)
                ORDER BY c2.created_at DESC
                LIMIT 1
            ) AS last_message,
            (
                SELECT COUNT(*)
                FROM chats c3
                WHERE c3.sender_id = u.id
                  AND c3.receiver_id = :owner_id_client_unread
                  AND c3.read_status = 0
            ) AS unread_count
        FROM orders o
        JOIN shops s ON o.shop_id = s.id
        JOIN users u ON o.client_id = u.id
        LEFT JOIN chats ch
          ON (ch.sender_id = u.id AND ch.receiver_id = :owner_id_client_join_in)
          OR (ch.sender_id = :owner_id_client_join_out AND ch.receiver_id = u.id)
        WHERE s.owner_id = :owner_id_client_where
        GROUP BY u.id, u.fullname
    ) AS c
    GROUP BY c.contact_id, c.contact_name, c.contact_role, c.shop_name, c.last_message_at, c.last_message, c.unread_count
    ORDER BY (c.last_message_at IS NULL) ASC, c.last_message_at DESC, c.contact_name ASC
    LIMIT 8
");

$contacts_stmt->execute([
    'owner_id_staff_last_in' => $owner_id,
    'owner_id_staff_last_out' => $owner_id,
    'owner_id_staff_unread' => $owner_id,
    'owner_id_staff_join_in' => $owner_id,
    'owner_id_staff_join_out' => $owner_id,
    'owner_id_staff_where' => $owner_id,
    'owner_id_client_last_in' => $owner_id,
    'owner_id_client_last_out' => $owner_id,
    'owner_id_client_unread' => $owner_id,
    'owner_id_client_join_in' => $owner_id,
    'owner_id_client_join_out' => $owner_id,
    'owner_id_client_where' => $owner_id,
]);

$owner_quick_contacts = $contacts_stmt->fetchAll();
$total_unread = 0;
foreach ($owner_quick_contacts as $contact) {
    $total_unread += (int) $contact['unread_count'];
}
?>

<style>
    body.owner-with-message-dock .container {
        margin-right: 380px;
    }
    .owner-message-dock {
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
    .owner-message-dock__head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 8px;
        padding: 12px 14px;
        border-bottom: 1px solid #e2e8f0;
        background: #f8fafc;
    }
    .owner-message-dock__list {
        max-height: min(60vh, 460px);
        overflow-y: auto;
    }
    .owner-message-dock__item {
        display: block;
        color: inherit;
        text-decoration: none;
        padding: 12px 14px;
        border-bottom: 1px solid #eef2f7;
    }
    .owner-message-dock__item:hover {
        background: #f8fafc;
    }
    .owner-message-dock__meta {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 6px;
        gap: 8px;
    }
    .owner-message-dock__preview {
        margin-top: 5px;
        color: #64748b;
        font-size: 12px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .owner-message-dock__badge {
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
        body.owner-with-message-dock .container {
            margin-right: auto;
        }
        .owner-message-dock {
            position: static;
            width: 100%;
            margin-top: 12px;
        }
    }
</style>

<script>
    document.body.classList.add('owner-with-message-dock');
</script>

<aside class="owner-message-dock" aria-label="Owner inbox quick view">
    <div class="owner-message-dock__head">
        <strong><i class="fas fa-inbox"></i> Messages</strong>
        <?php if ($total_unread > 0): ?>
            <span class="owner-message-dock__badge"><?php echo (int) $total_unread; ?></span>
        <?php endif; ?>
    </div>
    <div class="owner-message-dock__list">
        <?php if (!empty($owner_quick_contacts)): ?>
            <?php foreach ($owner_quick_contacts as $contact): ?>
                <a class="owner-message-dock__item" href="messages.php?partner_id=<?php echo (int) $contact['contact_id']; ?>">
                    <strong><?php echo htmlspecialchars($contact['contact_name']); ?></strong>
                    <div class="text-muted small"><?php echo htmlspecialchars($contact['contact_role']); ?> · <?php echo htmlspecialchars($contact['shop_name']); ?></div>
                    <div class="owner-message-dock__preview"><?php echo !empty($contact['last_message']) ? htmlspecialchars($contact['last_message']) : 'No messages yet.'; ?></div>
                    <div class="owner-message-dock__meta">
                        <span class="text-muted small"><?php echo !empty($contact['last_message_at']) ? date('M d, h:i A', strtotime($contact['last_message_at'])) : 'Open chat'; ?></span>
                        <?php if ((int) $contact['unread_count'] > 0): ?>
                            <span class="owner-message-dock__badge"><?php echo (int) $contact['unread_count']; ?></span>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="p-3 text-muted">No available conversations yet.</div>
        <?php endif; ?>
    </div>
</aside>
