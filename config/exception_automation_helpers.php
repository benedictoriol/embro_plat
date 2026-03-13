<?php

require_once __DIR__ . '/exception_helpers.php';
require_once __DIR__ . '/notification_functions.php';
require_once __DIR__ . '/system_settings.php';


function exception_automation_schema_supports(PDO $pdo, string $table, array $columns = []): bool {
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

function exception_automation_config(PDO $pdo): array {
    return [
        'default_escalation_hours' => max(1, (int) system_setting_get($pdo, 'automation', 'exception_default_escalation_hours', 12)),
        'reminder_cooldown_minutes' => max(5, (int) system_setting_get($pdo, 'automation', 'exception_reminder_cooldown_minutes', 120)),
        'triggers' => [
            'overdue_payment' => ['type' => 'unpaid_block', 'severity' => 'high', 'roles' => ['client', 'owner'], 'escalate_to' => ['sys_admin'], 'hours' => max(1, (int) system_setting_get($pdo, 'automation', 'overdue_payment_escalation_hours', 12))],
            'stale_quotation' => ['type' => 'quote_expired', 'severity' => 'medium', 'roles' => ['client', 'owner'], 'escalate_to' => ['owner'], 'hours' => max(1, (int) system_setting_get($pdo, 'automation', 'stale_quotation_escalation_hours', 24))],
            'delayed_production' => ['type' => 'production_delay', 'severity' => 'high', 'roles' => ['owner', 'staff'], 'escalate_to' => ['hr', 'sys_admin'], 'hours' => max(1, (int) system_setting_get($pdo, 'automation', 'delayed_production_escalation_hours', 8))],
            'qc_failure' => ['type' => 'qc_failed', 'severity' => 'high', 'roles' => ['owner', 'staff'], 'escalate_to' => ['hr'], 'hours' => max(1, (int) system_setting_get($pdo, 'automation', 'qc_failure_escalation_hours', 6))],
            'missing_materials' => ['type' => 'materials_unavailable', 'severity' => 'high', 'roles' => ['owner', 'staff'], 'escalate_to' => ['hr'], 'hours' => max(1, (int) system_setting_get($pdo, 'automation', 'missing_materials_escalation_hours', 6))],
            'support_sla_breach' => ['type' => 'support_unresolved', 'severity' => 'high', 'roles' => ['owner', 'staff'], 'escalate_to' => ['sys_admin'], 'hours' => max(1, (int) system_setting_get($pdo, 'automation', 'support_sla_escalation_hours', 12))],
            'unresolved_dispute' => ['type' => 'dispute_unresolved', 'severity' => 'critical', 'roles' => ['client', 'owner', 'staff'], 'escalate_to' => ['sys_admin'], 'hours' => max(1, (int) system_setting_get($pdo, 'automation', 'dispute_sla_escalation_hours', 8))],
            'blocked_order_readiness' => ['type' => 'assignment_blocked', 'severity' => 'high', 'roles' => ['owner', 'staff'], 'escalate_to' => ['hr'], 'hours' => max(1, (int) system_setting_get($pdo, 'automation', 'readiness_block_escalation_hours', 6))],
        ],
    ];
}

function exception_automation_fetch_targets(PDO $pdo, int $order_id, array $roles): array {
    $roles = array_values(array_unique(array_filter($roles)));
    if($order_id <= 0 || empty($roles)) {
        return [];
    }

    $order = fetch_order_notification_context($pdo, $order_id);
    if(!$order) {
        return [];
    }

    $targets = [];
    foreach($roles as $role) {
        if($role === 'client' && (int) ($order['client_id'] ?? 0) > 0) {
            $targets[] = (int) $order['client_id'];
        }
        if($role === 'owner' && (int) ($order['owner_id'] ?? 0) > 0) {
            $targets[] = (int) $order['owner_id'];
        }
        if($role === 'staff' && (int) ($order['assigned_to'] ?? 0) > 0) {
            $targets[] = (int) $order['assigned_to'];
        }
        if($role === 'hr') {
            foreach(fetch_shop_hr_user_ids($pdo, (int) ($order['shop_id'] ?? 0)) as $hrId) {
                $targets[] = (int) $hrId;
            }
        }
        if($role === 'sys_admin') {
            $adminStmt = $pdo->query("SELECT id FROM users WHERE role = 'sys_admin' AND status = 'active'");
            foreach($adminStmt->fetchAll(PDO::FETCH_COLUMN) as $adminId) {
                $targets[] = (int) $adminId;
            }
        }
    }

    return array_values(array_unique(array_filter($targets, static fn($id) => $id > 0)));
}

function exception_automation_notify(PDO $pdo, int $order_id, string $message, array $roles, int $cooldownMinutes): int {
    $targets = exception_automation_fetch_targets($pdo, $order_id, $roles);
    $sent = 0;
    foreach($targets as $userId) {
        create_notification_recent_once_for_order($pdo, $userId, $order_id, 'warning', $message, $cooldownMinutes);
        $sent++;
    }
    return $sent;
}

function exception_automation_open(PDO $pdo, string $trigger_key, int $order_id, array $context = [], bool $dryRun = false): array {
    $config = exception_automation_config($pdo);
    $trigger = $config['triggers'][$trigger_key] ?? null;
    if(!$trigger || $order_id <= 0) {
        return ['opened' => false, 'notified' => 0, 'type' => null];
    }

    $type = (string) ($trigger['type'] ?? 'support_unresolved');
    $severity = (string) ($trigger['severity'] ?? 'medium');
    $notes = (string) ($context['notes'] ?? ('Automation trigger fired: ' . str_replace('_', ' ', $trigger_key) . '.'));
    $assigned = isset($context['assigned_handler_id']) ? (int) $context['assigned_handler_id'] : null;
    $actorId = isset($context['actor_id']) ? (int) $context['actor_id'] : 0;
    $actorRole = isset($context['actor_role']) ? (string) $context['actor_role'] : 'system';

    if(!$dryRun) {
        order_exception_open($pdo, $order_id, $type, $severity, $notes, $assigned, $actorId, $actorRole);
    }

    $message = sprintf('Automation exception: %s for order #%d.', str_replace('_', ' ', $type), $order_id);
    $notified = $dryRun ? 0 : exception_automation_notify($pdo, $order_id, $message, (array) ($trigger['roles'] ?? []), $config['reminder_cooldown_minutes']);

    return ['opened' => true, 'notified' => $notified, 'type' => $type];
}

function exception_automation_escalate_overdue(PDO $pdo, bool $dryRun = false): int {
    $config = exception_automation_config($pdo);
    $defaultHours = (int) ($config['default_escalation_hours'] ?? 12);
    $count = 0;

    if(!exception_automation_schema_supports($pdo, 'order_exceptions', ['id', 'order_id', 'exception_type', 'status', 'created_at'])) {
        return 0;
    }

    $stmt = $pdo->query("SELECT id, order_id, exception_type, status, created_at FROM order_exceptions WHERE status IN ('open','in_progress')");
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $exceptionId = (int) ($row['id'] ?? 0);
        $orderId = (int) ($row['order_id'] ?? 0);
        $type = (string) ($row['exception_type'] ?? '');
        if($exceptionId <= 0 || $orderId <= 0 || $type === '') {
            continue;
        }

        $triggerKey = null;
        foreach($config['triggers'] as $key => $trigger) {
            if(($trigger['type'] ?? null) === $type) {
                $triggerKey = $key;
                break;
            }
        }

        $thresholdHours = $triggerKey !== null ? (int) ($config['triggers'][$triggerKey]['hours'] ?? $defaultHours) : $defaultHours;
        $createdAt = strtotime((string) ($row['created_at'] ?? '')) ?: time();
        if($createdAt > strtotime('-' . $thresholdHours . ' hours')) {
            continue;
        }

        if(!$dryRun) {
            order_exception_update($pdo, $exceptionId, 'escalated', 'Escalated by unified automation SLA checker.', null, 0, 'system');
            if($triggerKey !== null) {
                $roles = (array) ($config['triggers'][$triggerKey]['escalate_to'] ?? ['owner']);
                exception_automation_notify($pdo, $orderId, 'Escalated exception requires attention for order #' . $orderId . '.', $roles, $config['reminder_cooldown_minutes']);
            }
        }
        $count++;
    }

    return $count;
}

function run_exception_automation(PDO $pdo, bool $dryRun = false): array {
    $config = exception_automation_config($pdo);
    $summary = [
        'exceptions_opened' => 0,
        'exceptions_escalated' => 0,
        'exceptions_resolved' => 0,
        'notifications_created' => 0,
        'stale_quotation_candidates' => 0,
        'overdue_payment_candidates' => 0,
        'delayed_production_candidates' => 0,
        'support_sla_candidates' => 0,
        'dispute_sla_candidates' => 0,
        'material_shortage_candidates' => 0,
    ];

    $openIfNeeded = static function(string $triggerKey, int $orderId, array $context = []) use ($pdo, $dryRun, &$summary): void {
        if($orderId <= 0) {
            return;
        }
        $opened = exception_automation_open($pdo, $triggerKey, $orderId, $context, $dryRun);
        if(!empty($opened['opened'])) {
            $summary['exceptions_opened']++;
        }
        $summary['notifications_created'] += (int) ($opened['notified'] ?? 0);
    };

    $staleHours = (int) ($config['triggers']['stale_quotation']['hours'] ?? 24);
    $staleStmt = $pdo->prepare("\n        SELECT o.id\n        FROM orders o\n        WHERE o.status = 'pending'\n          AND o.quote_status = 'sent'\n          AND o.updated_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)\n    ");
    $staleStmt->execute([$staleHours]);
    foreach($staleStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $summary['stale_quotation_candidates']++;
        $openIfNeeded('stale_quotation', (int) ($row['id'] ?? 0), [
            'notes' => 'Quote has remained pending without approval past exception SLA.',
        ]);
    }

    $paymentHours = (int) ($config['triggers']['overdue_payment']['hours'] ?? 12);
    $paymentStmt = $pdo->prepare("\n        SELECT o.id\n        FROM orders o\n        WHERE o.status IN ('accepted','digitizing','production_pending','production','production_rework','qc_pending')\n          AND o.payment_status IN ('unpaid', 'failed')\n          AND o.updated_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)\n    ");
    $paymentStmt->execute([$paymentHours]);
    foreach($paymentStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $summary['overdue_payment_candidates']++;
        $openIfNeeded('overdue_payment', (int) ($row['id'] ?? 0), [
            'notes' => 'Order is blocked by missing or failed payment beyond exception SLA.',
        ]);
    }

    $productionHours = (int) ($config['triggers']['delayed_production']['hours'] ?? 8);
    $productionStmt = $pdo->prepare("\n        SELECT o.id, o.assigned_to\n        FROM orders o\n        WHERE o.status IN ('production','production_rework','qc_pending')\n          AND o.estimated_completion IS NOT NULL\n          AND o.estimated_completion <= DATE_SUB(NOW(), INTERVAL ? HOUR)\n    ");
    $productionStmt->execute([$productionHours]);
    foreach($productionStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $summary['delayed_production_candidates']++;
        $openIfNeeded('delayed_production', (int) ($row['id'] ?? 0), [
            'notes' => 'Production timeline exceeded estimated completion threshold.',
            'assigned_handler_id' => (int) ($row['assigned_to'] ?? 0) > 0 ? (int) $row['assigned_to'] : null,
        ]);
    }

    $supportHours = (int) ($config['triggers']['support_sla_breach']['hours'] ?? 12);
    if(exception_automation_schema_supports($pdo, 'support_tickets', ['order_id', 'status', 'created_at'])) {
        $assignedSupportColumn = exception_automation_schema_supports($pdo, 'support_tickets', ['assigned_staff_id'])
            ? 'st.assigned_staff_id'
            : 'NULL AS assigned_staff_id';
        $supportStmt = $pdo->prepare("\n            SELECT st.order_id, {$assignedSupportColumn}\n            FROM support_tickets st\n            WHERE st.status IN ('open','under_review','assigned','in_progress')\n              AND st.created_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)\n        ");
        $supportStmt->execute([$supportHours]);
        foreach($supportStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $summary['support_sla_candidates']++;
            $openIfNeeded('support_sla_breach', (int) ($row['order_id'] ?? 0), [
                'notes' => 'Support ticket unresolved past exception SLA threshold.',
                'assigned_handler_id' => (int) ($row['assigned_staff_id'] ?? 0) > 0 ? (int) $row['assigned_staff_id'] : null,
            ]);
        }
    }

    $disputeHours = (int) ($config['triggers']['unresolved_dispute']['hours'] ?? 8);
    if(exception_automation_schema_supports($pdo, 'support_tickets', ['order_id', 'status', 'created_at', 'issue_type'])) {
        $assignedSupportColumn = exception_automation_schema_supports($pdo, 'support_tickets', ['assigned_staff_id'])
            ? 'st.assigned_staff_id'
            : 'NULL AS assigned_staff_id';
        $disputeStmt = $pdo->prepare("\n            SELECT st.order_id, {$assignedSupportColumn}\n            FROM support_tickets st\n            WHERE st.status IN ('open','under_review','assigned','in_progress')\n              AND LOWER(st.issue_type) LIKE '%dispute%'\n              AND st.created_at <= DATE_SUB(NOW(), INTERVAL ? HOUR)\n        ");
        $disputeStmt->execute([$disputeHours]);
        foreach($disputeStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $summary['dispute_sla_candidates']++;
            $openIfNeeded('unresolved_dispute', (int) ($row['order_id'] ?? 0), [
                'notes' => 'Dispute ticket unresolved past exception SLA threshold.',
                'assigned_handler_id' => (int) ($row['assigned_staff_id'] ?? 0) > 0 ? (int) $row['assigned_staff_id'] : null,
            ]);
        }
    }

    if(exception_automation_schema_supports($pdo, 'order_material_reservations', ['order_id', 'material_id', 'reserved_qty', 'status'])
        && exception_automation_schema_supports($pdo, 'raw_materials', ['id', 'current_stock'])) {
        $materialsStmt = $pdo->query("\n            SELECT DISTINCT omr.order_id\n            FROM order_material_reservations omr\n            JOIN raw_materials rm ON rm.id = omr.material_id\n            WHERE omr.status = 'reserved'\n              AND rm.current_stock < omr.reserved_qty\n        ");
        foreach($materialsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $summary['material_shortage_candidates']++;
            $openIfNeeded('missing_materials', (int) ($row['order_id'] ?? 0), [
                'notes' => 'Inventory shortage detected against reserved requirement.',
            ]);
        }
    }

    if(!$dryRun) {
        $summary['exceptions_escalated'] = exception_automation_escalate_overdue($pdo, false);
    }

    if(!$dryRun) {
        $resolveMap = [
            'quote_expired' => "SELECT 1 FROM orders WHERE id = ? AND status = 'pending' AND quote_status = 'sent'",
            'unpaid_block' => "SELECT 1 FROM orders WHERE id = ? AND status IN ('accepted','digitizing','production_pending','production','production_rework','qc_pending') AND payment_status IN ('unpaid','failed')",
            'production_delay' => "SELECT 1 FROM orders WHERE id = ? AND status IN ('production','production_rework','qc_pending') AND estimated_completion IS NOT NULL AND estimated_completion <= NOW()",
            'materials_unavailable' => "SELECT 1 FROM order_material_reservations omr JOIN raw_materials rm ON rm.id = omr.material_id WHERE omr.order_id = ? AND omr.status = 'reserved' AND rm.current_stock < omr.reserved_qty LIMIT 1",
            'support_unresolved' => "SELECT 1 FROM support_tickets WHERE order_id = ? AND status IN ('open','under_review','assigned','in_progress') LIMIT 1",
            'dispute_unresolved' => "SELECT 1 FROM support_tickets WHERE order_id = ? AND status IN ('open','under_review','assigned','in_progress') AND LOWER(issue_type) LIKE '%dispute%' LIMIT 1",
        ];

        if(exception_automation_schema_supports($pdo, 'order_exceptions', ['id', 'order_id', 'exception_type', 'status'])) {
            $activeStmt = $pdo->query("SELECT id, order_id, exception_type FROM order_exceptions WHERE status IN ('open','in_progress','escalated')");
            foreach($activeStmt->fetchAll(PDO::FETCH_ASSOC) as $exception) {
            $type = (string) ($exception['exception_type'] ?? '');
            $orderId = (int) ($exception['order_id'] ?? 0);
            if($orderId <= 0 || !isset($resolveMap[$type])) {
                continue;
            }

            $check = $pdo->prepare($resolveMap[$type]);
            $check->execute([$orderId]);
            if($check->fetchColumn()) {
                continue;
            }

            if(order_exception_update($pdo, (int) ($exception['id'] ?? 0), 'resolved', 'Auto-resolved by exception automation because trigger condition is no longer met.', null, 0, 'system')) {
                    $summary['exceptions_resolved']++;
                }
            }
        }
    }

    return $summary;
}