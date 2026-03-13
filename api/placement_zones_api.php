<?php
session_start();
require_once '../config/db.php';
require_once '../config/design_helpers.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$productId = isset($_GET['product_id']) ? (int) $_GET['product_id'] : null;
$modelKey = trim((string) ($_GET['model_key'] ?? 'tshirt'));
$placementType = isset($_GET['placement_type']) ? trim((string) $_GET['placement_type']) : null;

try {
    $zones = list_placement_zones($pdo, $productId, $modelKey, $placementType);
    echo json_encode(['data' => $zones]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load placement zones']);
}
?>
