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

require_once __DIR__ . '/auth_functions.php';
require_once __DIR__ . '/notification_functions.php';

// Sanitize input
function sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)));
}

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



function fetch_unread_notification_count(PDO $pdo, int $user_id): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL");
    $stmt->execute([$user_id]);
    return (int) $stmt->fetchColumn();
}
?>