<?php
session_start();
require_once '../config/db.php';
require_once '../includes/analytics_service.php';

header('Content-Type: application/json');

$userRole = canonicalize_role($_SESSION['user']['role'] ?? null);
if (!is_active_user() || $userRole !== 'sys_admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

try {
    $system = fetch_system_overview($pdo);
    $orders = fetch_order_analytics($pdo, null);
    $status = fetch_order_status_breakdown($pdo, null);
    $activity = fetch_system_activity_overview($pdo, 7);

    $totalOrders = (int) ($orders['total_orders'] ?? 0);
    $completedOrders = (int) ($orders['completed_orders'] ?? 0);
    $cancelledOrders = (int) ($orders['cancelled_orders'] ?? 0);
    $paidOrders = (int) ($orders['paid_orders'] ?? 0);

    echo json_encode([
        'data' => [
            'total_shops' => $system['total_shops'],
            'active_users' => $system['active_users'],
            'total_orders' => $totalOrders,
            'completed_orders' => $completedOrders,
            'pending_orders' => (int) ($orders['pending_orders'] ?? 0),
            'active_orders' => (int) ($orders['active_orders'] ?? 0),
            'cancelled_orders' => $cancelledOrders,
            'paid_orders' => $paidOrders,
            'pending_payments' => (int) ($orders['pending_payments'] ?? 0),
            'total_revenue' => (float) ($system['total_revenue'] ?? 0),
            'avg_order_value' => (float) ($orders['avg_order_value'] ?? 0),
            'pending_approvals' => (int) ($system['pending_approvals'] ?? 0),
            'order_status_breakdown' => $status,
            'system_activity' => $activity,
            'completion_rate' => $totalOrders > 0 ? round(($completedOrders / $totalOrders) * 100, 1) : 0.0,
            'cancellation_rate' => $totalOrders > 0 ? round(($cancelledOrders / $totalOrders) * 100, 1) : 0.0,
            'payment_rate' => $totalOrders > 0 ? round(($paidOrders / $totalOrders) * 100, 1) : 0.0,
        ],
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load analytics data']);
}
