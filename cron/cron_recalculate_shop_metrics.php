<?php
// Cron entrypoint for DSS recalculation.
// Example: */30 * * * * /usr/bin/php /path/to/embro_plat/cron/cron_recalculate_shop_metrics.php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/analytics_service.php';

$startedAt = microtime(true);
refresh_shop_metrics($pdo);

$shopsCountStmt = $pdo->query("SELECT COUNT(*) FROM shop_metrics");
$shopsCount = (int) $shopsCountStmt->fetchColumn();
$durationMs = (int) round((microtime(true) - $startedAt) * 1000);

write_dss_log($pdo, 'cron_recalculated_shop_metrics', null, [
    'shops_processed' => $shopsCount,
    'duration_ms' => $durationMs,
    'trigger' => 'cron',
]);

echo sprintf("[%s] DSS metrics recalculated. shops=%d duration_ms=%d\n", date('Y-m-d H:i:s'), $shopsCount, $durationMs);
