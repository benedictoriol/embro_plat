<?php
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/automation_helpers.php';
require_once __DIR__ . '/quote_helpers.php';
require_once __DIR__ . '/assignment_helpers.php';
require_once __DIR__ . '/exception_helpers.php';
require_once __DIR__ . '/notification_functions.php';

class OrderService {
    public static function transitionStatus(
        PDO $pdo,
        int $order_id,
        string $next_status,
        ?int $actor_user_id = null,
        ?string $actor_role = null,
        ?string $notes = null,
        bool $allow_manual_override = false,
        ?string $audit_action = 'order_status_changed'
    ): array {
        return automation_transition_order_status(
            $pdo,
            $order_id,
            $next_status,
            $actor_user_id,
            $actor_role,
            $notes,
            $allow_manual_override,
            $audit_action
        );
    }

    public static function assignStaff(PDO $pdo, int $order_id, int $staff_id, int $assigned_by): array {
        if($order_id <= 0 || $staff_id <= 0) {
            return [false, 'Invalid order or staff selection.'];
        }

        if(!assign_order_to_staff($pdo, $order_id, $staff_id, $assigned_by)) {
            return [false, 'Failed to assign this order to the selected staff member.'];
        }

        return [true, null];
    }

    public static function unassignStaff(PDO $pdo, int $order_id, int $shop_id, ?int $expected_assignee = null): array {
        if($order_id <= 0 || $shop_id <= 0) {
            return [false, 'Invalid order context.'];
        }

        $assign_stmt = $pdo->prepare("\n            UPDATE orders\n            SET assigned_to = NULL, updated_at = NOW()\n            WHERE id = ?\n              AND shop_id = ?\n              AND (assigned_to <=> ?)\n        ");
        $assign_stmt->execute([$order_id, $shop_id, $expected_assignee]);

        if($assign_stmt->rowCount() <= 0) {
            return [false, 'Assignment changed by another user. Please refresh and try again.'];
        }

        if(function_exists('sync_production_queue')) {
            sync_production_queue($pdo);
        }

        return [true, null];
    }
}

class QuoteService {
    public static function clientRespond(PDO $pdo, int $order_id, int $client_id, string $decision, ?string $actor_role = null): array {
        $normalized = strtolower(trim($decision));
        if(!in_array($normalized, ['approved', 'rejected'], true)) {
            return [false, 'Invalid quote response.'];
        }

        [$ok, $err] = quote_client_respond($pdo, $order_id, $client_id, $normalized);
        if(!$ok) {
            return [false, $err ?: 'Unable to process quote response.'];
        }

        if($normalized === 'approved') {
            [$transition_ok, $transition_error] = OrderService::transitionStatus(
                $pdo,
                $order_id,
                STATUS_ACCEPTED,
                $client_id,
                $actor_role,
                'Client approved quote and accepted order.',
                false,
                'order_quote_approved'
            );

            if(!$transition_ok) {
                return [false, $transition_error ?: 'Quote approved but order acceptance transition failed.'];
            }

            notify_business_event($pdo, 'order_quote_approved', $order_id, [
                'actor_id' => $client_id,
            ]);
        } else {
            [$transition_ok, $transition_error] = OrderService::transitionStatus(
                $pdo,
                $order_id,
                STATUS_PENDING,
                $client_id,
                $actor_role,
                'Client rejected quote. Returned to pending status.',
                true,
                'order_quote_rejected'
            );

            if(!$transition_ok) {
                return [false, $transition_error ?: 'Quote rejected but order reset failed.'];
            }

            $pdo->prepare("UPDATE orders SET design_approved = 0, updated_at = NOW() WHERE id = ? AND client_id = ?")
                ->execute([$order_id, $client_id]);

            ExceptionService::open(
                $pdo,
                $order_id,
                'quote_expired',
                'low',
                'Client rejected quote; waiting for revised quote or renegotiation.'
            );

            notify_business_event($pdo, 'order_quote_rejected', $order_id, [
                'actor_id' => $client_id,
            ]);
        }

        return [true, null];
    }
}

class ProductionService {
    public static function startOrderProduction(PDO $pdo, int $order_id, int $shop_id, int $actor_user_id, ?string $actor_role = null): array {
        $order_stmt = $pdo->prepare("
            SELECT o.id, o.order_number, o.status
            FROM orders o
            WHERE o.id = ? AND o.shop_id = ?
            LIMIT 1
        ");
        $order_stmt->execute([$order_id, $shop_id]);
        $order = $order_stmt->fetch(PDO::FETCH_ASSOC);

        if(!$order) {
            return [false, 'Unable to locate the order to start production.', null];
        }

        $status = order_workflow_normalize_order_status((string) ($order['status'] ?? ''));
        if(!in_array($status, [STATUS_PRODUCTION_PENDING, STATUS_PRODUCTION_REWORK], true)) {
            return [false, 'Only production-ready or rework orders can be started.', null];
        }

        [$transition_ok, $transition_error, $transition_meta] = OrderService::transitionStatus(
            $pdo,
            $order_id,
            STATUS_PRODUCTION,
            $actor_user_id,
            $actor_role,
            'Production started by shop owner.',
            false,
            'start_production'
        );

        if(!$transition_ok) {
            return [false, $transition_error ?: 'Unable to start production.', null];
        }

        notify_business_event($pdo, 'production_update', $order_id, [
            'message' => 'Order #' . $order['order_number'] . ' is now in production.',
            'notify_client' => true,
            'notify_owner' => false,
            'actor_id' => $actor_user_id,
        ]);

        return [true, null, $transition_meta['meta']['production_inventory'] ?? null];
    }
}

class QCService {
    public static function applyDecision(PDO $pdo, int $order_id, string $order_number, string $qc_result, string $notes, int $actor_user_id, ?string $actor_role = null, ?int $owner_id = null): array {
        if($qc_result === 'passed') {
            $qc_notes = $notes !== '' ? $notes : 'QC passed.';
            [$ok, $error] = automation_apply_order_event_transition($pdo, $order_id, 'qc_passed', $actor_user_id, $actor_role, false, 'QC PASS: ' . $qc_notes);
            if(!$ok) {
                return [false, $error ?: 'Unable to advance order after QC pass.'];
            }

            ExceptionService::resolve($pdo, $order_id, 'qc_failed', 'QC passed after rework and moved to delivery readiness.');
            return [true, null];
        }

        $qc_notes = $notes !== '' ? $notes : 'QC failed. Rework required.';
        [$ok, $error] = automation_apply_order_event_transition($pdo, $order_id, 'qc_failed', $actor_user_id, $actor_role, false, 'QC FAIL: ' . $qc_notes);
        if(!$ok) {
            return [false, $error ?: 'Unable to move order to rework after QC failure.'];
        }

        ExceptionService::open($pdo, $order_id, 'qc_failed', 'high', $qc_notes, $owner_id);

        return [true, null];
    }
}

class FulfillmentService {
    public static function save(PDO $pdo, array $order, array $payload, int $actor_user_id, ?string $actor_role = null): array {
        return automation_upsert_order_fulfillment($pdo, $order, $payload, $actor_user_id, $actor_role);
    }
}

class PaymentService {
    public static function syncOrderPaymentState(PDO $pdo, int $order_id, ?int $actor_user_id = null, ?string $actor_role = null, ?string $context = null): void {
        automation_sync_payment_hold_state($pdo, $order_id, $actor_user_id, $actor_role, $context);
        automation_sync_invoice_for_order($pdo, $order_id);
    }
}

class ExceptionService {
    public static function open(PDO $pdo, int $order_id, string $type, string $severity = 'medium', ?string $notes = null, ?int $assigned_handler_id = null): void {
        if(function_exists('order_exception_open')) {
            order_exception_open($pdo, $order_id, $type, $severity, $notes, $assigned_handler_id);
        }
    }

    public static function resolve(PDO $pdo, int $order_id, string $type, ?string $resolution_notes = null): void {
        if(function_exists('order_exception_resolve')) {
            order_exception_resolve($pdo, $order_id, $type, $resolution_notes);
        }
    }
}
