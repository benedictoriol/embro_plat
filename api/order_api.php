<?php
session_start();
require_once '../config/db.php';
require_once '../config/constants.php';

header('Content-Type: application/json');

if (!is_active_user()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user = $_SESSION['user'];
$userRole = canonicalize_role($user['role'] ?? null);
if ($userRole !== null) {
    $user['role'] = $userRole;
    $_SESSION['user']['role'] = $userRole;
}

if (owner_requires_approved_shop($user, $pdo)) {
    http_response_code(403);
    echo json_encode(['error' => 'Owner onboarding is incomplete or awaiting approval']);
    exit();
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = $_POST;
        if (empty($payload)) {
            $payload = json_decode(file_get_contents('php://input'), true) ?? [];
        }

        $order_id = (int) ($payload['order_id'] ?? 0);
        $status = sanitize($payload['status'] ?? '');
        $progress = (int) ($payload['progress'] ?? 0);
        $notes = sanitize($payload['notes'] ?? '');

        if ($order_id <= 0 || $status === '') {
            http_response_code(422);
            echo json_encode(['error' => 'order_id and status are required']);
            exit();
        }

        $order = null;
        $actor_id = (int) ($user['id'] ?? 0);
        if ($userRole === 'owner') {
            $shop_stmt = $pdo->prepare("SELECT id FROM shops WHERE owner_id = ?");
            $shop_stmt->execute([$user['id']]);
            $shop = $shop_stmt->fetch();
            if ($shop) {
                $order_stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND shop_id = ? LIMIT 1");
                $order_stmt->execute([$order_id, $shop['id']]);
                $order = $order_stmt->fetch();
            }
        } elseif ($userRole === 'staff') {
            $permissions = fetch_staff_permissions($pdo, (int) $user['id']);
            if (empty($permissions['update_status'])) {
                http_response_code(403);
                echo json_encode(['error' => 'Permission denied']);
                exit();
            }
            $staff_id = (int) $user['id'];
            $actor_id = $staff_id;
            $order_stmt = $pdo->prepare("
                SELECT o.*
                FROM orders o
                LEFT JOIN job_schedule js ON js.order_id = o.id AND js.staff_id = ?
                WHERE o.id = ? AND (o.assigned_to = ? OR js.staff_id = ?)
                LIMIT 1
            ");
            $order_stmt->execute([$staff_id, $order_id, $staff_id, $staff_id]);
            $order = $order_stmt->fetch();
        } else {
            http_response_code(403);
            echo json_encode(['error' => 'Role not permitted']);
            exit();
        }

        if (!$order) {
            http_response_code(404);
            echo json_encode(['error' => 'Order not found']);
            exit();
        }

        $allow_manual_override = false;
        [$transition_ok, $transition_error, $transition_meta] = order_workflow_apply_transition(
            $pdo,
            $order_id,
            $status,
            ['id' => $actor_id > 0 ? $actor_id : null, 'role' => (string) ($userRole ?? '')],
            $notes !== '' ? $notes : null,
            $allow_manual_override
        );
        if(!$transition_ok) {
            http_response_code(422);
            echo json_encode([
                'error' => $transition_error ?: 'Status transition not allowed',
                'missing_requirements' => $transition_meta['missing_requirements'] ?? [],
            ]);
            exit();
        }

        echo json_encode([
            'message' => 'Order status updated',
            'transition' => $transition_meta['transition'] ?? null,
            'missing_requirements' => [],
        ]);
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit();
    }

    $orders = [];

    if ($userRole === 'owner') {
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
    } elseif ($userRole === 'client') {
        $orders_stmt = $pdo->prepare("
            SELECT o.*, s.shop_name
            FROM orders o
            JOIN shops s ON o.shop_id = s.id
            WHERE o.client_id = ?
            ORDER BY o.created_at DESC
        ");
        $orders_stmt->execute([$user['id']]);
        $orders = $orders_stmt->fetchAll();
    } elseif ($userRole === 'staff') {
        $permissions = fetch_staff_permissions($pdo, (int) $user['id']);
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
            LEFT JOIN job_schedule js ON js.order_id = o.id AND js.staff_id = ?
            WHERE (o.assigned_to = ? OR js.staff_id = ?)
            ORDER BY o.scheduled_date ASC, o.created_at DESC
        ");
        $orders_stmt->execute([$user['id'], $user['id'], $user['id']]);
        $orders = $orders_stmt->fetchAll();
    } else {
        http_response_code(403);
        echo json_encode(['error' => 'Role not permitted']);
        exit();
    }

    if(function_exists('order_exception_summaries') && !empty($orders)) {
        $summaryMap = order_exception_summaries($pdo, array_column($orders, 'id'));
        foreach($orders as &$orderRow) {
            $summary = $summaryMap[(int) ($orderRow['id'] ?? 0)] ?? ['open_count' => 0, 'escalated_count' => 0, 'has_blocking' => false];
            $orderRow['exception_open_count'] = (int) ($summary['open_count'] ?? 0);
            $orderRow['exception_escalated_count'] = (int) ($summary['escalated_count'] ?? 0);
            $orderRow['has_blocking_exception'] = !empty($summary['has_blocking']) ? 1 : 0;
        }
        unset($orderRow);
    }

    echo json_encode(['data' => $orders]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load orders']);
}
