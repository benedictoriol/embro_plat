<?php
session_start();
require_once '../config/db.php';
require_once '../config/constants.php';
require_once '../config/design_persistence.php';

header('Content-Type: application/json');

if (!check_role('client')) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$user = $_SESSION['user'];
$orderId = isset($_GET['order_id']) && (int) $_GET['order_id'] > 0 ? (int) $_GET['order_id'] : null;
$versionId = (int) ($_GET['version_id'] ?? 0);

try {
    if ($orderId !== null) {
        $order = design_persist_fetch_order_for_user($pdo, $orderId, $user);
        if (!$order) {
            http_response_code(404);
            echo json_encode(['error' => 'Order not found.']);
            exit();
        }
    }

$versions = design_persist_list_versions($pdo, (int) $user['id'], $orderId);
    $selectedVersion = design_persist_get_version($pdo, (int) $user['id'], $orderId, $versionId, 0);

    echo json_encode([
        'order_id' => $orderId,
        'versions' => $versions,
        'selected_version' => $selectedVersion
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load design versions.']);
}