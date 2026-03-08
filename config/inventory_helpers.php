<?php

function ensure_supplier_orders_table(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS supplier_orders (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            material_id INT(11) NOT NULL,
            quantity DECIMAL(10,2) NOT NULL,
            supplier_name VARCHAR(150) DEFAULT NULL,
            status ENUM('draft', 'pending', 'ordered', 'cancelled', 'completed') NOT NULL DEFAULT 'draft',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_supplier_orders_material (material_id),
            KEY idx_supplier_orders_status (status),
            CONSTRAINT fk_supplier_orders_material FOREIGN KEY (material_id) REFERENCES raw_materials(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function create_low_stock_supplier_drafts(PDO $pdo, int $ownerId, int $shopId): array {
    ensure_supplier_orders_table($pdo);

    $lowStockStmt = $pdo->prepare("
        SELECT id, name, current_stock, min_stock_level, supplier
        FROM raw_materials
        WHERE shop_id = ?
          AND min_stock_level IS NOT NULL
          AND current_stock <= min_stock_level
    ");
    $lowStockStmt->execute([$shopId]);
    $lowStockMaterials = $lowStockStmt->fetchAll();

    $createdDrafts = 0;
    $notificationCount = 0;

    foreach ($lowStockMaterials as $material) {
        $qtyNeeded = (float) $material['min_stock_level'] - (float) $material['current_stock'];
        if ($qtyNeeded <= 0) {
            continue;
        }

        $existingDraftStmt = $pdo->prepare("
            SELECT id
            FROM supplier_orders
            WHERE material_id = ?
              AND status IN ('draft', 'pending', 'ordered')
            LIMIT 1
        ");
        $existingDraftStmt->execute([(int) $material['id']]);
        $existingDraftId = $existingDraftStmt->fetchColumn();

        $createdNow = false;
        if (!$existingDraftId) {
            $draftStmt = $pdo->prepare("
                INSERT INTO supplier_orders (material_id, quantity, supplier_name, status, created_at)
                VALUES (?, ?, ?, 'draft', NOW())
            ");
            $draftStmt->execute([
                (int) $material['id'],
                $qtyNeeded,
                !empty($material['supplier']) ? $material['supplier'] : null,
            ]);
            $createdDrafts++;
            $createdNow = true;
        }

        $message = sprintf(
            'Low stock alert: %s is below threshold (%.2f / %.2f).%s',
            $material['name'],
            (float) $material['current_stock'],
            (float) $material['min_stock_level'],
            $createdNow ? ' Draft supplier order created.' : ' Existing draft supplier order is pending.'
        );

        if (!has_recent_notification_by_type_and_message($pdo, $ownerId, 'low_stock', $message, 24)) {
            create_notification($pdo, $ownerId, null, 'low_stock', $message);
            $notificationCount++;
        }
    }

    return [
        'low_stock_count' => count($lowStockMaterials),
        'drafts_created' => $createdDrafts,
        'notifications_sent' => $notificationCount,
    ];
}
