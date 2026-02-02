<?php
session_start();
require_once '../config/db.php';

header('Content-Type: application/json');

if (!is_active_user()) {
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

try {
    $orders = [];

    if ($user['role'] === 'owner') {
        $shop_stmt = $pdo->prepare("SELECT id FROM shops WHERE owner_id = ?");
        $shop_stmt->execute([$user['id']]);
        $shop = $shop_stmt->fetch();

        if ($shop) {
            $orders_stmt = $pdo->prepare("
                SELECT o.*, u.fullname as client_name
                FROM orders o
                JOIN users u ON o.client_id = u.id
                WHERE o.shop_id = ?
                ORDER BY o.created_at DESC
            ");
            $orders_stmt->execute([$shop['id']]);
            $orders = $orders_stmt->fetchAll();
        }
    } elseif ($user['role'] === 'client') {
        $orders_stmt = $pdo->prepare("
            SELECT o.*, s.shop_name
            FROM orders o
            JOIN shops s ON o.shop_id = s.id
            WHERE o.client_id = ?
            ORDER BY o.created_at DESC
        ");
        $orders_stmt->execute([$user['id']]);
        $orders = $orders_stmt->fetchAll();
    } elseif ($user['role'] === 'employee') {
        $permissions = fetch_employee_permissions($pdo, (int) $user['id']);
        if (empty($permissions['view_jobs'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Permission denied']);
            exit();
        }
        $orders_stmt = $pdo->prepare("
            SELECT o.*, u.fullname as client_name, s.shop_name
            FROM orders o
            JOIN users u ON o.client_id = u.id
            JOIN shops s ON o.shop_id = s.id
            LEFT JOIN job_schedule js ON js.order_id = o.id AND js.employee_id = ?
            WHERE (o.assigned_to = ? OR js.employee_id = ?)
            ORDER BY o.scheduled_date ASC, o.created_at DESC
        ");
        $orders_stmt->execute([$user['id'], $user['id'], $user['id']]);
        $orders = $orders_stmt->fetchAll();
    } else {
        http_response_code(403);
        echo json_encode(['error' => 'Role not permitted']);
        exit();
    }

    echo json_encode(['data' => $orders]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load orders']);
}
