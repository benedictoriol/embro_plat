<?php

function raw_material_label_column(PDO $pdo, string $tableAlias = ''): string {
    $prefix = $tableAlias !== '' ? rtrim($tableAlias, '.') . '.' : '';

    if (column_exists($pdo, 'raw_materials', 'name')) {
        return $prefix . 'name';
    }

    if (column_exists($pdo, 'raw_materials', 'material_name')) {
        return $prefix . 'material_name';
    }

    return $prefix . 'name';
}

function ensure_supplier_orders_table(PDO $pdo): void {
    // Schema is managed by migrations.
}

function ensure_order_material_reservations_table(PDO $pdo): void {
    // Schema is managed by migrations.
}

function create_low_stock_supplier_drafts(PDO $pdo, int $ownerId, int $shopId): array {

    $lowStockStmt = $pdo->prepare("\n        SELECT id, name, current_stock, min_stock_level, supplier\n        FROM raw_materials\n        WHERE shop_id = ?\n          AND min_stock_level IS NOT NULL\n          AND current_stock <= min_stock_level\n    ");
    $lowStockStmt->execute([$shopId]);
    $lowStockMaterials = $lowStockStmt->fetchAll();

    $createdDrafts = 0;
    $notificationCount = 0;

    foreach ($lowStockMaterials as $material) {
        $qtyNeeded = (float) $material['min_stock_level'] - (float) $material['current_stock'];
        if ($qtyNeeded <= 0) {
            continue;
        }

        $existingDraftStmt = $pdo->prepare("\n            SELECT id\n            FROM supplier_orders\n            WHERE material_id = ?\n              AND status IN ('draft', 'pending', 'ordered')\n            LIMIT 1\n        ");
        $existingDraftStmt->execute([(int) $material['id']]);
        $existingDraftId = $existingDraftStmt->fetchColumn();

        $createdNow = false;
        if (!$existingDraftId) {
            $draftStmt = $pdo->prepare("\n                INSERT INTO supplier_orders (material_id, quantity, supplier_name, status, created_at)\n                VALUES (?, ?, ?, 'draft', NOW())\n            ");
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
