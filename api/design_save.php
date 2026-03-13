<?php
session_start();
require_once '../config/db.php';
require_once '../config/constants.php';
require_once '../config/design_persistence.php';
require_once '../includes/media_manager.php';

header('Content-Type: application/json');

if (!check_role('client')) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$user = $_SESSION['user'];
$orderId = isset($_POST['order_id']) && (int) $_POST['order_id'] > 0 ? (int) $_POST['order_id'] : null;
$productId = isset($_POST['product_id']) && (int) $_POST['product_id'] > 0 ? (int) $_POST['product_id'] : null;
$legacyProjectId = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
$designName = trim((string) ($_POST['design_name'] ?? ($legacyProjectId > 0 ? 'Project #' . $legacyProjectId : 'Untitled Design')));
$designJson = trim((string) ($_POST['design_json'] ?? ''));

if ($designJson === '') {
    http_response_code(422);
    echo json_encode(['error' => 'design_json is required.']);
    exit();
}

$previewPath = null;
if (isset($_FILES['png_preview']) && $_FILES['png_preview']['error'] !== UPLOAD_ERR_NO_FILE) {
    $upload = save_uploaded_media($_FILES['png_preview'], ['png'], MAX_FILE_SIZE, 'designs', 'design_preview', (string) ($orderId ?? $user['id']));
    if (!$upload['success']) {
        http_response_code(422);
        echo json_encode(['error' => $upload['error']]);
        exit();
    }
    $previewPath = '../assets/uploads/designs/' . $upload['filename'];
}

try {
    $result = design_persist_save($pdo, $user, [
        'order_id' => $orderId,
        'product_id' => $productId,
        'design_name' => $designName,
        'design_json' => $designJson,
        'preview_image_path' => $previewPath
    ]);

    cleanup_media($pdo);

    echo json_encode([
        'message' => 'Design version saved.',
        'version' => [
            'id' => $result['data']['id'],
            'order_id' => $result['data']['order_id'],
            'version_number' => $result['version_number'],
            'preview_image_path' => $result['data']['preview_image_path']
        ]
    ]);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    http_response_code($e->getMessage() === 'Order not found.' ? 404 : 500);
    echo json_encode(['error' => $e->getMessage() === 'Order not found.' ? 'Order not found.' : 'Failed to save design version.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save design version.']);
}