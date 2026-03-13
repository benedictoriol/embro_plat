<?php

function ensure_order_quotes_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_quotes (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        order_id INT(11) NOT NULL,
        shop_id INT(11) NOT NULL,
        owner_id INT(11) NOT NULL,
        status ENUM('draft','sent','approved','rejected','expired') NOT NULL DEFAULT 'draft',
        quoted_price DECIMAL(10,2) NOT NULL,
        base_price DECIMAL(10,2) DEFAULT NULL,
        design_adjustment DECIMAL(10,2) DEFAULT NULL,
        stitch_adjustment DECIMAL(10,2) DEFAULT NULL,
        size_adjustment DECIMAL(10,2) DEFAULT NULL,
        rush_fee DECIMAL(10,2) DEFAULT NULL,
        quantity_breakdown JSON DEFAULT NULL,
        notes_terms TEXT DEFAULT NULL,
        expires_at DATETIME DEFAULT NULL,
        sent_at DATETIME DEFAULT NULL,
        responded_at DATETIME DEFAULT NULL,
        approved_at DATETIME DEFAULT NULL,
        rejected_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_order_quotes_order (order_id),
        KEY idx_order_quotes_shop (shop_id),
        KEY idx_order_quotes_status (status),
        CONSTRAINT fk_order_quotes_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
        CONSTRAINT fk_order_quotes_shop FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
        CONSTRAINT fk_order_quotes_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function quote_normalize_status(string $status): string {
    $normalized = strtolower(trim($status));
    return match ($normalized) {
        'requested' => 'draft',
        'issued' => 'sent',
        default => $normalized,
    };
}

function quote_sync_order_snapshot(PDO $pdo, int $order_id, array $quote): void {
    $status = quote_normalize_status((string) ($quote['status'] ?? 'draft'));
    $status = in_array($status, ['draft', 'sent', 'approved', 'rejected', 'expired'], true) ? $status : 'draft';
    $approvedAt = $status === 'approved' ? ($quote['approved_at'] ?? date('Y-m-d H:i:s')) : null;
    $price = isset($quote['quoted_price']) ? (float) $quote['quoted_price'] : null;

    $stmt = $pdo->prepare("UPDATE orders SET quote_status = ?, quote_approved_at = ?, price = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$status, $approvedAt, $price, $order_id]);
}

function quote_get_latest_for_order(PDO $pdo, int $order_id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM order_quotes WHERE order_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$order_id]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);
    if(!$quote) {
        return null;
    }

    if(($quote['status'] ?? '') === 'sent' && !empty($quote['expires_at']) && strtotime((string) $quote['expires_at']) < time()) {
        $expireStmt = $pdo->prepare("UPDATE order_quotes SET status = 'expired', updated_at = NOW() WHERE id = ?");
        $expireStmt->execute([(int) $quote['id']]);
        $quote['status'] = 'expired';
        quote_sync_order_snapshot($pdo, $order_id, $quote);
    }

    return $quote;
}

function quote_save_owner_quote(PDO $pdo, int $order_id, int $shop_id, int $owner_id, array $payload, bool $send = true): array {
    $status = $send ? 'sent' : 'draft';
    $now = date('Y-m-d H:i:s');
    $quantityBreakdown = $payload['quantity_breakdown'] ?? null;
    if(is_array($quantityBreakdown)) {
        $quantityBreakdown = json_encode($quantityBreakdown);
    }

    $stmt = $pdo->prepare("INSERT INTO order_quotes
        (order_id, shop_id, owner_id, status, quoted_price, base_price, design_adjustment, stitch_adjustment, size_adjustment, rush_fee, quantity_breakdown, notes_terms, expires_at, sent_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $order_id,
        $shop_id,
        $owner_id,
        $status,
        (float) ($payload['quoted_price'] ?? 0),
        isset($payload['base_price']) ? (float) $payload['base_price'] : null,
        isset($payload['design_adjustment']) ? (float) $payload['design_adjustment'] : null,
        isset($payload['stitch_adjustment']) ? (float) $payload['stitch_adjustment'] : null,
        isset($payload['size_adjustment']) ? (float) $payload['size_adjustment'] : null,
        isset($payload['rush_fee']) ? (float) $payload['rush_fee'] : null,
        $quantityBreakdown,
        $payload['notes_terms'] ?? null,
        $payload['expires_at'] ?? null,
        $send ? $now : null,
    ]);

    $quoteId = (int) $pdo->lastInsertId();
    $quote = quote_get_latest_for_order($pdo, $order_id);
    if($quote) {
        quote_sync_order_snapshot($pdo, $order_id, $quote);
    }

    return ['id' => $quoteId, 'status' => $status];
}

function quote_client_respond(PDO $pdo, int $order_id, int $client_id, string $decision): array {
    $decision = strtolower(trim($decision));
    if(!in_array($decision, ['approved', 'rejected'], true)) {
        return [false, 'Invalid quote response.'];
    }

    $orderStmt = $pdo->prepare("SELECT id, client_id FROM orders WHERE id = ? LIMIT 1");
    $orderStmt->execute([$order_id]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if(!$order || (int) $order['client_id'] !== $client_id) {
        return [false, 'Order not found.'];
    }

    $quote = quote_get_latest_for_order($pdo, $order_id);
    if(!$quote) {
        return [false, 'No quote found for this order.'];
    }

    if(($quote['status'] ?? '') === 'expired') {
        return [false, 'This quote has expired. Please wait for a reissued quote.'];
    }

    if(($quote['status'] ?? '') !== 'sent') {
        return [false, 'This quote is not actionable.'];
    }

    $now = date('Y-m-d H:i:s');
    $statusStmt = $pdo->prepare("UPDATE order_quotes SET status = ?, responded_at = ?, approved_at = ?, rejected_at = ? WHERE id = ?");
    $statusStmt->execute([
        $decision,
        $now,
        $decision === 'approved' ? $now : null,
        $decision === 'rejected' ? $now : null,
        (int) $quote['id'],
    ]);

    $updated = quote_get_latest_for_order($pdo, $order_id);
    if($updated) {
        quote_sync_order_snapshot($pdo, $order_id, $updated);
    }

    return [true, null];
}
