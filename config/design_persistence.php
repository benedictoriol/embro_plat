<?php
require_once __DIR__ . '/design_helpers.php';

function design_persist_normalize_json($payload): string
{
    if (is_string($payload)) {
        return $payload;
    }

    return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function design_persist_normalize_application_mode(?string $mode): string
{
    $clean = strtolower(trim((string) $mode));
    return $clean === 'patch' ? 'patch' : 'embroidery_preview';
}

function design_persist_ensure_columns(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    try {
        $modeStmt = $pdo->query("SHOW COLUMNS FROM saved_designs LIKE 'application_mode'");
        if (!$modeStmt || !$modeStmt->fetch()) {
            $pdo->exec("ALTER TABLE saved_designs ADD COLUMN application_mode ENUM('patch','embroidery_preview') NOT NULL DEFAULT 'embroidery_preview' AFTER preview_image_path");
        }

        $styleStmt = $pdo->query("SHOW COLUMNS FROM saved_designs LIKE 'patch_style_json'");
        if (!$styleStmt || !$styleStmt->fetch()) {
            $pdo->exec("ALTER TABLE saved_designs ADD COLUMN patch_style_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL AFTER application_mode");
        }

        $placementModeStmt = $pdo->query("SHOW COLUMNS FROM design_placements LIKE 'application_mode'");
        if (!$placementModeStmt || !$placementModeStmt->fetch()) {
            $pdo->exec("ALTER TABLE design_placements ADD COLUMN application_mode ENUM('patch','embroidery_preview') NOT NULL DEFAULT 'embroidery_preview' AFTER placement_type");
        }

        $placementStyleStmt = $pdo->query("SHOW COLUMNS FROM design_placements LIKE 'patch_style_json'");
        if (!$placementStyleStmt || !$placementStyleStmt->fetch()) {
            $pdo->exec("ALTER TABLE design_placements ADD COLUMN patch_style_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL AFTER application_mode");
        }
    } catch (Throwable $e) {
        // Keep API functional even if migration is blocked.
    }

    $checked = true;
}

function design_persist_fetch_order_for_user(PDO $pdo, int $orderId, array $user): ?array
{
    $role = canonicalize_role($user['role'] ?? '');

    if ($role === 'sys_admin') {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$orderId]);
        return $stmt->fetch() ?: null;
    }

    if ($role === 'client') {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? AND client_id = ?');
        $stmt->execute([$orderId, $user['id']]);
        return $stmt->fetch() ?: null;
    }

    if ($role === 'owner') {
        $stmt = $pdo->prepare('SELECT o.* FROM orders o JOIN shops s ON s.id = o.shop_id WHERE o.id = ? AND s.owner_id = ?');
        $stmt->execute([$orderId, $user['id']]);
        return $stmt->fetch() ?: null;
    }

    if ($role === 'staff' || $role === 'hr') {
        $stmt = $pdo->prepare('SELECT o.* FROM orders o JOIN shop_staffs ss ON ss.shop_id = o.shop_id WHERE o.id = ? AND ss.user_id = ? AND ss.status = "active"');
        $stmt->execute([$orderId, $user['id']]);
        return $stmt->fetch() ?: null;
    }

    return null;
}

function design_persist_fetch_placement(PDO $pdo, int $designId): ?array
{
    $stmt = $pdo->prepare('SELECT placement_key, zone_id, model_key, placement_type, transform_json, application_mode, patch_style_json FROM design_placements WHERE design_id = ? LIMIT 1');
    $stmt->execute([$designId]);
    $placement = $stmt->fetch();

    if (!$placement) {
        return null;
    }

    return [
        'zone_id' => isset($placement['zone_id']) ? (int) $placement['zone_id'] : null,
        'placement_key' => $placement['placement_key'],
        'model_key' => $placement['model_key'] ?? null,
        'placement_type' => $placement['placement_type'] ?? null,
        'application_mode' => $placement['application_mode'] ?? null,
        'patch_style' => json_decode((string) ($placement['patch_style_json'] ?? 'null'), true),
        'transform' => json_decode((string) ($placement['transform_json'] ?? 'null'), true)
    ];
}

function design_persist_map_row(array $row, ?array $placement = null): array
{
    return [
        'id' => (int) $row['id'],
        'order_id' => $row['order_id'] !== null ? (int) $row['order_id'] : null,
        'client_user_id' => (int) $row['client_user_id'],
        'product_id' => $row['product_id'] !== null ? (int) $row['product_id'] : null,
        'design_name' => $row['design_name'],
        'design_json' => $row['design_json'],
        'preview_image_path' => $row['preview_image_path'],
        'application_mode' => $row['application_mode'] ?? 'embroidery_preview',
        'patch_style' => json_decode((string) ($row['patch_style_json'] ?? 'null'), true),
        'version_number' => (int) $row['version_number'],
        'is_active' => (int) $row['is_active'] === 1,
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
        'placement' => $placement
    ];
}

function design_persist_save(PDO $pdo, array $user, array $payload): array
{
    design_persist_ensure_columns($pdo);

    $orderId = isset($payload['order_id']) && (int) $payload['order_id'] > 0 ? (int) $payload['order_id'] : null;
    $productId = isset($payload['product_id']) && (int) $payload['product_id'] > 0 ? (int) $payload['product_id'] : null;
    $designName = trim((string) ($payload['design_name'] ?? 'Untitled Design'));
    $designJson = design_persist_normalize_json($payload['design_json'] ?? '');
    $previewImagePath = trim((string) ($payload['preview_image_path'] ?? ''));
    $modelKey = trim((string) ($payload['model_key'] ?? 'tshirt'));
    $applicationMode = design_persist_normalize_application_mode($payload['application_mode'] ?? 'embroidery_preview');
    $patchStyleJson = array_key_exists('patch_style', $payload)
        ? design_persist_normalize_json($payload['patch_style'])
        : null;
    $placement = isset($payload['placement']) && is_array($payload['placement']) ? $payload['placement'] : null;
    $versionId = isset($payload['version_id']) && (int) $payload['version_id'] > 0 ? (int) $payload['version_id'] : null;

    if ($designJson === '' || $designJson === 'null') {
        throw new InvalidArgumentException('design_json is required.');
    }

    if ($orderId !== null) {
        $order = design_persist_fetch_order_for_user($pdo, $orderId, $user);
        if (!$order) {
            throw new RuntimeException('Order not found.');
        }
    }

    $clientUserId = (int) $user['id'];

    $pdo->beginTransaction();
    try {
        if ($versionId !== null) {
            $existingStmt = $pdo->prepare('SELECT * FROM saved_designs WHERE id = ? AND client_user_id = ? AND ((order_id IS NULL AND ? IS NULL) OR order_id = ?) LIMIT 1 FOR UPDATE');
            $existingStmt->execute([$versionId, $clientUserId, $orderId, $orderId]);
            $existing = $existingStmt->fetch();
            if (!$existing) {
                throw new RuntimeException('Design version not found.');
            }

            $pdo->prepare('UPDATE saved_designs SET is_active = 0 WHERE client_user_id = ? AND ((order_id IS NULL AND ? IS NULL) OR order_id = ?)')->execute([$clientUserId, $orderId, $orderId]);
            $updateStmt = $pdo->prepare('UPDATE saved_designs SET product_id = ?, design_name = ?, design_json = ?, preview_image_path = ?, application_mode = ?, patch_style_json = ?, is_active = 1 WHERE id = ?');
            $updateStmt->execute([
                $productId,
                $designName !== '' ? $designName : 'Untitled Design',
                $designJson,
                $previewImagePath !== '' ? $previewImagePath : null,
                $applicationMode,
                $patchStyleJson,
                $versionId
            ]);
            $designId = $versionId;
            $nextVersion = (int) $existing['version_number'];
        } else {
            $pdo->prepare('UPDATE saved_designs SET is_active = 0 WHERE client_user_id = ? AND ((order_id IS NULL AND ? IS NULL) OR order_id = ?)')->execute([$clientUserId, $orderId, $orderId]);

            $versionStmt = $pdo->prepare('SELECT COALESCE(MAX(version_number), 0) FROM saved_designs WHERE client_user_id = ? AND ((order_id IS NULL AND ? IS NULL) OR order_id = ?) FOR UPDATE');
            $versionStmt->execute([$clientUserId, $orderId, $orderId]);
            $nextVersion = ((int) $versionStmt->fetchColumn()) + 1;

            $insertStmt = $pdo->prepare('INSERT INTO saved_designs (order_id, client_user_id, product_id, design_name, design_json, preview_image_path, application_mode, patch_style_json, version_number, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)');
            $insertStmt->execute([
                $orderId,
                $clientUserId,
                $productId,
                $designName !== '' ? $designName : 'Untitled Design',
                $designJson,
                $previewImagePath !== '' ? $previewImagePath : null,
                $applicationMode,
                $patchStyleJson,
                $nextVersion
            ]);
            $designId = (int) $pdo->lastInsertId();
        }

        if ($orderId !== null) {
            $pdo->prepare('UPDATE orders SET design_version_id = ? WHERE id = ?')->execute([$designId, $orderId]);
        }

        if ($placement !== null) {
            $pdo->prepare('DELETE FROM design_placements WHERE design_id = ?')->execute([$designId]);
            $availableZones = list_placement_zones($pdo, $productId, $modelKey, null);
            $defaultZone = $availableZones[0] ?? null;
            $placementKey = trim((string) ($placement['placement_key'] ?? ($defaultZone['zone_key'] ?? 'center_chest')));
            $selectedZone = null;
            foreach ($availableZones as $zone) {
                if (($zone['zone_key'] ?? '') === $placementKey) {
                    $selectedZone = $zone;
                    break;
                }
            }
            if (!$selectedZone && $defaultZone) {
                $selectedZone = $defaultZone;
                $placementKey = $selectedZone['zone_key'] ?? 'center_chest';
            }

            $zoneId = isset($selectedZone['id']) ? (int) $selectedZone['id'] : null;
            $placementType = $applicationMode === 'patch' ? 'patch' : 'embroidery';
            $transformJson = design_persist_normalize_json($placement['transform'] ?? ($selectedZone['transform_defaults'] ?? new stdClass()));

            $placementStmt = $pdo->prepare('INSERT INTO design_placements (design_id, zone_id, model_key, placement_key, placement_type, application_mode, patch_style_json, transform_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $placementStmt->execute([$designId, $zoneId, $modelKey, $placementKey, $placementType, $applicationMode, $patchStyleJson, $transformJson]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $fetchStmt = $pdo->prepare('SELECT * FROM saved_designs WHERE id = ?');
    $fetchStmt->execute([$designId]);
    $row = $fetchStmt->fetch();

    if (!$row) {
        throw new RuntimeException('Design save failed.');
    }

    return [
        'data' => design_persist_map_row($row, design_persist_fetch_placement($pdo, $designId)),
        'version_number' => $nextVersion
    ];
}

function design_persist_list_versions(PDO $pdo, int $clientUserId, ?int $orderId): array
{
    design_persist_ensure_columns($pdo);
    $stmt = $pdo->prepare('SELECT * FROM saved_designs WHERE client_user_id = ? AND ((order_id IS NULL AND ? IS NULL) OR order_id = ?) ORDER BY version_number DESC');
    $stmt->execute([$clientUserId, $orderId, $orderId]);
    $rows = $stmt->fetchAll();

    return array_map(function (array $row) use ($pdo): array {
        return design_persist_map_row($row, design_persist_fetch_placement($pdo, (int) $row['id']));
    }, $rows);
}

function design_persist_get_version(PDO $pdo, int $clientUserId, ?int $orderId, int $versionId = 0, int $versionNumber = 0): ?array
{
    design_persist_ensure_columns($pdo);

    if ($versionId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM saved_designs WHERE id = ? AND client_user_id = ? AND ((order_id IS NULL AND ? IS NULL) OR order_id = ?)');
        $stmt->execute([$versionId, $clientUserId, $orderId, $orderId]);
    } elseif ($versionNumber > 0) {
        $stmt = $pdo->prepare('SELECT * FROM saved_designs WHERE version_number = ? AND client_user_id = ? AND ((order_id IS NULL AND ? IS NULL) OR order_id = ?) ORDER BY id DESC LIMIT 1');
        $stmt->execute([$versionNumber, $clientUserId, $orderId, $orderId]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM saved_designs WHERE client_user_id = ? AND ((order_id IS NULL AND ? IS NULL) OR order_id = ?) ORDER BY is_active DESC, version_number DESC LIMIT 1');
        $stmt->execute([$clientUserId, $orderId, $orderId]);
    }

    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    return design_persist_map_row($row, design_persist_fetch_placement($pdo, (int) $row['id']));
}
