<?php
// Database Configuration
$host = "localhost";
$dbname = "embroidery_platform";
$username = "root";
$password = "";

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/auth_functions.php';
require_once __DIR__ . '/notification_functions.php';
require_once __DIR__ . '/order_helpers.php';
require_once __DIR__ . '/payment_helpers.php';
require_once __DIR__ . '/design_helpers.php';
require_once __DIR__ . '/queue_helpers.php';
require_once __DIR__ . '/inventory_helpers.php';

enforce_csrf_protection();

// Sanitize input
function sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)));
}

function table_exists(PDO $pdo, string $tableName): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = ?
    ");
    $stmt->execute([$tableName]);
    return (int) $stmt->fetchColumn() > 0;
}
function column_exists(PDO $pdo, string $tableName, string $columnName): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = ?
          AND column_name = ?
    ");
    $stmt->execute([$tableName, $columnName]);
    return (int) $stmt->fetchColumn() > 0;
}


function ensure_shop_staff_position_column(PDO $pdo): void {
    if (!table_exists($pdo, 'shop_staffs') || column_exists($pdo, 'shop_staffs', 'position')) {
        return;
    }

    $positionClause = column_exists($pdo, 'shop_staffs', 'staff_role') ? ' AFTER staff_role' : '';
    $pdo->exec("ALTER TABLE shop_staffs ADD COLUMN position VARCHAR(100) DEFAULT NULL{$positionClause}");
}

function ensure_orders_price_column(PDO $pdo): void {
    if (!table_exists($pdo, 'orders') || column_exists($pdo, 'orders', 'price')) {
        return;
    }

    $positionClause = column_exists($pdo, 'orders', 'quantity') ? ' AFTER quantity' : '';
    $pdo->exec("ALTER TABLE orders ADD COLUMN price DECIMAL(10,2) DEFAULT NULL{$positionClause}");
}

function ensure_orders_image_dimension_columns(PDO $pdo): void {
    if (!table_exists($pdo, 'orders')) {
        return;
    }

    if (!column_exists($pdo, 'orders', 'width_px')) {
        $positionClause = column_exists($pdo, 'orders', 'design_file') ? ' AFTER design_file' : '';
        $pdo->exec("ALTER TABLE orders ADD COLUMN width_px INT DEFAULT NULL{$positionClause}");
    }

    if (!column_exists($pdo, 'orders', 'height_px')) {
        $positionClause = column_exists($pdo, 'orders', 'width_px') ? ' AFTER width_px' : '';
        $pdo->exec("ALTER TABLE orders ADD COLUMN height_px INT DEFAULT NULL{$positionClause}");
    }
}



function ensure_digitized_designs_table(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS digitized_designs (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            order_id INT(11) NOT NULL,
            digitizer_id INT(11) NOT NULL,
            stitch_file_path VARCHAR(255) DEFAULT NULL,
            stitch_count INT(11) DEFAULT NULL,
            thread_colors INT(11) DEFAULT NULL,
            estimated_thread_length DECIMAL(12,2) DEFAULT NULL,
            width_px INT(11) DEFAULT NULL,
            height_px INT(11) DEFAULT NULL,
            detected_width_mm DECIMAL(10,2) DEFAULT NULL,
            detected_height_mm DECIMAL(10,2) DEFAULT NULL,
            suggested_width_mm DECIMAL(10,2) DEFAULT NULL,
            suggested_height_mm DECIMAL(10,2) DEFAULT NULL,
            scale_ratio DECIMAL(10,4) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            approved_at TIMESTAMP NULL DEFAULT NULL,
            KEY idx_digitized_order_id (order_id),
            KEY idx_digitized_digitizer_id (digitizer_id),
            CONSTRAINT fk_digitized_designs_order FOREIGN KEY (order_id) REFERENCES orders(id),
            CONSTRAINT fk_digitized_designs_digitizer FOREIGN KEY (digitizer_id) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function ensure_orders_cap_measurement_columns(PDO $pdo): void {
    if (!table_exists($pdo, 'orders')) {
        return;
    }

    $columns = [
        'detected_width_mm' => 'DECIMAL(10,2) DEFAULT NULL',
        'detected_height_mm' => 'DECIMAL(10,2) DEFAULT NULL',
        'fits_cap_area' => 'TINYINT(1) DEFAULT NULL',
        'suggested_width_mm' => 'DECIMAL(10,2) DEFAULT NULL',
        'suggested_height_mm' => 'DECIMAL(10,2) DEFAULT NULL',
        'scale_ratio' => 'DECIMAL(10,4) DEFAULT NULL',
    ];

    foreach ($columns as $column => $definition) {
        if (!column_exists($pdo, 'orders', $column)) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN {$column} {$definition}");
        }
    }
}

ensure_orders_price_column($pdo);
ensure_orders_image_dimension_columns($pdo);
ensure_orders_cap_measurement_columns($pdo);
ensure_shop_staff_position_column($pdo);
ensure_digitized_designs_table($pdo);
ensure_production_queue_table($pdo);
ensure_payments_payment_method_column($pdo);
ensure_supplier_orders_table($pdo);
function log_audit(PDO $pdo, ?int $actorId, ?string $actorRole, string $action, string $entityType, ?int $entityId, array $oldValues = [], array $newValues = []): void {
    $stmt = $pdo->prepare("
        INSERT INTO audit_logs (actor_id, actor_role, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $oldPayload = !empty($oldValues) ? json_encode($oldValues) : null;
    $newPayload = !empty($newValues) ? json_encode($newValues) : null;

    $stmt->execute([
        $actorId,
        $actorRole,
        $action,
        $entityType,
        $entityId,
        $oldPayload,
        $newPayload,
        $ipAddress,
        $userAgent,
    ]);
}

function fetch_sys_admin_ids(PDO $pdo): array {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE role = 'sys_admin' AND status = 'active'");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function log_suspicious_activity(PDO $pdo, ?int $actorId, ?string $actorRole, string $reason, array $context = []): void {
    $payload = array_merge(['reason' => $reason], $context);
    log_audit($pdo, $actorId, $actorRole, 'suspicious_activity', 'security', null, [], $payload);

    $message = 'Suspicious activity detected: ' . $reason;
    if (!empty($context['email'])) {
        $message .= ' (Email: ' . $context['email'] . ')';
    }
    if (!empty($context['ip_address'])) {
        $message .= ' (IP: ' . $context['ip_address'] . ')';
    }

    foreach (fetch_sys_admin_ids($pdo) as $adminId) {
        create_notification($pdo, (int) $adminId, null, 'security_alert', $message);
    }
}



function fetch_unread_notification_count(PDO $pdo, int $user_id): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL");
    $stmt->execute([$user_id]);
    return (int) $stmt->fetchColumn();
}

function update_shop_rating_summary(PDO $pdo, int $shop_id): void {
    $summary_stmt = $pdo->prepare("
        SELECT COUNT(*) as rating_count, AVG(rating) as avg_rating
        FROM orders
        WHERE shop_id = ?
          AND rating IS NOT NULL
          AND rating > 0
          AND rating_status = 'approved'
    ");
    $summary_stmt->execute([$shop_id]);
    $summary = $summary_stmt->fetch();

    $rating_count = (int) ($summary['rating_count'] ?? 0);
    $avg_rating = $summary['avg_rating'] !== null ? (float) $summary['avg_rating'] : 0.0;

    $update_stmt = $pdo->prepare("UPDATE shops SET rating = ?, rating_count = ?, updated_at = NOW() WHERE id = ?");
    $update_stmt->execute([$avg_rating, $rating_count, $shop_id]);
}
?>