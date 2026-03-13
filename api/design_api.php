<?php
session_start();
require_once '../config/db.php';
require_once '../config/constants.php';
require_once '../config/design_persistence.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user = $_SESSION['user'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            http_response_code(422);
            echo json_encode(['error' => 'Invalid payload.']);
            exit();
        }

        $result = design_persist_save($pdo, $user, $payload);
        echo json_encode([
            'message' => 'Design saved',
            'data' => $result['data']
        ]);
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $orderId = isset($_GET['order_id']) && (int) $_GET['order_id'] > 0 ? (int) $_GET['order_id'] : null;
        $versionId = isset($_GET['version_id']) ? (int) $_GET['version_id'] : 0;
        $versionNumber = isset($_GET['version']) ? (int) $_GET['version'] : 0;

        if ($orderId !== null) {
            $order = design_persist_fetch_order_for_user($pdo, $orderId, $user);
            if (!$order) {
                http_response_code(404);
                echo json_encode(['error' => 'Order not found']);
                exit();
            }
        }

        if (isset($_GET['versions'])) {
            echo json_encode(['data' => design_persist_list_versions($pdo, (int) $user['id'], $orderId)]);
            exit();
        }

        $version = design_persist_get_version($pdo, (int) $user['id'], $orderId, $versionId, $versionNumber);
        if ($version === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Design version not found']);
            exit();
        }

        echo json_encode(['data' => $version]);
        exit();
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
} catch (RuntimeException $e) {
    $message = $e->getMessage();
    $status = $message === 'Order not found.' || $message === 'Design version not found.' ? 404 : 500;
    http_response_code($status);
    echo json_encode(['error' => $status === 500 ? 'Failed to process design request' : $message]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Failed to process design request']);
}
?>
