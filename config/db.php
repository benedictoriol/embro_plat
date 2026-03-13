<?php
function app_env(): string {
    static $environment = null;
    if($environment !== null) {
        return $environment;
    }

    $rawEnv = getenv('APP_ENV');
    if(!is_string($rawEnv) || trim($rawEnv) === '') {
        $rawEnv = getenv('APPLICATION_ENV');
    }

    $normalized = strtolower(trim((string) $rawEnv));
    $environment = $normalized !== '' ? $normalized : 'development';

    return $environment;
}

function app_is_production(): bool {
    $env = app_env();
    return in_array($env, ['prod', 'production'], true);
}

function app_is_development(): bool {
    return !app_is_production();
}

function log_runtime_error(string $context, Throwable $error): void {
    error_log(sprintf('[%s] %s in %s:%d', $context, $error->getMessage(), $error->getFile(), $error->getLine()));
}

function user_safe_error_message(string $productionMessage, string $developmentMessage): string {
    return app_is_production() ? $productionMessage : $developmentMessage;
}

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
    log_runtime_error('database_connection', $e);
    $safeMessage = user_safe_error_message(
        'Unable to connect to the service right now. Please try again shortly.',
        'Connection failed: ' . $e->getMessage()
    );
    die($safeMessage);
}

require_once __DIR__ . '/system_settings.php';

$system_timezone = 'Asia/Manila';
try {
    $system_timezone = (string) system_setting_get($pdo, 'platform', 'timezone', 'Asia/Manila');
} catch (PDOException $e) {
    $system_timezone = 'Asia/Manila';
}
if ($system_timezone === '' || !in_array($system_timezone, timezone_identifiers_list(), true)) {
    $system_timezone = 'Asia/Manila';
}
date_default_timezone_set($system_timezone);

require_once __DIR__ . '/auth_functions.php';
require_once __DIR__ . '/notification_functions.php';
require_once __DIR__ . '/messaging_helpers.php';
require_once __DIR__ . '/order_helpers.php';
require_once __DIR__ . '/payment_helpers.php';
require_once __DIR__ . '/payment_webhook_helpers.php';
require_once __DIR__ . '/design_helpers.php';
require_once __DIR__ . '/queue_helpers.php';
require_once __DIR__ . '/scheduling_helpers.php';
require_once __DIR__ . '/inventory_helpers.php';
require_once __DIR__ . '/qc_helpers.php';
require_once __DIR__ . '/quote_helpers.php';
require_once __DIR__ . '/customer_profile_helpers.php';
require_once __DIR__ . '/notification_reminders.php';
require_once __DIR__ . '/moderation_helpers.php';
require_once __DIR__ . '/exception_helpers.php';
require_once __DIR__ . '/exception_automation_helpers.php';

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


// NOTE:
// Schema mutations were removed from runtime bootstrap.
// Apply SQL files under database/migrations during deployment or upgrades.


function sanitize_audit_meta(array $meta): array {
    $sensitive_fragments = ['password', 'token', 'secret', 'otp', 'card', 'cvv'];
    $clean = [];

    foreach($meta as $key => $value) {
        $key_string = strtolower((string) $key);
        $is_sensitive = false;
        foreach($sensitive_fragments as $fragment) {
            if(str_contains($key_string, $fragment)) {
                $is_sensitive = true;
                break;
            }
        }

        if($is_sensitive) {
            continue;
        }

        if(is_array($value)) {
            $clean[$key] = sanitize_audit_meta($value);
            continue;
        }

        $clean[$key] = $value;
    }

    return $clean;
}

function log_audit(PDO $pdo, ?int $actorId, ?string $actorRole, string $action, string $entityType, ?int $entityId, array $oldValues = [], array $newValues = [], array $meta = []): void {
    $stmt = $pdo->prepare("
        INSERT INTO audit_logs (actor_id, actor_role, action, entity_type, entity_id, old_values, new_values, meta_json, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $oldPayload = !empty($oldValues) ? json_encode($oldValues) : null;
    $newPayload = !empty($newValues) ? json_encode($newValues) : null;
    $metaPayload = !empty($meta) ? json_encode(sanitize_audit_meta($meta)) : null;

    $stmt->execute([
        $actorId,
        $actorRole,
        $action,
        $entityType,
        $entityId,
        $oldPayload,
        $newPayload,
        $metaPayload,
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
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND " . notification_unread_sql_condition($pdo));
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