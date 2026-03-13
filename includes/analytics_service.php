<?php

function dss_table_columns(PDO $pdo, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    if (!table_exists($pdo, $table)) {
        $cache[$table] = [];
        return [];
    }

    $stmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?");
    $stmt->execute([$table]);

    $columns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $columns[(string) ($row['column_name'] ?? '')] = true;
    }

    $cache[$table] = $columns;
    return $columns;
}

function dss_table_has_column(PDO $pdo, string $table, string $column): bool {
    $columns = dss_table_columns($pdo, $table);
    return isset($columns[$column]);
}

function ensure_shop_metrics_table(PDO $pdo): void {
    if (!table_exists($pdo, 'shop_metrics')) {
        return;
    }

    $requiredColumns = [
        'shop_id' => 'INT(11) NOT NULL',
        'avg_rating' => 'DECIMAL(4,2) DEFAULT NULL',
        'review_count' => 'INT(11) DEFAULT 0',
        'completion_rate' => 'DECIMAL(6,4) DEFAULT NULL',
        'avg_turnaround_days' => 'DECIMAL(8,2) DEFAULT NULL',
        'price_index' => 'DECIMAL(8,4) DEFAULT NULL',
        'cancellation_rate' => 'DECIMAL(6,4) DEFAULT NULL',
        'availability_flag' => 'TINYINT(1) DEFAULT NULL',
        'updated_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ];

    foreach ($requiredColumns as $column => $definition) {
        if (dss_table_has_column($pdo, 'shop_metrics', $column)) {
            continue;
        }
        try {
            $pdo->exec('ALTER TABLE shop_metrics ADD COLUMN ' . $column . ' ' . $definition);
        } catch (PDOException $e) {
            error_log('shop_metrics schema alignment failed for ' . $column . ': ' . $e->getMessage());
        }
    }
}

function ensure_dss_logs_table(PDO $pdo): void {
    if (!table_exists($pdo, 'dss_logs')) {
        return;
    }

    $requiredColumns = [
        'actor_user_id' => 'INT(11) DEFAULT NULL',
        'shop_id' => 'INT(11) DEFAULT NULL',
        'action' => "VARCHAR(100) NOT NULL DEFAULT 'unknown_action'",
        'context_json' => 'LONGTEXT DEFAULT NULL',
        'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ];

    foreach ($requiredColumns as $column => $definition) {
        if (dss_table_has_column($pdo, 'dss_logs', $column)) {
            continue;
        }
        try {
            $pdo->exec('ALTER TABLE dss_logs ADD COLUMN ' . $column . ' ' . $definition);
        } catch (PDOException $e) {
            error_log('dss_logs schema alignment failed for ' . $column . ': ' . $e->getMessage());
        }
    }
}

function write_dss_log(PDO $pdo, string $action, ?int $shopId = null, array $payload = []): void {
    ensure_dss_logs_table($pdo);

    if (!table_exists($pdo, 'dss_logs')) {
        return;
    }

    $actorUserId = $_SESSION['user']['id'] ?? null;
    if (!is_int($actorUserId)) {
        $actorUserId = is_numeric($actorUserId) ? (int) $actorUserId : null;
    }

    $context = $payload;
    if ($shopId !== null) {
        $context['shop_id'] = $shopId;
    }

    $contextJson = null;
    if (!empty($context)) {
        $encoded = json_encode($context);
        if ($encoded !== false) {
            $contextJson = $encoded;
        }
    }

    $legacyContextJson = null;
    if ($contextJson !== null) {
        $legacyEncoded = json_encode(['payload' => $context], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($legacyEncoded !== false) {
            $legacyContextJson = $legacyEncoded;
        }
    }

    $columns = [];
    $values = [];

    if (dss_table_has_column($pdo, 'dss_logs', 'actor_user_id')) {
        $columns[] = 'actor_user_id';
        $values[] = $actorUserId;
    }
    if (dss_table_has_column($pdo, 'dss_logs', 'user_id')) {
        $columns[] = 'user_id';
        $values[] = $actorUserId;
    }
    if (dss_table_has_column($pdo, 'dss_logs', 'action')) {
        $columns[] = 'action';
        $values[] = $action;
    }
    if (dss_table_has_column($pdo, 'dss_logs', 'activity_type')) {
        $columns[] = 'activity_type';
        $values[] = $action;
    }
    if (dss_table_has_column($pdo, 'dss_logs', 'shop_id')) {
        $columns[] = 'shop_id';
        $values[] = $shopId;
    }
    if (dss_table_has_column($pdo, 'dss_logs', 'context_json')) {
        $columns[] = 'context_json';
        $values[] = $contextJson;
    }
    if (dss_table_has_column($pdo, 'dss_logs', 'payload_json')) {
        $columns[] = 'payload_json';
        $values[] = $contextJson;
    }
    if (dss_table_has_column($pdo, 'dss_logs', 'metadata_json')) {
        $columns[] = 'metadata_json';
        $values[] = $contextJson;
    }
    if (dss_table_has_column($pdo, 'dss_logs', 'request_context')) {
        $columns[] = 'request_context';
        $values[] = $legacyContextJson;
    }

    if (empty($columns)) {
        return;
    }

    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $sql = 'INSERT INTO dss_logs (' . implode(', ', $columns) . ") VALUES ($placeholders)";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
    } catch (PDOException $e) {
        error_log('DSS log write failed: ' . $e->getMessage());
    }
}

function fetch_dss_weights(PDO $pdo): array {
    $defaults = [
        'rating_weight' => 0.24,
        'review_weight' => 0.12,
        'completion_weight' => 0.2,
        'turnaround_weight' => 0.14,
        'price_weight' => 0.1,
        'cancel_weight' => 0.1,
        'availability_weight' => 0.08,
        'location_weight' => 0.02,
    ];

    if (table_exists($pdo, 'dss_global_weights')) {
        $stmt = $pdo->query("SELECT metric_key, weight_value FROM dss_global_weights");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['metric_key'] ?? '';
            if (array_key_exists($key, $defaults)) {
                $defaults[$key] = (float) $row['weight_value'];
            }
        }
        return $defaults;
    }

    $keys = array_keys($defaults);
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $pdo->prepare("SELECT config_key, config_value FROM dss_configurations WHERE config_key IN ({$placeholders})");
    $stmt->execute($keys);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = $row['config_key'] ?? '';
        if (array_key_exists($key, $defaults)) {
            $defaults[$key] = (float) $row['config_value'];
        }
    }

    return $defaults;
}

function refresh_shop_metrics(PDO $pdo): void {
    ensure_shop_metrics_table($pdo);

    if (!table_exists($pdo, 'shop_metrics')) {
        return;
    }

    $globalAvgStmt = $pdo->query("SELECT COALESCE(AVG(price), 0) FROM orders WHERE price IS NOT NULL AND price > 0");
    $globalAvgPrice = (float) $globalAvgStmt->fetchColumn();

    $shopStmt = $pdo->query("SELECT id, rating FROM shops WHERE status = 'active'");
    $shops = $shopStmt->fetchAll(PDO::FETCH_ASSOC);

    $orderMetricStmt = $pdo->prepare("SELECT
            COUNT(*) AS total_orders,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_orders,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_orders,
            AVG(CASE WHEN status = 'completed' AND completed_at IS NOT NULL THEN TIMESTAMPDIFF(DAY, created_at, completed_at) END) AS avg_turnaround_days,
            AVG(CASE WHEN price IS NOT NULL AND price > 0 THEN price END) AS avg_price,
            AVG(CASE WHEN rating_status = 'approved' AND rating IS NOT NULL AND rating > 0 THEN rating END) AS avg_rating,
            SUM(CASE WHEN rating_status = 'approved' AND rating IS NOT NULL AND rating > 0 THEN 1 ELSE 0 END) AS review_count
        FROM orders
        WHERE shop_id = ?");

    $active_workload_statuses = order_workflow_active_statuses();
    $workload_placeholders = order_workflow_status_placeholders($active_workload_statuses);

    $availabilityStmt = $pdo->prepare("SELECT
            COALESCE(SUM(max_active_orders), 0) AS total_capacity,
            (
                SELECT COUNT(*)
                FROM orders o
                WHERE o.shop_id = ss.shop_id
                  AND o.status IN ($workload_placeholders)
            ) AS active_orders
        FROM shop_staffs ss
        WHERE ss.shop_id = ?
          AND ss.status = 'active'");

    $metricColumns = [
        'shop_id',
        'avg_rating',
        'review_count',
        'completion_rate',
        'avg_turnaround_days',
        'price_index',
        'cancellation_rate',
        'availability_flag',
    ];

    $availableMetricColumns = [];
    foreach ($metricColumns as $column) {
        if (dss_table_has_column($pdo, 'shop_metrics', $column)) {
            $availableMetricColumns[] = $column;
        }
    }

    if (!in_array('shop_id', $availableMetricColumns, true)) {
        return;
    }

    $upsertUpdateColumns = array_values(array_filter($availableMetricColumns, static fn(string $column): bool => $column !== 'shop_id'));
    $upsertSql = 'INSERT INTO shop_metrics (' . implode(', ', $availableMetricColumns) . ') VALUES (' . implode(', ', array_fill(0, count($availableMetricColumns), '?')) . ')';
    if (!empty($upsertUpdateColumns)) {
        $upsertSql .= ' ON DUPLICATE KEY UPDATE ';
        $upsertSql .= implode(', ', array_map(static fn(string $column): string => $column . ' = VALUES(' . $column . ')', $upsertUpdateColumns));
        if (dss_table_has_column($pdo, 'shop_metrics', 'updated_at')) {
            $upsertSql .= ', updated_at = CURRENT_TIMESTAMP';
        }
    }

    $upsertStmt = $pdo->prepare($upsertSql);

    foreach ($shops as $shop) {
        $shopId = (int) $shop['id'];
        if ($shopId <= 0) {
            continue;
        }
        $orderMetricStmt->execute([$shopId]);
        $metrics = $orderMetricStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $totalOrders = (int) ($metrics['total_orders'] ?? 0);
        $completedOrders = (int) ($metrics['completed_orders'] ?? 0);
        $cancelledOrders = (int) ($metrics['cancelled_orders'] ?? 0);
        $avgPrice = isset($metrics['avg_price']) ? (float) $metrics['avg_price'] : null;

        $completionRate = $totalOrders > 0 ? ($completedOrders / $totalOrders) : null;
        $cancellationRate = $totalOrders > 0 ? ($cancelledOrders / $totalOrders) : null;
        $priceIndex = ($avgPrice !== null && $globalAvgPrice > 0) ? ($avgPrice / $globalAvgPrice) : null;
        $avgTurnaround = isset($metrics['avg_turnaround_days']) ? (float) $metrics['avg_turnaround_days'] : null;

        $avgRating = null;
        if (!empty($metrics['avg_rating'])) {
            $avgRating = (float) $metrics['avg_rating'];
        } elseif (isset($shop['rating'])) {
            $avgRating = (float) $shop['rating'];
        }

        $availabilityStmt->execute(array_merge([$shopId], $active_workload_statuses));
        $availabilityRow = $availabilityStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $totalCapacity = (int) ($availabilityRow['total_capacity'] ?? 0);
        $activeOrders = (int) ($availabilityRow['active_orders'] ?? 0);
        $availabilityFlag = $totalCapacity > 0 ? (int) ($activeOrders < $totalCapacity) : null;

        $reviewCount = (int) ($metrics['review_count'] ?? 0);

        $metricValues = [
            'shop_id' => $shopId,
            'avg_rating' => $avgRating,
            'review_count' => $reviewCount,
            'completion_rate' => $completionRate,
            'avg_turnaround_days' => $avgTurnaround,
            'price_index' => $priceIndex,
            'cancellation_rate' => $cancellationRate,
            'availability_flag' => $availabilityFlag,
        ];

        $insertValues = [];
        foreach ($availableMetricColumns as $column) {
            $insertValues[] = $metricValues[$column] ?? null;
        }

        try {
            $upsertStmt->execute($insertValues);
        } catch (PDOException $e) {
            error_log('shop_metrics upsert failed for shop ' . $shopId . ': ' . $e->getMessage());
            continue;
        }
        
        write_dss_log($pdo, 'shop_metrics_refreshed', $shopId, [
            'avg_rating' => $avgRating,
            'review_count' => $reviewCount,
            'completion_rate' => $completionRate,
            'avg_turnaround_days' => $avgTurnaround,
            'price_index' => $priceIndex,
            'cancellation_rate' => $cancellationRate,
            'availability_flag' => $availabilityFlag,
        ]);
    }
}

function normalize_metric(float $value, float $min, float $max, bool $inverse = false): float {
    if ($max <= $min) {
        return 0.5;
    }

    $normalized = ($value - $min) / ($max - $min);
    $normalized = max(0.0, min(1.0, $normalized));
    if ($inverse) {
        $normalized = 1.0 - $normalized;
    }
    
    return $normalized;
}

function calculate_location_match_score(string $locationHint, string $shopAddress, string $shopName): float {
    $needle = mb_strtolower(trim($locationHint));
    if ($needle === '') {
        return 0.5;
    }

    $haystack = mb_strtolower(trim($shopAddress . ' ' . $shopName));
    if ($haystack === '') {
        return 0.0;
    }

    if (mb_strpos($haystack, $needle) !== false) {
        return 1.0;
    }

    return 0.0;
}

function compute_dss_ranked_shops(PDO $pdo, array $shops, ?string $locationHint = null): array {
    $weights = fetch_dss_weights($pdo);
    
    $metricColumns = [
        'shop_id',
        'avg_rating',
        'review_count',
        'completion_rate',
        'avg_turnaround_days',
        'price_index',
        'cancellation_rate',
        'availability_flag',
        'updated_at',
    ];

    $availableMetricColumns = [];
    foreach ($metricColumns as $column) {
        if (dss_table_has_column($pdo, 'shop_metrics', $column)) {
            $availableMetricColumns[] = $column;
        }
    }

    if (!in_array('shop_id', $availableMetricColumns, true)) {
        return array_map(function(array $shop) use ($locationHint) {
            $shop['dss_score'] = 0.0;
            $shop['dss_is_fallback'] = true;
            $shop['dss_breakdown'] = [
                'rating' => 0,
                'review_count' => 0,
                'review_norm' => 0,
                'completion' => 0,
                'turnaround' => 0.5,
                'price' => 0.5,
                'cancellation' => 1,
                'availability' => 0.5,
                'location' => round(calculate_location_match_score((string) ($locationHint ?? ''), (string) ($shop['address'] ?? ''), (string) ($shop['shop_name'] ?? '')), 4),
            ];
            return $shop;
        }, $shops);
    }

    $metricStmt = $pdo->query('SELECT ' . implode(', ', $availableMetricColumns) . ' FROM shop_metrics');
    $metricsMap = [];
    foreach ($metricStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $metricsMap[(int) $row['shop_id']] = $row;
    }

    $reviewCounts = [];
    $turnaroundValues = [];
    $priceIndexes = [];

    foreach ($shops as $shop) {
        $metrics = $metricsMap[(int) ($shop['id'] ?? 0)] ?? null;
        if (!$metrics) {
            continue;
        }
        if (isset($metrics['review_count'])) {
            $reviewCounts[] = (float) $metrics['review_count'];
        }
        if ($metrics['avg_turnaround_days'] !== null) {
            $turnaroundValues[] = (float) $metrics['avg_turnaround_days'];
        }
        if ($metrics['price_index'] !== null) {
            $priceIndexes[] = (float) $metrics['price_index'];
        }
    }

    $reviewMin = !empty($reviewCounts) ? min($reviewCounts) : 0.0;
    $reviewMax = !empty($reviewCounts) ? max($reviewCounts) : 1.0;
    $turnaroundMin = !empty($turnaroundValues) ? min($turnaroundValues) : 1.0;
    $turnaroundMax = !empty($turnaroundValues) ? max($turnaroundValues) : 1.0;
    $priceMin = !empty($priceIndexes) ? min($priceIndexes) : 1.0;
    $priceMax = !empty($priceIndexes) ? max($priceIndexes) : 1.0;

    return array_map(function(array $shop) use ($metricsMap, $weights, $reviewMin, $reviewMax, $turnaroundMin, $turnaroundMax, $priceMin, $priceMax, $locationHint) {
        $shopId = (int) ($shop['id'] ?? 0);
        $metrics = $metricsMap[$shopId] ?? [];

        // Normalization notes:
        // - Better-when-higher metrics (rating/completion/reviews/availability/location) trend toward 1.
        // - Better-when-lower metrics (turnaround/price_index/cancellation) are inverted so lower raw values score higher.
        $ratingNorm = min(1.0, max(0.0, ((float) ($metrics['avg_rating'] ?? $shop['rating'] ?? 0.0)) / 5));
        $reviewNorm = normalize_metric((float) ($metrics['review_count'] ?? 0), $reviewMin, $reviewMax);
        $completionNorm = min(1.0, max(0.0, (float) ($metrics['completion_rate'] ?? 0.0)));
        $turnaroundNorm = isset($metrics['avg_turnaround_days']) && $metrics['avg_turnaround_days'] !== null
            ? normalize_metric((float) $metrics['avg_turnaround_days'], $turnaroundMin, $turnaroundMax, true)
            : 0.5;
        $priceNorm = isset($metrics['price_index']) && $metrics['price_index'] !== null
            ? normalize_metric((float) $metrics['price_index'], $priceMin, $priceMax, true)
            : 0.5;
        $cancelNorm = 1.0 - min(1.0, max(0.0, (float) ($metrics['cancellation_rate'] ?? 0.0)));
        $availabilityNorm = ($metrics['availability_flag'] ?? null) === null ? 0.5 : ((int) $metrics['availability_flag'] === 1 ? 1.0 : 0.0);
        $locationNorm = calculate_location_match_score((string) ($locationHint ?? ''), (string) ($shop['address'] ?? ''), (string) ($shop['shop_name'] ?? ''));

        $weightedScore = (
            $weights['rating_weight'] * $ratingNorm
            + $weights['review_weight'] * $reviewNorm
            + $weights['completion_weight'] * $completionNorm
            + $weights['turnaround_weight'] * $turnaroundNorm
            + $weights['price_weight'] * $priceNorm
            + $weights['cancel_weight'] * $cancelNorm
            + $weights['availability_weight'] * $availabilityNorm
            + $weights['location_weight'] * $locationNorm
        );

        $hasCore = isset($metrics['avg_rating'], $metrics['completion_rate'], $metrics['price_index'], $metrics['cancellation_rate']);
        $shop['dss_score'] = round($weightedScore, 6);
        $shop['dss_is_fallback'] = !$hasCore;
        $shop['dss_breakdown'] = [
            'rating' => round($ratingNorm, 4),
            'review_count' => (int) ($metrics['review_count'] ?? 0),
            'review_norm' => round($reviewNorm, 4),
            'completion' => round($completionNorm, 4),
            'turnaround' => round($turnaroundNorm, 4),
            'price' => round($priceNorm, 4),
            'cancellation' => round($cancelNorm, 4),
            'availability' => round($availabilityNorm, 4),
            'location' => round($locationNorm, 4),
        ];
        $shop['avg_turnaround_days'] = isset($metrics['avg_turnaround_days']) ? (float) $metrics['avg_turnaround_days'] : null;
        $shop['review_count'] = (int) ($metrics['review_count'] ?? 0);
        $shop['availability_flag'] = $metrics['availability_flag'] ?? null;
        return $shop;
    }, $shops);
}

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
            COALESCE(SUM(CASE WHEN status IN ('accepted', 'digitizing', 'production_pending', 'production', 'production_rework', 'qc_pending', 'ready_for_delivery', 'in_progress') THEN 1 ELSE 0 END), 0) AS active_orders,
            COALESCE(SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END), 0) AS cancelled_orders,
            COALESCE(AVG(CASE WHEN status = 'completed' AND price IS NOT NULL THEN price END), 0) AS avg_order_value,
            COALESCE(AVG(CASE WHEN status = 'completed' AND completed_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR, created_at, completed_at) END), 0) AS avg_turnaround_hours
            FROM orders
        WHERE 1=1 {$orderShopClause}
    ");
    $stmt->execute($orderParams);
    $overview = $stmt->fetch() ?: [];

    $paymentParams = [];
    $paymentShopClause = build_shop_filter_clause($shopIds, 'o.shop_id', $paymentParams);
    $paymentStmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT CASE WHEN o.payment_status = 'paid' THEN o.id END) AS paid_orders,
            COUNT(DISTINCT CASE WHEN p.status = 'pending' THEN p.order_id END) AS pending_payments,
            COALESCE(SUM(CASE WHEN p.status = 'verified' THEN p.amount ELSE 0 END), 0) AS total_revenue
        FROM orders o
        LEFT JOIN payments p ON p.order_id = o.id
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

    $totalOrders = (int) ($overview['total_orders'] ?? 0);
    $completedOrders = (int) ($overview['completed_orders'] ?? 0);


    return [
        'total_orders' => $totalOrders,
        'completed_orders' => $completedOrders,
        'pending_orders' => (int) ($overview['pending_orders'] ?? 0),
        'active_orders' => (int) ($overview['active_orders'] ?? 0),
        'cancelled_orders' => (int) ($overview['cancelled_orders'] ?? 0),
        'paid_orders' => (int) ($paymentOverview['paid_orders'] ?? 0),
        'pending_payments' => (int) ($paymentOverview['pending_payments'] ?? 0),
        'total_revenue' => (float) ($paymentOverview['total_revenue'] ?? 0),
        'avg_order_value' => (float) ($overview['avg_order_value'] ?? 0),
        'completion_rate' => $totalOrders > 0 ? ($completedOrders / $totalOrders) : 0.0,
        'average_turnaround_days' => ((float) ($overview['avg_turnaround_hours'] ?? 0)) / 24,
        'average_rating' => (float) ($ratingOverview['average_rating'] ?? 0),
        'rating_count' => (int) ($ratingOverview['rating_count'] ?? 0),
    ];
}

function fetch_order_status_breakdown(PDO $pdo, ?array $shopIds = null): array {
    $params = [];
    $shopClause = build_shop_filter_clause($shopIds, 'shop_id', $params);

    $stmt = $pdo->prepare("SELECT status, COUNT(*) AS total FROM orders WHERE 1=1 {$shopClause} GROUP BY status");
    $stmt->execute($params);

    $statusMap = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $statusMap[(string) $row['status']] = (int) $row['total'];
    }

    return $statusMap;
}

function fetch_monthly_earnings(PDO $pdo, ?array $shopIds = null, int $months = 6): array {
    $months = max(1, min(24, $months));
    $params = [];
    $shopClause = build_shop_filter_clause($shopIds, 'o.shop_id', $params);

    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(p.verified_at, '%Y-%m-01') AS month_start, COALESCE(SUM(p.amount), 0) AS total
        FROM payments p
        INNER JOIN orders o ON o.id = p.order_id
        WHERE p.status = 'verified'
          AND p.verified_at IS NOT NULL
          AND p.verified_at >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL ? MONTH)
          {$shopClause}
        GROUP BY DATE_FORMAT(p.verified_at, '%Y-%m-01')
        ORDER BY month_start ASC
    ");
    $stmt->execute(array_merge([$months - 1], $params));

    $raw = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $raw[(string) $row['month_start']] = (float) $row['total'];
    }

    $series = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $monthStart = date('Y-m-01', strtotime("-{$i} months"));
        $series[] = [
            'month_start' => $monthStart,
            'label' => date('M Y', strtotime($monthStart)),
            'earnings' => $raw[$monthStart] ?? 0.0,
        ];
    }

    return $series;
}

function fetch_quote_conversion_summary(PDO $pdo, ?array $shopIds = null): array {
    $params = [];
    $shopClause = build_shop_filter_clause($shopIds, 'shop_id', $params);

    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN quote_details IS NOT NULL THEN 1 ELSE 0 END), 0) AS quoted_orders,
            COALESCE(SUM(CASE WHEN quote_details IS NOT NULL AND status IN ('accepted', 'digitizing', 'production_pending', 'production', 'production_rework', 'qc_pending', 'ready_for_delivery', 'in_progress', 'completed') THEN 1 ELSE 0 END), 0) AS converted_orders
        FROM orders
        WHERE 1=1 {$shopClause}
    ");
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $quotedOrders = (int) ($row['quoted_orders'] ?? 0);
    $convertedOrders = (int) ($row['converted_orders'] ?? 0);

    return [
        // Data quality note: conversion depends on quote_details being consistently populated.
        'quoted_orders' => $quotedOrders,
        'converted_orders' => $convertedOrders,
        'conversion_rate' => $quotedOrders > 0 ? ($convertedOrders / $quotedOrders) : 0.0,
    ];
}

function fetch_qc_summary(PDO $pdo, ?array $shopIds = null): array {
    if (!table_exists($pdo, 'order_quality_checks')) {
        return ['pending' => 0, 'passed' => 0, 'failed' => 0];
    }

    $params = [];
    $shopClause = build_shop_filter_clause($shopIds, 'o.shop_id', $params);
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN oqc.qc_status = 'pending' THEN 1 ELSE 0 END), 0) AS pending,
            COALESCE(SUM(CASE WHEN oqc.qc_status = 'passed' THEN 1 ELSE 0 END), 0) AS passed,
            COALESCE(SUM(CASE WHEN oqc.qc_status = 'failed' THEN 1 ELSE 0 END), 0) AS failed
        FROM order_quality_checks oqc
        INNER JOIN orders o ON o.id = oqc.order_id
        WHERE 1=1 {$shopClause}
    ");
    $stmt->execute($params);

    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'pending' => (int) ($row['pending'] ?? 0),
        'passed' => (int) ($row['passed'] ?? 0),
        'failed' => (int) ($row['failed'] ?? 0),
    ];
}

function fetch_employee_productivity(PDO $pdo, ?array $shopIds = null, int $limit = 5): array {
    $params = [];
    $shopClause = build_shop_filter_clause($shopIds, 'o.shop_id', $params);

    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.fullname,
            COUNT(o.id) AS total_assigned,
            COALESCE(SUM(CASE WHEN o.status = 'completed' THEN 1 ELSE 0 END), 0) AS completed_orders,
            COALESCE(SUM(CASE WHEN o.status IN ('accepted', 'digitizing', 'production_pending', 'production', 'production_rework', 'qc_pending', 'ready_for_delivery', 'in_progress') THEN 1 ELSE 0 END), 0) AS active_orders,
            COALESCE(SUM(CASE WHEN o.status = 'cancelled' THEN 1 ELSE 0 END), 0) AS cancelled_orders,
            AVG(CASE WHEN o.status = 'completed' AND o.completed_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR, o.created_at, o.completed_at) END) AS avg_completion_hours
        FROM users u
        LEFT JOIN orders o ON o.assigned_to = u.id {$shopClause}
        WHERE u.role = 'staff'
        GROUP BY u.id, u.fullname
        ORDER BY completed_orders DESC, active_orders DESC
        LIMIT {$limit}
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetch_system_overview(PDO $pdo): array {
    $row = $pdo->query("
        SELECT
            (SELECT COUNT(*) FROM shops) AS total_shops,
            (SELECT COUNT(*) FROM shops WHERE status = 'active') AS active_shops,
            (SELECT COUNT(*) FROM users WHERE status = 'active') AS active_users,
            (SELECT COUNT(*) FROM orders) AS total_orders,
            (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.status = 'verified') AS total_revenue,
            (SELECT COUNT(*) FROM shops WHERE status = 'pending') + (SELECT COUNT(*) FROM users WHERE status = 'pending') AS pending_approvals
    ")->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'total_shops' => (int) ($row['total_shops'] ?? 0),
        'active_shops' => (int) ($row['active_shops'] ?? 0),
        'active_users' => (int) ($row['active_users'] ?? 0),
        'total_orders' => (int) ($row['total_orders'] ?? 0),
        'total_revenue' => (float) ($row['total_revenue'] ?? 0),
        'pending_approvals' => (int) ($row['pending_approvals'] ?? 0),
    ];
}

function fetch_system_activity_overview(PDO $pdo, int $days = 7): array {
    $days = max(1, min(30, $days));
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL ? DAY) THEN 1 ELSE 0 END), 0) AS orders_created,
            COALESCE(SUM(CASE WHEN status = 'completed' AND completed_at IS NOT NULL AND completed_at >= DATE_SUB(NOW(), INTERVAL ? DAY) THEN 1 ELSE 0 END), 0) AS orders_completed,
            COALESCE(SUM(CASE WHEN status = 'cancelled' AND cancelled_at IS NOT NULL AND cancelled_at >= DATE_SUB(NOW(), INTERVAL ? DAY) THEN 1 ELSE 0 END), 0) AS orders_cancelled
        FROM orders
    ");
    $stmt->execute([$days, $days, $days]);
    $orders = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $userStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)");
    $userStmt->execute([$days]);

    $shopStmt = $pdo->prepare("SELECT COUNT(*) FROM shops WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)");
    $shopStmt->execute([$days]);

    return [
        'days' => $days,
        'orders_created' => (int) ($orders['orders_created'] ?? 0),
        'orders_completed' => (int) ($orders['orders_completed'] ?? 0),
        'orders_cancelled' => (int) ($orders['orders_cancelled'] ?? 0),
        'new_users' => (int) $userStmt->fetchColumn(),
        'new_shops' => (int) $shopStmt->fetchColumn(),
    ];
}

function build_date_filter_clause(?string $startDate, ?string $endDate, string $column, array &$params): string {
    $clause = '';
    if ($startDate) {
        $clause .= " AND {$column} >= ?";
        $params[] = $startDate . ' 00:00:00';
    }
    if ($endDate) {
        $clause .= " AND {$column} <= ?";
        $params[] = $endDate . ' 23:59:59';
    }
    return $clause;
}

function fetch_sys_admin_reporting_metrics(PDO $pdo, ?string $startDate = null, ?string $endDate = null): array {
    $userParams = [];
    $userDateClause = build_date_filter_clause($startDate, $endDate, 'created_at', $userParams);
    $usersByRoleStmt = $pdo->prepare("SELECT role, COUNT(*) AS total FROM users WHERE 1=1 {$userDateClause} GROUP BY role");
    $usersByRoleStmt->execute($userParams);

    $usersByRole = [
        'sys_admin' => 0,
        'owner' => 0,
        'hr' => 0,
        'staff' => 0,
        'client' => 0,
    ];
    foreach ($usersByRoleStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $role = (string) ($row['role'] ?? '');
        if (array_key_exists($role, $usersByRole)) {
            $usersByRole[$role] = (int) ($row['total'] ?? 0);
        }
    }

    $approvalParams = [];
    $approvalDateClause = build_date_filter_clause($startDate, $endDate, 'created_at', $approvalParams);
    $approvalsStmt = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN role = 'owner' AND status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_owner_approvals,
            COALESCE(SUM(CASE WHEN role IN ('client', 'staff', 'hr') AND status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_member_approvals
        FROM users
        WHERE 1=1 {$approvalDateClause}");
    $approvalsStmt->execute($approvalParams);
    $approvalRow = $approvalsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $shopApprovalParams = [];
    $shopApprovalDateClause = build_date_filter_clause($startDate, $endDate, 'created_at', $shopApprovalParams);
    $pendingShopStmt = $pdo->prepare("SELECT COUNT(*) FROM shops WHERE status = 'pending' {$shopApprovalDateClause}");
    $pendingShopStmt->execute($shopApprovalParams);

    $statusMap = fetch_order_status_breakdown($pdo, null);
    $orderOverview = fetch_order_analytics($pdo, null);

    $orderDateParams = [];
    $orderDateClause = build_date_filter_clause($startDate, $endDate, 'created_at', $orderDateParams);
    if ($orderDateClause !== '') {
        $orderStatusStmt = $pdo->prepare("SELECT status, COUNT(*) AS total FROM orders WHERE 1=1 {$orderDateClause} GROUP BY status");
        $orderStatusStmt->execute($orderDateParams);
        $statusMap = [];
        foreach ($orderStatusStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $statusMap[(string) ($row['status'] ?? '')] = (int) ($row['total'] ?? 0);
        }

        $orderOverviewStmt = $pdo->prepare("SELECT
                COUNT(*) AS total_orders,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END), 0) AS completed_orders
            FROM orders
            WHERE 1=1 {$orderDateClause}");
        $orderOverviewStmt->execute($orderDateParams);
        $orderOverviewRow = $orderOverviewStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $orderOverview['total_orders'] = (int) ($orderOverviewRow['total_orders'] ?? 0);
        $orderOverview['completed_orders'] = (int) ($orderOverviewRow['completed_orders'] ?? 0);
    }

    $paymentParams = [];
    $paymentDateClause = build_date_filter_clause($startDate, $endDate, 'created_at', $paymentParams);
    $paymentStmt = $pdo->prepare("SELECT
            COALESCE(SUM(amount), 0) AS total_payment_amount,
            COALESCE(SUM(CASE WHEN status IN ('paid', 'partially_paid') THEN amount ELSE 0 END), 0) AS paid_payment_amount,
            COALESCE(SUM(CASE WHEN status = 'pending_verification' THEN amount ELSE 0 END), 0) AS pending_verification_amount,
            COALESCE(SUM(CASE WHEN status = 'paid' AND verified_at IS NOT NULL THEN amount ELSE 0 END), 0) AS verified_payment_amount,
            COALESCE(COUNT(CASE WHEN status = 'paid' AND verified_at IS NOT NULL THEN 1 END), 0) AS verified_payment_count
        FROM payments
        WHERE 1=1 {$paymentDateClause}");
    $paymentStmt->execute($paymentParams);
    $paymentOverview = $paymentStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $disputeCounts = ['total' => 0, 'pending' => 0, 'reviewing' => 0, 'resolved' => 0, 'dismissed' => 0];
    if (table_exists($pdo, 'content_reports')) {
        $disputeParams = [];
        $disputeDateClause = build_date_filter_clause($startDate, $endDate, 'created_at', $disputeParams);
        $disputeStmt = $pdo->prepare("SELECT
                COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending,
                COALESCE(SUM(CASE WHEN status = 'reviewing' THEN 1 ELSE 0 END), 0) AS reviewing,
                COALESCE(SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END), 0) AS resolved,
                COALESCE(SUM(CASE WHEN status = 'dismissed' THEN 1 ELSE 0 END), 0) AS dismissed
            FROM content_reports
            WHERE 1=1 {$disputeDateClause}");
        $disputeStmt->execute($disputeParams);
        $disputeCounts = array_merge($disputeCounts, $disputeStmt->fetch(PDO::FETCH_ASSOC) ?: []);
    }

    $supportCounts = ['total' => 0, 'pending' => 0, 'in_progress' => 0, 'completed' => 0, 'cancelled' => 0];
    if (table_exists($pdo, 'service_requests')) {
        $supportParams = [];
        $supportDateClause = build_date_filter_clause($startDate, $endDate, 'created_at', $supportParams);
        $supportStmt = $pdo->prepare("SELECT
                COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending,
                COALESCE(SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END), 0) AS in_progress,
                COALESCE(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END), 0) AS completed,
                COALESCE(SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END), 0) AS cancelled
            FROM service_requests
            WHERE 1=1 {$supportDateClause}");
        $supportStmt->execute($supportParams);
        $supportCounts = array_merge($supportCounts, $supportStmt->fetch(PDO::FETCH_ASSOC) ?: []);
    }

    $inventoryAlerts = ['low_stock_materials' => 0, 'reorder_alerts' => 0];
    if (table_exists($pdo, 'raw_materials')) {
        $materialParams = [];
        $materialDateClause = build_date_filter_clause($startDate, $endDate, 'created_at', $materialParams);
        $lowStockStmt = $pdo->prepare("SELECT COUNT(*)
            FROM raw_materials
            WHERE status = 'active'
              AND min_stock_level IS NOT NULL
              AND current_stock <= min_stock_level
              {$materialDateClause}");
        $lowStockStmt->execute($materialParams);
        $inventoryAlerts['low_stock_materials'] = (int) $lowStockStmt->fetchColumn();
    }

    if (table_exists($pdo, 'warehouse_stock_management')) {
        $warehouseParams = [];
        $warehouseDateClause = build_date_filter_clause($startDate, $endDate, 'created_at', $warehouseParams);
        $reorderStmt = $pdo->prepare("SELECT COUNT(*)
            FROM warehouse_stock_management
            WHERE opening_stock_qty <= reorder_level
              {$warehouseDateClause}");
        $reorderStmt->execute($warehouseParams);
        $inventoryAlerts['reorder_alerts'] = (int) $reorderStmt->fetchColumn();
    }

    $shopPerformanceParams = [];
    $shopOrderDateClause = build_date_filter_clause($startDate, $endDate, 'o.created_at', $shopPerformanceParams);
    $shopPerformanceStmt = $pdo->prepare("SELECT
            s.shop_name,
            COUNT(o.id) AS total_orders,
            COALESCE(SUM(CASE WHEN o.status = 'completed' THEN 1 ELSE 0 END), 0) AS completed_orders,
            COALESCE(SUM(CASE WHEN p.status = 'paid' AND p.verified_at IS NOT NULL THEN p.amount ELSE 0 END), 0) AS verified_revenue
        FROM shops s
        LEFT JOIN orders o ON o.shop_id = s.id {$shopOrderDateClause}
        LEFT JOIN payments p ON p.order_id = o.id
        GROUP BY s.id, s.shop_name
        ORDER BY verified_revenue DESC, completed_orders DESC
        LIMIT 5");
    $shopPerformanceStmt->execute($shopPerformanceParams);
    $topShops = [];
    foreach ($shopPerformanceStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $total = (int) ($row['total_orders'] ?? 0);
        $completed = (int) ($row['completed_orders'] ?? 0);
        $row['completion_rate'] = $total > 0 ? ($completed / $total) : 0;
        $topShops[] = $row;
    }

    return [
        'users_by_role' => $usersByRole,
        'pending_owner_approvals' => (int) ($approvalRow['pending_owner_approvals'] ?? 0),
        'pending_member_approvals' => (int) ($approvalRow['pending_member_approvals'] ?? 0),
        'pending_shop_approvals' => (int) $pendingShopStmt->fetchColumn(),
        'orders_by_status' => $statusMap,
        'total_orders' => (int) ($orderOverview['total_orders'] ?? 0),
        'total_completed_orders' => (int) ($orderOverview['completed_orders'] ?? 0),
        'payments' => [
            'total_payment_amount' => (float) ($paymentOverview['total_payment_amount'] ?? 0),
            'paid_payment_amount' => (float) ($paymentOverview['paid_payment_amount'] ?? 0),
            'pending_verification_amount' => (float) ($paymentOverview['pending_verification_amount'] ?? 0),
            'verified_payment_amount' => (float) ($paymentOverview['verified_payment_amount'] ?? 0),
            'verified_payment_count' => (int) ($paymentOverview['verified_payment_count'] ?? 0),
        ],
        'disputes' => [
            'total' => (int) ($disputeCounts['total'] ?? 0),
            'pending' => (int) ($disputeCounts['pending'] ?? 0),
            'reviewing' => (int) ($disputeCounts['reviewing'] ?? 0),
            'resolved' => (int) ($disputeCounts['resolved'] ?? 0),
            'dismissed' => (int) ($disputeCounts['dismissed'] ?? 0),
        ],
        'support_tickets' => [
            'total' => (int) ($supportCounts['total'] ?? 0),
            'pending' => (int) ($supportCounts['pending'] ?? 0),
            'in_progress' => (int) ($supportCounts['in_progress'] ?? 0),
            'completed' => (int) ($supportCounts['completed'] ?? 0),
            'cancelled' => (int) ($supportCounts['cancelled'] ?? 0),
        ],
        'inventory_alerts' => $inventoryAlerts,
        'top_shops' => $topShops,
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
