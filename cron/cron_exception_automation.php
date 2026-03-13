<?php
// Cron entrypoint for exception automation.
// Example: */15 * * * * /usr/bin/php /path/to/embro_plat/cron/cron_exception_automation.php
require_once __DIR__ . '/../config/db.php';

$startedAt = microtime(true);
$summary = run_exception_automation($pdo, false);
$durationMs = (int) round((microtime(true) - $startedAt) * 1000);

if(function_exists('write_dss_log')) {
    write_dss_log($pdo, 'cron_exception_automation', null, [
        'summary' => $summary,
        'duration_ms' => $durationMs,
        'trigger' => 'cron',
    ]);
}

echo sprintf(
    "[%s] Exception automation complete. opened=%d escalated=%d resolved=%d notifications=%d stale=%d payment=%d production=%d support=%d dispute=%d materials=%d duration_ms=%d\n",
    date('Y-m-d H:i:s'),
    (int) ($summary['exceptions_opened'] ?? 0),
    (int) ($summary['exceptions_escalated'] ?? 0),
    (int) ($summary['exceptions_resolved'] ?? 0),
    (int) ($summary['notifications_created'] ?? 0),
    (int) ($summary['stale_quotation_candidates'] ?? 0),
    (int) ($summary['overdue_payment_candidates'] ?? 0),
    (int) ($summary['delayed_production_candidates'] ?? 0),
    (int) ($summary['support_sla_candidates'] ?? 0),
    (int) ($summary['dispute_sla_candidates'] ?? 0),
    (int) ($summary['material_shortage_candidates'] ?? 0),
    $durationMs
);
