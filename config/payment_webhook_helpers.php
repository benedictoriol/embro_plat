<?php

function payment_webhook_resolve_target(PDO $pdo, array $event): ?array {
    $provider_transaction_id = trim((string) ($event['provider_transaction_id'] ?? ''));
    $reference_number = trim((string) ($event['reference_number'] ?? ''));

    if($provider_transaction_id !== '') {
        $stmt = $pdo->prepare("SELECT * FROM payments WHERE provider_transaction_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$provider_transaction_id]);
        $payment = $stmt->fetch();
        if($payment) {
            return $payment;
        }
    }

    if($reference_number !== '') {
        $stmt = $pdo->prepare("SELECT * FROM payments WHERE reference_number = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$reference_number]);
        $payment = $stmt->fetch();
        if($payment) {
            return $payment;
        }
    }

    return null;
}

function payment_webhook_apply_event(PDO $pdo, array $event, ?int $verified_by = null): array {
    $payment = payment_webhook_resolve_target($pdo, $event);
    if(!$payment) {
        return [false, 'Payment record not found for webhook event.'];
    }

    $status = normalize_payment_status((string) ($event['status'] ?? 'pending_verification'));
    $paid_amount = isset($event['paid_amount']) ? (float) $event['paid_amount'] : (float) ($payment['paid_amount'] ?? $payment['amount'] ?? 0);
    $expected_amount = (float) ($payment['expected_amount'] ?? $payment['amount'] ?? 0);
    $paymentStatus = payment_calculate_status_from_amount($expected_amount, $paid_amount, $status);
    $order_status = payment_calculate_order_financial_status($expected_amount, $paid_amount, $paymentStatus);

    $verified_at = in_array($paymentStatus, ['paid', 'partially_paid'], true) ? date('Y-m-d H:i:s') : null;

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE payments SET status = ?, paid_amount = ?, provider_transaction_id = ?, notes = ?, verified_by = ?, verified_at = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([
            $paymentStatus,
            $paid_amount,
            $event['provider_transaction_id'] ?? ($payment['provider_transaction_id'] ?? null),
            $event['notes'] ?? ($payment['notes'] ?? null),
            $verified_by,
            $verified_at,
            $payment['id'],
        ]);

        $order_stmt = $pdo->prepare("UPDATE orders SET payment_status = ?, payment_verified_at = ?, updated_at = NOW() WHERE id = ?");
        $order_stmt->execute([$order_status, $verified_at, $payment['order_id']]);

        if(table_exists($pdo, 'payment_attempts')) {
            $attemptStmt = $pdo->prepare("UPDATE payment_attempts SET status = ?, updated_at = NOW() WHERE reference_number = ?");
            $attemptStmt->execute([
                match ($paymentStatus) {
                    'paid' => 'paid',
                    'failed' => 'failed',
                    'cancelled' => 'expired',
                    default => 'pending',
                },
                $event['reference_number'] ?? ($payment['reference_number'] ?? ''),
            ]);
        }

        payment_record_timeline(
            $pdo,
            (int) $payment['order_id'],
            (int) $payment['id'],
            null,
            'webhook_' . $paymentStatus,
            $verified_by,
            'system',
            (string) ($event['notes'] ?? 'Gateway webhook update'),
            $event
        );

    $pdo->commit();
    } catch(Throwable $e) {
        if($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [false, 'Failed to process webhook event.'];
    }

    automation_sync_invoice_for_order($pdo, (int) $payment['order_id']);
    automation_sync_payment_hold_state($pdo, (int) $payment['order_id'], $verified_by, 'system', 'payment_webhook');

    if(function_exists('order_exception_open') && in_array($paymentStatus, ['failed', 'cancelled'], true)) {
        order_exception_open(
            $pdo,
            (int) $payment['order_id'],
            'unpaid_block',
            'high',
            'Payment attempt failed/cancelled from gateway webhook event.',
            null,
            $verified_by,
            'system'
        );
    }

    if(function_exists('order_exception_resolve') && in_array($paymentStatus, ['paid', 'partially_paid'], true)) {
        order_exception_resolve($pdo, (int) $payment['order_id'], 'unpaid_block', 'Payment state updated successfully from gateway webhook.', $verified_by, 'system');
    }

    $eventType = match ($paymentStatus) {
        'paid' => 'payment_verified',
        'failed', 'cancelled' => 'payment_failed',
        default => 'payment_awaiting_verification',
    };

    notify_business_event($pdo, $eventType, (int) $payment['order_id'], [
        'actor_id' => $verified_by,
        'client_message' => 'Payment update received for your order #' . ($payment['order_id']) . '.',
        'owner_message' => 'Gateway payment update received for order #' . ($payment['order_id']) . '.',
    ]);

    return [true, null];
}
