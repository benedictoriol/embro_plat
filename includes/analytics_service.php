<?php

function build_shop_filter_clause(?array $shopIds, string $column, array &$params): string {
    if (!is_array($shopIds)) {
        return '';
    }

    if (count($shopIds) === 0) {
        return ' AND 0=1';
    }

    $placeholders = implode(',', array_fill(0, count($shopIds), '?'));
    $params = array_values($shopIds);
    return " AND {$column} IN ({$placeholders})";
}

function fetch_order_analytics(PDO $pdo, ?array $shopIds = null): array {
    $orderParams = [];
    $orderShopClause = build_shop_filter_clause($shopIds, 'shop_id', $orderParams);

    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_orders,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END), 0) AS completed_orders,
            COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_orders,
            COALESCE(SUM(CASE WHEN status IN ('accepted', 'in_progress') THEN 1 ELSE 0 END), 0) AS active_orders,
            COALESCE(SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END), 0) AS cancelled_orders,
            COALESCE(AVG(CASE WHEN status = 'completed' AND price IS NOT NULL THEN price END), 0) AS avg_order_value
        FROM orders
        WHERE 1=1 {$orderShopClause}
    ");
    $stmt->execute($orderParams);
    $overview = $stmt->fetch() ?: [];

    $paymentParams = [];
    $paymentShopClause = build_shop_filter_clause($shopIds, 'p.shop_id', $paymentParams);
    $paymentStmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT CASE WHEN p.status = 'verified' THEN p.order_id END) AS paid_orders,
            COUNT(DISTINCT CASE WHEN p.status = 'pending' THEN p.order_id END) AS pending_payments,
            COALESCE(SUM(CASE WHEN p.status = 'verified' THEN p.amount ELSE 0 END), 0) AS total_revenue
        FROM payments p
        WHERE 1=1 {$paymentShopClause}
    ");
    $paymentStmt->execute($paymentParams);
    $paymentOverview = $paymentStmt->fetch() ?: [];

    $ratingParams = [];
    $ratingShopClause = build_shop_filter_clause($shopIds, 'shop_id', $ratingParams);
    $ratingStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS rating_count,
            COALESCE(AVG(rating), 0) AS average_rating
        FROM orders
        WHERE rating IS NOT NULL
          AND rating > 0
          AND rating_status = 'approved'
          {$ratingShopClause}
    ");
    $ratingStmt->execute($ratingParams);
    $ratingOverview = $ratingStmt->fetch() ?: [];

    return [
        'total_orders' => (int) ($overview['total_orders'] ?? 0),
        'completed_orders' => (int) ($overview['completed_orders'] ?? 0),
        'pending_orders' => (int) ($overview['pending_orders'] ?? 0),
        'active_orders' => (int) ($overview['active_orders'] ?? 0),
        'cancelled_orders' => (int) ($overview['cancelled_orders'] ?? 0),
        'paid_orders' => (int) ($paymentOverview['paid_orders'] ?? 0),
        'pending_payments' => (int) ($paymentOverview['pending_payments'] ?? 0),
        'total_revenue' => (float) ($paymentOverview['total_revenue'] ?? 0),
        'avg_order_value' => (float) ($overview['avg_order_value'] ?? 0),
        'average_rating' => (float) ($ratingOverview['average_rating'] ?? 0),
        'rating_count' => (int) ($ratingOverview['rating_count'] ?? 0),
    ];
}

function fetch_staff_count(PDO $pdo, ?array $shopIds = null): int {
    $params = [];
    $shopClause = '';

    if (is_array($shopIds)) {
        if (count($shopIds) === 0) {
            $shopClause = ' AND 0=1';
        } else {
            $placeholders = implode(',', array_fill(0, count($shopIds), '?'));
            $shopClause = " AND ss.shop_id IN ($placeholders)";
            $params = $shopIds;
        }
    }

    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM shop_staffs ss
        INNER JOIN users u ON u.id = ss.user_id
        WHERE ss.status = 'active'
          AND u.status = 'active'
          {$shopClause}
    ");
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}
