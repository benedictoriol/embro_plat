<?php
// Cron entrypoint for notification reminder automation.
// Example: */30 * * * * /usr/bin/php /path/to/embro_plat/cron/cron_notification_reminders.php
require_once __DIR__ . '/../config/db.php';

$startedAt = microtime(true);
$summary = run_notification_reminders($pdo, false);
$durationMs = (int) round((microtime(true) - $startedAt) * 1000);

if(function_exists('write_dss_log')) {
    write_dss_log($pdo, 'cron_notification_reminders', null, [
        'summary' => $summary,
        'duration_ms' => $durationMs,
        'trigger' => 'cron',
    ]);
}

echo sprintf(
    "[%s] Notification reminders complete. created=%d stale_quotes=%d unpaid=%d overdue_production=%d qc_pending=%d pickup=%d delivery_followups=%d support_sla=%d dispute_sla=%d low_stock=%d overdue_orders=%d metrics_recalculated=%d duration_ms=%d\n",
    date('Y-m-d H:i:s'),
    (int) ($summary['notifications_created'] ?? 0),
    (int) ($summary['stale_pending_quotes'] ?? 0),
    (int) ($summary['unpaid_orders'] ?? 0),
    (int) ($summary['overdue_production'] ?? 0),
    (int) ($summary['qc_pending_alerts'] ?? 0),
    (int) ($summary['ready_for_pickup_unclaimed'] ?? 0),
    (int) ($summary['delivery_followups'] ?? 0),
    (int) ($summary['unresolved_support_tickets'] ?? 0),
    (int) ($summary['dispute_sla_alerts'] ?? 0),
    (int) ($summary['low_stock_alerts'] ?? 0),
    (int) ($summary['overdue_orders'] ?? 0),
    (int) ($summary['metrics_recalculated'] ?? 0),
    $durationMs
);
