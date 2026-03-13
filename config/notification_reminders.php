<?php

require_once __DIR__ . '/notification_functions.php';
require_once __DIR__ . '/system_settings.php';
require_once __DIR__ . '/messaging_helpers.php';
require_once __DIR__ . '/assignment_helpers.php';
require_once __DIR__ . '/../includes/analytics_service.php';

function reminder_schema_supports(PDO $pdo, string $table, array $columns = []): bool {
    try {
        $tableExists = function_exists('table_exists') ? table_exists($pdo, $table) : true;
        if(!$tableExists) {
            return false;
        }

        foreach($columns as $column) {
            $columnExists = function_exists('column_exists') ? column_exists($pdo, $table, $column) : true;
            if(!$columnExists) {
                return false;
            }
        }
    } catch(Throwable $e) {
        return false;
    }

    return true;
}

function ensure_automation_reminder_markers_table(PDO $pdo): void {
    // Schema is managed by migrations.
}

function automation_should_dispatch_reminder(PDO $pdo, string $reminderType, string $entityKey, int $cooldownHours, ?int $orderId = null, bool $dryRun = false): bool {
    $cooldownHours = max(1, $cooldownHours);

    $hasMarkers = reminder_schema_supports($pdo, 'automation_reminder_markers', ['reminder_type', 'entity_key', 'reminded_at']);
    if(!$hasMarkers) {
        // Fallback for schema-drift environments: do not dedupe, but keep automation running.
        return true;
    }

    $stmt = $pdo->prepare("\n        SELECT reminded_at\n        FROM automation_reminder_markers\n        WHERE reminder_type = ? AND entity_key = ?\n        LIMIT 1\n    ");
    $stmt->execute([$reminderType, $entityKey]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if($existing && !empty($existing['reminded_at'])) {
        $lastRun = strtotime((string) $existing['reminded_at']) ?: 0;
        if($lastRun > strtotime('-' . $cooldownHours . ' hours')) {
            return false;
        }
    }

    if(!$dryRun) {
        $upsert = $pdo->prepare("\n            INSERT INTO automation_reminder_markers (reminder_type, entity_key, context_order_id, reminded_at)\n            VALUES (?, ?, ?, NOW())\n            ON DUPLICATE KEY UPDATE context_order_id = VALUES(context_order_id), reminded_at = VALUES(reminded_at)\n        ");
        $upsert->execute([$reminderType, $entityKey, $orderId]);
    }

    return true;
}

function automation_log_reminder_action(PDO $pdo, string $action, array $payload): void {
    if(function_exists('write_dss_log')) {
        write_dss_log($pdo, 'automation_' . $action, null, $payload);
    }
}

function notification_reminder_settings(PDO $pdo): array {
    return [
        'stale_quote_hours' => max(1, (int) system_setting_get($pdo, 'notification', 'stale_quote_hours', 24)),
        'unpaid_order_hours' => max(1, (int) system_setting_get($pdo, 'notification', 'unpaid_order_hours', 24)),
        'overdue_production_hours' => max(1, (int) system_setting_get($pdo, 'notification', 'overdue_production_hours', 12)),
        'ready_pickup_hours' => max(1, (int) system_setting_get($pdo, 'notification', 'ready_pickup_hours', 24)),
        'overdue_order_hours' => max(1, (int) system_setting_get($pdo, 'notification', 'overdue_order_hours', 24)),
        'qc_pending_hours' => max(1, (int) system_setting_get($pdo, 'notification', 'qc_pending_hours', 8)),
        'delivery_followup_hours' => max(1, (int) system_setting_get($pdo, 'notification', 'delivery_followup_hours', 12)),
        'support_sla_hours' => max(1, (int) system_setting_get($pdo, 'notification', 'support_sla_hours', 24)),
        'dispute_sla_hours' => max(1, (int) system_setting_get($pdo, 'notification', 'dispute_sla_hours', 48)),
        'pending_owner_review_hours' => max(1, (int) system_setting_get($pdo, 'notification', 'pending_owner_review_hours', 12)),
        'owner_profile_incomplete_hours' => max(1, (int) system_setting_get($pdo, 'notification', 'owner_profile_incomplete_hours', 24)),
        'untouched_quote_request_hours' => max(1, (int) system_setting_get($pdo, 'notification', 'untouched_quote_request_hours', 24)),
        'design_approval_pending_hours' => max(1, (int) system_setting_get($pdo, 'notification', 'design_approval_pending_hours', 24)),
        'payment_verification_pending_hours' => max(1, (int) system_setting_get($pdo, 'notification', 'payment_verification_pending_hours', 12)),
        'exception_escalation_hours' => max(1, (int) system_setting_get($pdo, 'notification', 'overdue_exception_hours', 12)),
        'low_stock_threshold' => max(1, (float) system_setting_get($pdo, 'notification', 'low_stock_threshold', 10)),
        'auto_recalculate_metrics' => (bool) filter_var(system_setting_get($pdo, 'notification', 'auto_recalculate_metrics', '1'), FILTER_VALIDATE_BOOLEAN),
        'daily_summary_hour' => max(0, min(23, (int) system_setting_get($pdo, 'notification', 'daily_summary_hour', 6))),
        'weekly_summary_weekday' => max(1, min(7, (int) system_setting_get($pdo, 'notification', 'weekly_summary_weekday', 1))),
        'reminder_cooldown_hours' => max(1, (int) system_setting_get($pdo, 'notification', 'reminder_cooldown_hours', 24)),
    ];
}

function run_notification_reminders(PDO $pdo, bool $dryRun = false): array {
    $settings = notification_reminder_settings($pdo);
    $summary = [
        'settings' => $settings,
        'stale_pending_quotes' => 0,
        'unpaid_orders' => 0,
        'overdue_production' => 0,
        'ready_for_pickup_unclaimed' => 0,
        'overdue_orders' => 0,
        'notifications_created' => 0,
        'unresolved_support_tickets' => 0,
        'inventory_shortages' => 0,
        'qc_pending_alerts' => 0,
        'delivery_followups' => 0,
        'low_stock_alerts' => 0,
        'assignment_suggestions' => 0,
        'dispute_sla_alerts' => 0,
        'pending_owner_applications' => 0,
        'owner_profiles_incomplete' => 0,
        'untouched_quote_requests' => 0,
        'design_approvals_pending' => 0,
        'pending_payment_verifications' => 0,
        'production_delay_exceptions' => 0,
        'support_sla_escalations' => 0,
        'daily_summary_hooks' => 0,
        'weekly_summary_hooks' => 0,
        'metrics_recalculated' => 0,
    ];

    $ownerReviewStmt = $pdo->prepare("\n        SELECT u.id, u.fullname, u.email\n        FROM users u\n        JOIN shops s ON s.owner_id = u.id\n        WHERE u.role = 'owner'\n          AND u.status = 'pending'\n          AND s.status = 'pending'\n          AND u.created_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)\n    ");
    $ownerReviewStmt->execute([$settings['pending_owner_review_hours']]);
    foreach($ownerReviewStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ownerId = (int) ($row['id'] ?? 0);
        if($ownerId <= 0) {
            continue;
        }
        $summary['pending_owner_applications']++;
        if(!automation_should_dispatch_reminder($pdo, 'owner_review_pending', 'owner:' . $ownerId, $settings['reminder_cooldown_hours'], null, $dryRun)) {
            continue;
        }

        if(!$dryRun) {
            $adminMessage = sprintf(
                'Owner application pending review: %s (%s).',
                (string) ($row['fullname'] ?? 'Owner'),
                (string) ($row['email'] ?? 'no-email')
            );
            notify_sys_admins_owner_review_needed($pdo, $adminMessage, $settings['reminder_cooldown_hours']);
            automation_log_reminder_action($pdo, 'owner_review_pending', ['owner_id' => $ownerId]);
        }
    }

    $ownerIncompleteSql = "
        SELECT u.id, s.id AS shop_id
        FROM users u
        JOIN shops s ON s.owner_id = u.id
        WHERE u.role = 'owner'
          AND u.status IN ('pending','active')
          AND s.status = 'pending'";
    if(reminder_schema_supports($pdo, 'shops', ['profile_completed_at'])) {
        $ownerIncompleteSql .= "
          AND s.profile_completed_at IS NULL";
    }
    $ownerIncompleteSql .= "
          AND u.created_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)
    ";
    $ownerIncompleteStmt = $pdo->prepare($ownerIncompleteSql);
    $ownerIncompleteStmt->execute([$settings['owner_profile_incomplete_hours']]);
    foreach($ownerIncompleteStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ownerId = (int) ($row['id'] ?? 0);
        if($ownerId <= 0) {
            continue;
        }
        $summary['owner_profiles_incomplete']++;
        if(!automation_should_dispatch_reminder($pdo, 'owner_profile_incomplete', 'owner:' . $ownerId, $settings['reminder_cooldown_hours'], null, $dryRun)) {
            continue;
        }

        if(!$dryRun) {
            $message = 'Reminder: complete your shop profile submission so admin approval can proceed.';
            if(!has_recent_notification_by_type_and_message($pdo, $ownerId, 'account', $message, $settings['reminder_cooldown_hours'])) {
                create_notification($pdo, $ownerId, null, 'account', $message);
                $summary['notifications_created']++;
            }
            automation_log_reminder_action($pdo, 'owner_profile_incomplete', ['owner_id' => $ownerId]);
        }
    }

    $ownerDecisionStmt = $pdo->query("\n        SELECT u.id, s.status AS shop_status, s.rejection_reason\n        FROM users u\n        JOIN shops s ON s.owner_id = u.id\n        WHERE u.role = 'owner'\n          AND (s.status = 'active' OR s.status = 'rejected')\n    ");
    foreach($ownerDecisionStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ownerId = (int) ($row['id'] ?? 0);
        $shopStatus = strtolower((string) ($row['shop_status'] ?? ''));
        if($ownerId <= 0 || $shopStatus === '') {
            continue;
        }
        if(!automation_should_dispatch_reminder($pdo, 'owner_decision_notice', 'owner:' . $ownerId . ':' . $shopStatus, 720, null, $dryRun)) {
            continue;
        }
        if(!$dryRun) {
            notify_owner_approval_decision($pdo, $ownerId, $shopStatus === 'active', $shopStatus === 'rejected' ? (string) ($row['rejection_reason'] ?? '') : null);
            automation_log_reminder_action($pdo, 'owner_decision_notice', ['owner_id' => $ownerId, 'status' => $shopStatus]);
        }
    }

    $staleQuoteStmt = $pdo->prepare("\n        SELECT o.id, o.order_number, o.client_id, s.owner_id\n        FROM orders o\n        JOIN shops s ON s.id = o.shop_id\n        WHERE o.status = 'pending'\n          AND o.quote_status = 'sent'\n          AND o.updated_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)\n    ");
    $staleQuoteStmt->execute([$settings['stale_quote_hours']]);
    foreach($staleQuoteStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $summary['stale_pending_quotes']++;
        if(!automation_should_dispatch_reminder($pdo, 'stale_quote', 'order:' . (int) $row['id'], $settings['reminder_cooldown_hours'], (int) $row['id'], $dryRun)) {
            continue;
        }
        $orderId = (int) $row['id'];
        $orderNumber = (string) ($row['order_number'] ?? $orderId);
        $clientMsg = sprintf('Reminder: quote for order #%s is still awaiting your approval.', $orderNumber);
        $ownerMsg = sprintf('Reminder: quote for order #%s is pending client approval.', $orderNumber);

        if(!$dryRun && (int) $row['client_id'] > 0 && !has_recent_notification_for_order_message($pdo, (int) $row['client_id'], $orderId, 'order_status', $clientMsg, $settings['reminder_cooldown_hours'] * 60)) {
            create_notification($pdo, (int) $row['client_id'], $orderId, 'order_status', $clientMsg);
            $summary['notifications_created']++;
        }
        if(!$dryRun && (int) $row['owner_id'] > 0 && !has_recent_notification_for_order_message($pdo, (int) $row['owner_id'], $orderId, 'order_status', $ownerMsg, $settings['reminder_cooldown_hours'] * 60)) {
            create_notification($pdo, (int) $row['owner_id'], $orderId, 'order_status', $ownerMsg);
            $summary['notifications_created']++;
        }
        if(!$dryRun) {
            messaging_auto_thread_on_client_owner_discussion($pdo, $orderId, 0, 'Quote pending approval beyond SLA threshold.');
            automation_log_reminder_action($pdo, 'stale_quote', ['order_id' => $orderId]);
        }
    }

    $untouchedQuoteStmt = $pdo->prepare("\n        SELECT o.id, o.order_number, o.shop_id, s.owner_id\n        FROM orders o\n        JOIN shops s ON s.id = o.shop_id\n        WHERE o.status = 'pending'\n          AND o.quote_status IN ('requested','draft','issued')\n          AND o.updated_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)\n    ");
    $untouchedQuoteStmt->execute([$settings['untouched_quote_request_hours']]);
    foreach($untouchedQuoteStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $orderId = (int) ($row['id'] ?? 0);
        $ownerId = (int) ($row['owner_id'] ?? 0);
        if($orderId <= 0 || $ownerId <= 0) {
            continue;
        }
        $summary['untouched_quote_requests']++;
        if(!automation_should_dispatch_reminder($pdo, 'untouched_quote_request', 'order:' . $orderId, $settings['reminder_cooldown_hours'], $orderId, $dryRun)) {
            continue;
        }

        $message = sprintf('Quote request for order #%s has not been actioned within SLA.', (string) ($row['order_number'] ?? $orderId));
        if(!$dryRun) {
            create_notification_recent_once_for_order($pdo, $ownerId, $orderId, 'warning', $message, $settings['reminder_cooldown_hours'] * 60);
            $summary['notifications_created']++;
            foreach(fetch_shop_hr_user_ids($pdo, (int) ($row['shop_id'] ?? 0)) as $hrId) {
                create_notification_recent_once_for_order($pdo, (int) $hrId, $orderId, 'warning', $message, $settings['reminder_cooldown_hours'] * 60);
                $summary['notifications_created']++;
            }
            if(function_exists('order_exception_open')) {
                order_exception_open($pdo, $orderId, 'quote_expired', 'medium', 'Quote request untouched past SLA threshold.', $ownerId, 0, 'system');
                $summary['support_sla_escalations']++;
            }
            automation_log_reminder_action($pdo, 'untouched_quote_request', ['order_id' => $orderId]);
        }
    }

    $unpaidStmt = $pdo->prepare("\n        SELECT o.id, o.order_number, o.client_id, s.owner_id\n        FROM orders o\n        JOIN shops s ON s.id = o.shop_id\n        WHERE o.status IN ('accepted','digitizing','production_pending','production','production_rework','qc_pending')\n          AND o.payment_status IN ('unpaid', 'failed')\n          AND o.updated_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)\n    ");
    $unpaidStmt->execute([$settings['unpaid_order_hours']]);
    foreach($unpaidStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $summary['unpaid_orders']++;
        if(!automation_should_dispatch_reminder($pdo, 'unpaid_payment', 'order:' . (int) $row['id'], $settings['reminder_cooldown_hours'], (int) $row['id'], $dryRun)) {
            continue;
        }
        $orderId = (int) $row['id'];
        $orderNumber = (string) ($row['order_number'] ?? $orderId);
        $clientMsg = sprintf('Payment reminder: order #%s still requires payment to continue processing.', $orderNumber);
        $ownerMsg = sprintf('Order #%s is awaiting required client payment.', $orderNumber);

        if(!$dryRun && (int) $row['client_id'] > 0 && !has_recent_notification_for_order_message($pdo, (int) $row['client_id'], $orderId, 'payment', $clientMsg, $settings['reminder_cooldown_hours'] * 60)) {
            create_notification($pdo, (int) $row['client_id'], $orderId, 'payment', $clientMsg);
            $summary['notifications_created']++;
        }
        if(!$dryRun && (int) $row['owner_id'] > 0 && !has_recent_notification_for_order_message($pdo, (int) $row['owner_id'], $orderId, 'payment', $ownerMsg, $settings['reminder_cooldown_hours'] * 60)) {
            create_notification($pdo, (int) $row['owner_id'], $orderId, 'payment', $ownerMsg);
            $summary['notifications_created']++;
        }
        if(!$dryRun) {
            messaging_auto_thread_on_client_owner_discussion($pdo, $orderId, 0, 'Payment follow-up required to unblock processing.');
            automation_log_reminder_action($pdo, 'unpaid_payment', ['order_id' => $orderId]);
        }
    }

    $overdueProductionStmt = $pdo->prepare("\n        SELECT o.id, o.order_number, o.client_id, o.assigned_to, s.owner_id\n        FROM orders o\n        JOIN shops s ON s.id = o.shop_id\n        WHERE o.status IN ('production','production_rework','qc_pending')\n          AND o.estimated_completion IS NOT NULL\n          AND o.estimated_completion <= DATE_SUB(NOW(), INTERVAL ? HOUR)\n    ");
    $overdueProductionStmt->execute([$settings['overdue_production_hours']]);
    foreach($overdueProductionStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $summary['overdue_production']++;
        $orderId = (int) $row['id'];
        if(!automation_should_dispatch_reminder($pdo, 'overdue_production', 'order:' . $orderId, $settings['reminder_cooldown_hours'], $orderId, $dryRun)) {
            continue;
        }
        notify_business_event($pdo, 'overdue_order', $orderId, [
            'actor_id' => 0,
            'lookback_hours' => $settings['reminder_cooldown_hours'],
            'message' => sprintf('Order #%s is overdue in production and requires attention.', (string) ($row['order_number'] ?? $orderId)),
        ]);
        if(!$dryRun && (int) ($row['assigned_to'] ?? 0) <= 0) {
            $suggested = choose_best_staff_assignee($pdo, $orderId);
            if($suggested && (int) ($row['owner_id'] ?? 0) > 0) {
                create_notification_recent_once_for_order(
                    $pdo,
                    (int) $row['owner_id'],
                    $orderId,
                    'order_status',
                    'Assignment suggestion for overdue order #' . ($row['order_number'] ?? $orderId) . ': ' . ($suggested['fullname'] ?? 'available staff') . '.',
                    $settings['reminder_cooldown_hours'] * 60
                );
                $summary['notifications_created']++;
                $summary['assignment_suggestions']++;
            }
        }
        if(!$dryRun && function_exists('exception_automation_open')) {
            $opened = exception_automation_open($pdo, 'delayed_production', $orderId, [
                'notes' => 'Production overdue reminder triggered delayed production exception.',
                'assigned_handler_id' => (int) ($row['assigned_to'] ?? 0) > 0 ? (int) $row['assigned_to'] : null,
                'actor_role' => 'system',
            ], false);
            if(!empty($opened['opened'])) {
                $summary['production_delay_exceptions']++;
                $summary['notifications_created'] += (int) ($opened['notified'] ?? 0);
            }
        }
    }

    $pendingProofStmt = $pdo->prepare("\n        SELECT da.id, da.order_id, da.updated_at, o.order_number, o.client_id\n        FROM design_approvals da\n        JOIN orders o ON o.id = da.order_id\n        WHERE da.status = 'pending'\n          AND da.updated_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)\n    ");
    $pendingProofStmt->execute([$settings['design_approval_pending_hours']]);
    foreach($pendingProofStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $approvalId = (int) ($row['id'] ?? 0);
        $orderId = (int) ($row['order_id'] ?? 0);
        $clientId = (int) ($row['client_id'] ?? 0);
        if($approvalId <= 0 || $orderId <= 0 || $clientId <= 0) {
            continue;
        }
        $summary['design_approvals_pending']++;
        if(!automation_should_dispatch_reminder($pdo, 'design_approval_pending', 'proof:' . $approvalId, $settings['reminder_cooldown_hours'], $orderId, $dryRun)) {
            continue;
        }

        if(!$dryRun) {
            $message = sprintf('Reminder: design proof approval is still pending for order #%s.', (string) ($row['order_number'] ?? $orderId));
            create_notification_recent_once_for_order($pdo, $clientId, $orderId, 'order_status', $message, $settings['reminder_cooldown_hours'] * 60);
            $summary['notifications_created']++;
            automation_log_reminder_action($pdo, 'design_approval_pending', ['order_id' => $orderId, 'approval_id' => $approvalId]);
        }
    }

    $paymentVerificationStmt = $pdo->prepare("\n        SELECT o.id, o.order_number, s.owner_id\n        FROM orders o\n        JOIN shops s ON s.id = o.shop_id\n        WHERE o.payment_status = 'pending_verification'\n          AND o.updated_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)\n    ");
    $paymentVerificationStmt->execute([$settings['payment_verification_pending_hours']]);
    foreach($paymentVerificationStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $orderId = (int) ($row['id'] ?? 0);
        $ownerId = (int) ($row['owner_id'] ?? 0);
        if($orderId <= 0 || $ownerId <= 0) {
            continue;
        }
        $summary['pending_payment_verifications']++;
        if(!automation_should_dispatch_reminder($pdo, 'payment_verification_pending', 'order:' . $orderId, $settings['reminder_cooldown_hours'], $orderId, $dryRun)) {
            continue;
        }

        if(!$dryRun) {
            notify_business_event($pdo, 'payment_awaiting_verification', $orderId, [
                'actor_id' => 0,
                'owner_message' => sprintf('Payment verification is pending for order #%s.', (string) ($row['order_number'] ?? $orderId)),
            ]);
            $summary['notifications_created']++;
            automation_log_reminder_action($pdo, 'payment_verification_pending', ['order_id' => $orderId]);
        }
    }

    $qcPendingStmt = $pdo->prepare("\n        SELECT o.id, o.order_number, o.client_id, o.assigned_to, s.owner_id\n        FROM orders o\n        JOIN shops s ON s.id = o.shop_id\n        WHERE o.status = 'qc_pending'\n          AND o.updated_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)\n    ");
    $qcPendingStmt->execute([$settings['qc_pending_hours']]);
    foreach($qcPendingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $orderId = (int) $row['id'];
        if(!automation_should_dispatch_reminder($pdo, 'qc_pending', 'order:' . $orderId, $settings['reminder_cooldown_hours'], $orderId, $dryRun)) {
            continue;
        }
        $summary['qc_pending_alerts']++;
        if(!$dryRun) {
            notify_business_event($pdo, 'overdue_order', $orderId, [
                'actor_id' => 0,
                'lookback_hours' => $settings['reminder_cooldown_hours'],
                'message' => sprintf('QC is pending for order #%s past the configured SLA.', (string) ($row['order_number'] ?? $orderId)),
            ]);
            automation_log_reminder_action($pdo, 'qc_pending', ['order_id' => $orderId]);
        }
    }

    $pickupStmt = $pdo->prepare("\n        SELECT o.id, o.order_number, o.client_id, s.owner_id\n        FROM order_fulfillments f\n        JOIN orders o ON o.id = f.order_id\n        JOIN shops s ON s.id = o.shop_id\n        WHERE f.status = 'ready_for_pickup'\n          AND f.ready_at IS NOT NULL\n          AND f.ready_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)\n          AND o.status IN ('ready_for_delivery', 'delivered')\n    ");
    $pickupStmt->execute([$settings['ready_pickup_hours']]);
    foreach($pickupStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $summary['ready_for_pickup_unclaimed']++;
        $orderId = (int) $row['id'];
        if(!automation_should_dispatch_reminder($pdo, 'ready_for_pickup', 'order:' . $orderId, $settings['reminder_cooldown_hours'], $orderId, $dryRun)) {
            continue;
        }
        $orderNumber = (string) ($row['order_number'] ?? $orderId);
        $clientMsg = sprintf('Pickup reminder: order #%s is ready and still waiting to be claimed.', $orderNumber);
        $ownerMsg = sprintf('Order #%s is ready for pickup but has not been claimed yet.', $orderNumber);

        if(!$dryRun && (int) $row['client_id'] > 0 && !has_recent_notification_for_order_message($pdo, (int) $row['client_id'], $orderId, 'order_status', $clientMsg, $settings['reminder_cooldown_hours'] * 60)) {
            create_notification($pdo, (int) $row['client_id'], $orderId, 'order_status', $clientMsg);
            $summary['notifications_created']++;
        }
        if(!$dryRun && (int) $row['owner_id'] > 0 && !has_recent_notification_for_order_message($pdo, (int) $row['owner_id'], $orderId, 'order_status', $ownerMsg, $settings['reminder_cooldown_hours'] * 60)) {
            create_notification($pdo, (int) $row['owner_id'], $orderId, 'order_status', $ownerMsg);
            $summary['notifications_created']++;
        }
        if(!$dryRun) {
            automation_log_reminder_action($pdo, 'ready_for_pickup', ['order_id' => $orderId]);
        }
    }

    $deliveryStmt = $pdo->prepare("\n        SELECT o.id, o.order_number, o.client_id, s.owner_id\n        FROM order_fulfillments f\n        JOIN orders o ON o.id = f.order_id\n        JOIN shops s ON s.id = o.shop_id\n        WHERE f.status = 'out_for_delivery'\n          AND f.shipped_at IS NOT NULL\n          AND f.shipped_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)\n          AND o.status IN ('ready_for_delivery', 'delivered')\n    ");
    $deliveryStmt->execute([$settings['delivery_followup_hours']]);
    foreach($deliveryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $orderId = (int) $row['id'];
        if(!automation_should_dispatch_reminder($pdo, 'delivery_followup', 'order:' . $orderId, $settings['reminder_cooldown_hours'], $orderId, $dryRun)) {
            continue;
        }
        $summary['delivery_followups']++;
        $msg = sprintf('Delivery reminder: order #%s is still marked out for delivery.', (string) ($row['order_number'] ?? $orderId));
        if(!$dryRun && (int) $row['client_id'] > 0) {
            create_notification_recent_once_for_order($pdo, (int) $row['client_id'], $orderId, 'order_status', $msg, $settings['reminder_cooldown_hours'] * 60);
            $summary['notifications_created']++;
        }
        if(!$dryRun && (int) $row['owner_id'] > 0) {
            create_notification_recent_once_for_order($pdo, (int) $row['owner_id'], $orderId, 'order_status', $msg, $settings['reminder_cooldown_hours'] * 60);
            $summary['notifications_created']++;
        }
    }

    $overdueOrderStmt = $pdo->prepare("\n        SELECT o.id\n        FROM orders o\n        WHERE o.status IN ('accepted','digitizing','production_pending','production','production_rework','qc_pending','ready_for_delivery')\n          AND o.estimated_completion IS NOT NULL\n          AND o.estimated_completion <= DATE_SUB(NOW(), INTERVAL ? HOUR)\n    ");
    $overdueOrderStmt->execute([$settings['overdue_order_hours']]);
    foreach($overdueOrderStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $summary['overdue_orders']++;
        if(!$dryRun && automation_should_dispatch_reminder($pdo, 'overdue_order', 'order:' . (int) $row['id'], $settings['reminder_cooldown_hours'], (int) $row['id'], $dryRun)) {
            notify_business_event($pdo, 'overdue_order', (int) $row['id'], [
                'actor_id' => 0,
                'lookback_hours' => $settings['reminder_cooldown_hours'],
            ]);
        }
    }

    $canQuerySupportSla = reminder_schema_supports($pdo, 'support_tickets', ['id', 'order_id', 'status', 'created_at'])
        && reminder_schema_supports($pdo, 'orders', ['id', 'shop_id', 'order_number'])
        && reminder_schema_supports($pdo, 'shops', ['id', 'owner_id']);

    if($canQuerySupportSla) {
        $hasAssignedSupportStaff = reminder_schema_supports($pdo, 'support_tickets', ['assigned_staff_id']);
        $assignedSelect = $hasAssignedSupportStaff ? 'st.assigned_staff_id' : 'NULL AS assigned_staff_id';

        $supportStmt = $pdo->prepare(" 
            SELECT st.id, st.order_id, {$assignedSelect}, o.order_number, s.owner_id
            FROM support_tickets st
            JOIN orders o ON o.id = st.order_id
            JOIN shops s ON s.id = o.shop_id
            WHERE st.status IN ('open','under_review','assigned','in_progress')
              AND st.created_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $supportStmt->execute([$settings['support_sla_hours']]);
        foreach($supportStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if(!automation_should_dispatch_reminder($pdo, 'support_sla', 'ticket:' . (int) $row['id'], $settings['reminder_cooldown_hours'], (int) $row['order_id'], $dryRun)) {
                continue;
            }
            $summary['unresolved_support_tickets']++;
            if(!$dryRun) {
                messaging_auto_thread_on_client_owner_discussion($pdo, (int) $row['order_id'], 0, 'Support/dispute SLA reminder triggered.');
                $assignedStaff = (int) ($row['assigned_staff_id'] ?? 0);
                if($assignedStaff <= 0) {
                    $suggested = choose_best_staff_assignee($pdo, (int) $row['order_id']);
                    if($suggested && (int) ($row['owner_id'] ?? 0) > 0) {
                        create_notification_recent_once_for_order(
                            $pdo,
                            (int) $row['owner_id'],
                            (int) $row['order_id'],
                            'warning',
                            'Support SLA reminder for order #' . ($row['order_number'] ?? $row['order_id']) . '. Suggested assignee: ' . ($suggested['fullname'] ?? 'available staff') . '.',
                            $settings['reminder_cooldown_hours'] * 60
                        );
                        $summary['notifications_created']++;
                        $summary['assignment_suggestions']++;
                    }
                }
                if(function_exists('exception_automation_open')) {
                    $opened = exception_automation_open($pdo, 'support_sla_breach', (int) $row['order_id'], [
                        'notes' => 'Support SLA reminder triggered escalation flow.',
                        'assigned_handler_id' => (int) ($row['assigned_staff_id'] ?? 0) > 0 ? (int) $row['assigned_staff_id'] : null,
                        'actor_role' => 'system',
                    ], false);
                    if(!empty($opened['opened'])) {
                        $summary['support_sla_escalations']++;
                        $summary['notifications_created'] += (int) ($opened['notified'] ?? 0);
                    }
                }
            }
        }
    }



    $canQueryDisputeSla = $canQuerySupportSla && reminder_schema_supports($pdo, 'support_tickets', ['issue_type']);
    if($canQueryDisputeSla) {
        $hasAssignedSupportStaff = reminder_schema_supports($pdo, 'support_tickets', ['assigned_staff_id']);
        $assignedSelect = $hasAssignedSupportStaff ? 'st.assigned_staff_id' : 'NULL AS assigned_staff_id';
        $disputeStmt = $pdo->prepare(" 
            SELECT st.id, st.order_id, {$assignedSelect}, o.order_number, s.owner_id
            FROM support_tickets st
            JOIN orders o ON o.id = st.order_id
            JOIN shops s ON s.id = o.shop_id
            WHERE st.status IN ('open','under_review','assigned','in_progress')
              AND LOWER(st.issue_type) LIKE '%dispute%'
              AND st.created_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $disputeStmt->execute([$settings['dispute_sla_hours']]);
        foreach($disputeStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if(!automation_should_dispatch_reminder($pdo, 'dispute_sla', 'ticket:' . (int) $row['id'], $settings['reminder_cooldown_hours'], (int) $row['order_id'], $dryRun)) {
                continue;
            }
            $summary['dispute_sla_alerts']++;
            if(!$dryRun) {
                notify_dispute_update($pdo, (int) $row['order_id'], 'SLA reminder: unresolved dispute', 0);
                automation_log_reminder_action($pdo, 'dispute_sla', ['ticket_id' => (int) $row['id'], 'order_id' => (int) $row['order_id']]);
            }
        }
    }

    $inventoryStmt = $pdo->query("
        SELECT DISTINCT omr.order_id
        FROM order_material_reservations omr
        JOIN raw_materials rm ON rm.id = omr.material_id
        WHERE omr.status = 'reserved'
          AND rm.current_stock < omr.reserved_qty
    ");
    foreach($inventoryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $summary['inventory_shortages']++;
        if(!automation_should_dispatch_reminder($pdo, 'reserved_material_shortage', 'order:' . (int) $row['order_id'], $settings['reminder_cooldown_hours'], (int) $row['order_id'], $dryRun)) {
            continue;
        }
    }

$lowStockStmt = $pdo->prepare("\n        SELECT rm.id, rm.shop_id, rm.name AS material_name, rm.current_stock, rm.min_stock_level, s.owner_id\n        FROM raw_materials rm\n        JOIN shops s ON s.id = rm.shop_id\n        WHERE rm.current_stock <= GREATEST(COALESCE(rm.min_stock_level, 0), ?)\n    ");
    $lowStockStmt->execute([$settings['low_stock_threshold']]);
    foreach($lowStockStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $materialId = (int) ($row['id'] ?? 0);
        if($materialId <= 0) {
            continue;
        }
        if(!automation_should_dispatch_reminder($pdo, 'low_stock', 'material:' . $materialId, $settings['reminder_cooldown_hours'], null, $dryRun)) {
            continue;
        }
        $summary['low_stock_alerts']++;
        if(!$dryRun && (int) ($row['owner_id'] ?? 0) > 0) {
            $message = sprintf('Low stock alert: %s has %.2f remaining (reorder level %.2f).', (string) ($row['material_name'] ?? 'Material'), (float) ($row['current_stock'] ?? 0), (float) ($row['min_stock_level'] ?? 0));
            if(!has_recent_notification_by_type_and_message($pdo, (int) $row['owner_id'], 'warning', $message, $settings['reminder_cooldown_hours'])) {
                create_notification($pdo, (int) $row['owner_id'], null, 'warning', $message);
                $summary['notifications_created']++;
            }
            automation_log_reminder_action($pdo, 'low_stock', ['material_id' => $materialId, 'shop_id' => (int) ($row['shop_id'] ?? 0)]);
        }
    }


    if(!$dryRun && $settings['auto_recalculate_metrics'] && function_exists('refresh_shop_metrics')) {
        refresh_shop_metrics($pdo);
        $summary['metrics_recalculated'] = 1;
    }

    $currentHour = (int) date('G');
    $currentWeekday = (int) date('N');
    if(!$dryRun && $currentHour === (int) $settings['daily_summary_hour']) {
        $dailyKey = 'daily:' . date('Y-m-d');
        if(automation_should_dispatch_reminder($pdo, 'automation_daily_summary_hook', $dailyKey, 12, null, false)) {
            automation_log_reminder_action($pdo, 'daily_summary_hook', ['date' => date('Y-m-d'), 'summary' => $summary]);
            $summary['daily_summary_hooks']++;
        }
    }

    if(!$dryRun && $currentWeekday === (int) $settings['weekly_summary_weekday'] && $currentHour === (int) $settings['daily_summary_hour']) {
        $weeklyKey = 'weekly:' . date('o-\\WW');
        if(automation_should_dispatch_reminder($pdo, 'automation_weekly_summary_hook', $weeklyKey, 36, null, false)) {
            automation_log_reminder_action($pdo, 'weekly_summary_hook', ['week' => date('o-\\WW'), 'summary' => $summary]);
            $summary['weekly_summary_hooks']++;
        }
    }

    return $summary;
}
