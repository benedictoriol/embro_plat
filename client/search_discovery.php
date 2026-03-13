<?php
session_start();
require_once '../config/db.php';
require_once '../includes/analytics_service.php';
require_role('client');

$client_id = $_SESSION['user']['id'];
$unread_notifications = fetch_unread_notification_count($pdo, $client_id);

$search_term = sanitize($_GET['search'] ?? '');
$hiring_filter = sanitize($_GET['hiring'] ?? '');
$sort_mode = sanitize($_GET['sort'] ?? 'recommended');

refresh_shop_metrics($pdo);

$shops_stmt = $pdo->query("
    SELECT *
    FROM shops
    WHERE status = 'active'
    ORDER BY rating DESC, total_orders DESC
");
$shops = $shops_stmt->fetchAll();

$capacity_map = [];
$capacity_stmt = $pdo->query("
    SELECT shop_id, COALESCE(SUM(max_active_orders), 0) AS total_capacity
    FROM shop_staffs
    WHERE status = 'active'
    GROUP BY shop_id
");
foreach ($capacity_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $capacity_map[(int) $row['shop_id']] = (int) $row['total_capacity'];
}

$workload_map = [];
$active_workload_statuses = order_workflow_active_statuses();
$workload_placeholders = order_workflow_status_placeholders($active_workload_statuses);
$workload_stmt = $pdo->prepare("
    SELECT shop_id, COUNT(*) AS active_orders
    FROM orders
    WHERE status IN ($workload_placeholders)
    GROUP BY shop_id
");
$workload_stmt->execute($active_workload_statuses);
foreach ($workload_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $workload_map[(int) $row['shop_id']] = (int) $row['active_orders'];
}

$reliability_map = [];
$reliability_stmt = $pdo->query("
    SELECT shop_id,
        COUNT(*) AS total_orders,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_orders
    FROM orders
    GROUP BY shop_id
");
foreach ($reliability_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $total_orders = (int) $row['total_orders'];
    $completed_orders = (int) $row['completed_orders'];
    $reliability_map[(int) $row['shop_id']] = [
        'total' => $total_orders,
        'completed' => $completed_orders,
        'rate' => $total_orders > 0 ? ($completed_orders / $total_orders) : 0,
    ];
}

$hiring_posts_map = [];
$hiring_stmt = $pdo->query("
    SELECT hp.*, s.shop_name
    FROM hiring_posts hp
    INNER JOIN shops s ON s.id = hp.shop_id
    WHERE hp.status = 'live'
      AND s.status = 'active'
      AND (hp.expires_at IS NULL OR hp.expires_at >= NOW())
    ORDER BY hp.created_at DESC
");
foreach ($hiring_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $shop_id = (int) $row['shop_id'];
    if (!isset($hiring_posts_map[$shop_id])) {
        $hiring_posts_map[$shop_id] = [];
    }
    $row['expiration_status'] = 'Open';
    if (!empty($row['expires_at'])) {
        $expires_ts = strtotime($row['expires_at']);
        $row['expiration_status'] = $expires_ts && $expires_ts >= time() ? 'Active' : 'Expired';
    }
    $hiring_posts_map[$shop_id][] = $row;
}


$shop_price_map = [];
$price_stmt = $pdo->query("
    SELECT shop_id, MIN(price) AS min_price, MAX(price) AS max_price
    FROM shop_portfolio
    WHERE price IS NOT NULL AND price > 0
    GROUP BY shop_id
");
foreach ($price_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $shop_price_map[(int) $row['shop_id']] = [
        'min' => (float) $row['min_price'],
        'max' => (float) $row['max_price'],
    ];
}

$shop_thumbnail_map = [];
$thumbnail_stmt = $pdo->query("\n    SELECT sp.shop_id, sp.image_path\n    FROM shop_portfolio sp\n    INNER JOIN (\n        SELECT shop_id, MAX(created_at) AS latest_created_at\n        FROM shop_portfolio\n        GROUP BY shop_id\n    ) latest ON latest.shop_id = sp.shop_id AND latest.latest_created_at = sp.created_at\n");
foreach ($thumbnail_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $shop_thumbnail_map[(int) $row['shop_id']] = $row['image_path'];
}

$shops = array_map(function(array $shop) use ($capacity_map, $workload_map, $reliability_map, $hiring_posts_map, $shop_thumbnail_map, $shop_price_map) {
    $shop_id = (int) $shop['id'];
    $shop['capacity'] = $capacity_map[$shop_id] ?? 0;
    $shop['active_orders'] = $workload_map[$shop_id] ?? 0;
    $shop['reliability'] = $reliability_map[$shop_id] ?? ['total' => 0, 'completed' => 0, 'rate' => 0];
    $shop['hiring_posts'] = $hiring_posts_map[$shop_id] ?? [];
    $shop['thumbnail'] = $shop_thumbnail_map[$shop_id] ?? '';
    $shop['price_range'] = $shop_price_map[$shop_id] ?? null;
    return $shop;
}, $shops);

$shops = compute_dss_ranked_shops($pdo, $shops, $search_term);

$shops = array_values(array_filter($shops, function(array $shop) use ($search_term, $hiring_filter) {
    if ($search_term !== '') {
        $haystack = mb_strtolower(trim(($shop['shop_name'] ?? '') . ' ' . ($shop['shop_description'] ?? '') . ' ' . ($shop['address'] ?? '')));
        if (mb_strpos($haystack, mb_strtolower($search_term)) === false) {
            return false;
        }
    }
    if ($hiring_filter === '1' && empty($shop['hiring_posts'])) {
        return false;
    }
    return true;
}));

if ($sort_mode === 'recommended') {
    usort($shops, function(array $a, array $b) {
        if ($b['dss_score'] === $a['dss_score']) {
            return $b['rating'] <=> $a['rating'];
        }
        return $b['dss_score'] <=> $a['dss_score'];
    });
} elseif ($sort_mode === 'rating') {
    usort($shops, fn(array $a, array $b) => $b['rating'] <=> $a['rating']);
} else {
    usort($shops, fn(array $a, array $b) => $b['total_orders'] <=> $a['total_orders']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search & Discovery Module - Client</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .discovery-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .overview-card,
        .search-card,
        .results-card {
            grid-column: span 12;
        }

        .search-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .shop-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 0.9rem;
        }

        .shop-card {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 0.9rem;
            background: var(--bg-primary);
            display: flex;
            flex-direction: column;
            gap: 0.6rem;
        }

        .shop-thumb {
            width: 100%;
            height: 140px;
            border-radius: var(--radius-sm);
            object-fit: cover;
            border: 1px solid var(--gray-200);
            background: var(--gray-100);
        }

        .shop-thumb-fallback {
            height: 140px;
            border-radius: var(--radius-sm);
            border: 1px dashed var(--gray-300);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gray-400);
        }

        .shop-card h4 {
            margin: 0;
        }

        .shop-meta {
            display: grid;
            gap: 0.35rem;
            font-size: 0.86rem;
        }

        .shop-extra details {
            border-top: 1px dashed var(--gray-200);
            padding-top: 0.45rem;
        }

        .shop-extra summary {
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--primary-700);
        }

        .shop-meta span {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .dss-badge {
            background: var(--primary-100);
            color: var(--primary-700);
            border-radius: 999px;
            padding: 0.35rem 0.75rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }

        .hiring-list {
            border-top: 1px dashed var(--gray-200);
            padding-top: 0.75rem;
            display: grid;
            gap: 0.75rem;
        }

        .shop-summary {
            font-size: 0.88rem;
            line-height: 1.4;
        }

        .hiring-post {
            background: var(--gray-50);
            border-radius: var(--radius);
            padding: 0.75rem;
        }

        .hiring-post h5 {
            margin: 0 0 0.35rem;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.2rem 0.6rem;
            border-radius: 999px;
            background: var(--success-100);
            color: var(--success-700);
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .pill--warning {
            background: #fef3c7;
            color: #92400e;
        }

        .pill--danger {
            background: #fee2e2;
            color: #991b1b;
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/includes/customer_navbar.php'; ?>

    <div class="container">
        <div class="dashboard-header fade-in">
            <div class="d-flex justify-between align-center">
                <div>
                    <h2>Search, Discovery & Hiring Visibility</h2>
                    <p class="text-muted">Find the right shop, check availability, and explore hiring opportunities.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-compass"></i> Module 6</span>
            </div>
        </div>

        <div class="discovery-grid">
            <div class="card overview-card">
                <div class="card-header">
                    <h3><i class="fas fa-bullseye text-primary"></i> Shop Discovery (Live)</h3>
                </div>
                <p class="text-muted mb-0">
                    Browse active embroidery shops, compare Decision Support System (DSS) scores, and review live hiring posts
                    before reaching out or placing an order.
                </p>
            </div>

            <div class="card search-card">
                <div class="card-header">
                    <h3><i class="fas fa-filter text-primary"></i> Filter Results</h3>
                </div>
                <form class="search-form" method="get" action="search_discovery.php">
                    <label class="form-group">
                        <span class="form-label">Search shop or location</span>
                        <input type="text" name="search" class="form-control" value="<?php echo htmlspecialchars($search_term); ?>" placeholder="e.g. Market Street or Thread & Needle">
                    </label>
                    <label class="form-group">
                        <span class="form-label">Hiring filter</span>
                        <select name="hiring" class="form-control">
                            <option value="">All shops</option>
                            <option value="1" <?php echo $hiring_filter === '1' ? 'selected' : ''; ?>>Only shops hiring</option>
                        </select>
                    </label>
                    <label class="form-group">
                        <span class="form-label">Sort by</span>
                        <select name="sort" class="form-control">
                            <option value="recommended" <?php echo $sort_mode === 'recommended' ? 'selected' : ''; ?>>Recommended (DSS)</option>
                            <option value="rating" <?php echo $sort_mode === 'rating' ? 'selected' : ''; ?>>Rating</option>
                            <option value="orders" <?php echo $sort_mode === 'orders' ? 'selected' : ''; ?>>Total Orders</option>
                        </select>
                    </label>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Apply</button>
                        <a href="search_discovery.php" class="btn btn-outline">Reset</a>
                    </div>
                </form>
            </div>

            <div class="card results-card">
                <div class="card-header">
                    <h3><i class="fas fa-store text-primary"></i> Ranked Shops</h3>
                    <p class="text-muted">Recommended uses weighted DSS score with fallback ranking when metrics are incomplete.</p>
                </div>
                <?php if (empty($shops)): ?>
                    <p class="text-muted mb-0">No shops matched your filters. Try adjusting the search or hiring filter.</p>
                <?php else: ?>
                    <div class="shop-grid">
                        <?php foreach ($shops as $shop): ?>
                            <div class="shop-card">
                                <div class="d-flex justify-between align-center">
                                      <h4>
                                        <a href="shop_details.php?shop_id=<?php echo (int) $shop['id']; ?>">
                                            <?php echo htmlspecialchars($shop['shop_name']); ?>
                                        </a>
                                    </h4>
                                    <span class="dss-badge"><i class="fas fa-star"></i> <?php echo number_format((float) $shop['rating'], 1); ?></span>
                                </div>
                                <?php if (!empty($shop['thumbnail'])): ?>
                                    <img class="shop-thumb" src="../assets/uploads/<?php echo htmlspecialchars($shop['thumbnail']); ?>" alt="<?php echo htmlspecialchars($shop['shop_name']); ?> thumbnail">
                                <?php else: ?>
                                    <div class="shop-thumb-fallback"><i class="fas fa-image"></i></div>
                                <?php endif; ?>
                                <p class="text-muted mb-0 shop-summary"><?php echo htmlspecialchars(mb_strimwidth((string) ($shop['shop_description'] ?? 'No shop description yet.'), 0, 95, '…')); ?></p>
                                <div class="shop-meta text-muted">
                                    <span><i class="fas fa-star text-warning"></i> Rating: <?php echo number_format((float) $shop['rating'], 1); ?> (<?php echo (int) $shop['rating_count']; ?>)</span>
                                    <span><i class="fas fa-peso-sign text-success"></i> Price: <?php
                                        if (!empty($shop['price_range'])) {
                                            echo '₱' . number_format((float) $shop['price_range']['min'], 2);
                                            if ((float) $shop['price_range']['max'] > (float) $shop['price_range']['min']) {
                                                echo ' - ₱' . number_format((float) $shop['price_range']['max'], 2);
                                            }
                                        } else {
                                            echo 'Contact shop';
                                        }
                                    ?></span>
                                    <?php if (!empty($shop['avg_turnaround_days'])): ?>
                                        <span><i class="fas fa-clock text-info"></i> Avg turnaround: <?php echo number_format((float) $shop['avg_turnaround_days'], 1); ?> day(s)</span>
                                    <?php endif; ?>
                                    </div>
                                <div class="shop-extra">
                                    <details>
                                        <summary>Details</summary>
                                        <div class="shop-meta text-muted mt-2">
                                            <span><i class="fas fa-map-marker-alt text-primary"></i> <?php echo htmlspecialchars($shop['address'] ?? 'Address not set'); ?></span>
                                            <span><i class="fas fa-chart-line text-primary"></i> DSS Score: <?php echo number_format((float) $shop['dss_score'], 2); ?></span>
                                            <span><i class="fas fa-boxes-stacked text-info"></i> Capacity: <?php echo (int) $shop['active_orders']; ?>/<?php echo (int) $shop['capacity']; ?> active orders</span>
                                            <span><i class="fas fa-chart-pie text-primary"></i> DSS Breakdown:
                                                Rating <?php echo number_format((float) ($shop['dss_breakdown']['rating'] ?? 0) * 100, 1); ?>% ·
                                                Reviews <?php echo (int) ($shop['dss_breakdown']['review_count'] ?? 0); ?> (<?php echo number_format((float) ($shop['dss_breakdown']['review_norm'] ?? 0) * 100, 1); ?>%) ·
                                                Completion <?php echo number_format((float) ($shop['dss_breakdown']['completion'] ?? 0) * 100, 1); ?>% ·
                                                Turnaround <?php echo number_format((float) ($shop['dss_breakdown']['turnaround'] ?? 0) * 100, 1); ?>% ·
                                                Price <?php echo number_format((float) ($shop['dss_breakdown']['price'] ?? 0) * 100, 1); ?>% ·
                                                Cancellation <?php echo number_format((float) ($shop['dss_breakdown']['cancellation'] ?? 0) * 100, 1); ?>% ·
                                                Availability <?php echo number_format((float) ($shop['dss_breakdown']['availability'] ?? 0) * 100, 1); ?>%
                                            </span>
                                            <?php if (!empty($shop['dss_is_fallback'])): ?>
                                                <span><i class="fas fa-triangle-exclamation text-warning"></i> Fallback score used (incomplete metrics)</span>
                                            <?php endif; ?>
                                            <?php if (!empty($shop['hiring_posts'])): ?>
                                                <div class="hiring-list">
                                                    <span class="pill"><i class="fas fa-briefcase"></i> <?php echo count($shop['hiring_posts']); ?> hiring post(s)</span>
                                                    <?php foreach ($shop['hiring_posts'] as $post): ?>
                                                        <div class="hiring-post">
                                                            <h5><?php echo htmlspecialchars($post['title']); ?></h5>
                                                            <p class="text-muted mb-1"><?php echo htmlspecialchars(mb_strimwidth((string) ($post['description'] ?? ''), 0, 90, '…')); ?></p>
                                                            <small class="text-muted">Posted <?php echo date('M d, Y', strtotime($post['created_at'])); ?></small>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </details>
                                </div>
                                <div class="d-flex gap-2">
                                     <a href="shop_details.php?shop_id=<?php echo (int) $shop['id']; ?>" class="btn btn-outline btn-sm">
                                        <i class="fas fa-store"></i> View Shop
                                    </a>
                                    <a href="client_posting_community.php" class="btn btn-outline btn-sm"><i class="fas fa-comments"></i> Ask a Question</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
