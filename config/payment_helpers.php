<?php
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/payment_gateway.php';

function available_payment_methods(): array {
    return [
        ['code' => 'pickup_pay', 'label' => 'Pick Up Pay', 'description' => 'Settle your order when you pick it up from the shop.', 'icon' => 'fa-store'],
        ['code' => 'cod', 'label' => 'Cash on Delivery (COD)', 'description' => 'Pay in cash upon delivery.', 'icon' => 'fa-truck'],
        payment_gateway_method_definition(),
    ];
}

function payment_manual_proof_enabled(): bool {
    $configured = strtolower(trim((string) getenv('PAYMENT_ALLOW_MANUAL_PROOF')));
    if($configured !== '') {
        return in_array($configured, ['1', 'true', 'yes', 'on'], true);
    }

    global $pdo;
    if(isset($pdo) && $pdo instanceof PDO) {
        return (bool) system_setting_get($pdo, 'payment', 'allow_manual_proof', false);
    }

    return false;
}

function payment_methods_for_submission(): array {
    return array_values(array_filter(
        available_payment_methods(),
        static fn(array $method): bool => !in_array($method['code'], ['pickup_pay', 'cod'], true)
    ));
}

function payment_method_labels_map(): array {
    $map = [];
    foreach (available_payment_methods() as $method) {
        $map[$method['code']] = $method['label'];
    }
    
    foreach (payment_gateway_legacy_method_aliases() as $legacy_code => $canonical_code) {
        if (isset($map[$canonical_code])) {
            $map[$legacy_code] = $map[$canonical_code];
        }
    }

    return $map;
}
function payment_status_transitions(): array {
    return [
        'unpaid' => ['pending_verification', 'partially_paid', 'cancelled', 'failed'],
        'pending_verification' => ['partially_paid', 'paid', 'failed', 'cancelled'],
        'partially_paid' => ['pending_verification', 'paid', 'failed', 'cancelled', 'refunded'],
        'paid' => ['refunded', 'failed'],
        'failed' => ['pending_verification', 'cancelled'],
        'refunded' => [],
        'cancelled' => [],
    ];
}

function payment_statuses(): array {
    return ['unpaid', 'pending_verification', 'partially_paid', 'paid', 'failed', 'refunded', 'cancelled'];
}

function normalize_payment_status(string $status): string {
    $normalized = strtolower(trim($status));
    $aliases = [
        'pending' => 'pending_verification',
        'verified' => 'paid',
        'rejected' => 'failed',
        'refund_pending' => 'pending_verification',
    ];

    $resolved = $aliases[$normalized] ?? $normalized;
    return in_array($resolved, payment_statuses(), true) ? $resolved : 'unpaid';
}

function payment_status_label(string $status): string {
    return ucwords(str_replace('_', ' ', normalize_payment_status($status)));
}

function payment_release_status_transitions(): array {
    return [
        'none' => ['awaiting_confirmation', 'held', 'refunded'],
        'awaiting_confirmation' => ['none', 'held', 'refunded'],
        'held' => ['release_pending', 'released', 'refunded'],
        'release_pending' => ['held', 'released', 'refunded'],
        'released' => ['refunded'],
        'refunded' => [],
    ];
}

function can_transition_payment_release_status(string $current, string $next): bool {
    if($current === $next) {
        return true;
    }

    $transitions = payment_release_status_transitions();
    return in_array($next, $transitions[$current] ?? [], true);
}

function payment_release_status_label(string $status): string {
    return match (strtolower($status)) {
        'awaiting_confirmation' => 'Awaiting confirmation',
        'held' => 'Held for fulfillment',
        'release_pending' => 'Ready for release',
        'released' => 'Released to shop',
        'refunded' => 'Refunded',
        default => 'Not funded',
    };
}

function can_transition_payment_status(string $current, string $next): bool {
    if ($current === $next) {
        return true;
    }

    $transitions = payment_status_transitions();
    return in_array($next, $transitions[$current] ?? [], true);
}

function has_pending_refund_request(PDO $pdo, int $order_id): bool {
    if($order_id <= 0) {
        return false;
    }

    $stmt = $pdo->prepare("\n        SELECT 1
        FROM payment_refunds
        WHERE order_id = ? AND status = 'pending'
        LIMIT 1
    ");
    $stmt->execute([$order_id]);

    return (bool) $stmt->fetchColumn();
}

function generate_invoice_number(string $order_number): string {
    return 'INV-' . $order_number;
}

function generate_receipt_number(int $payment_id, string $issued_at): string {
    $date = date('Ymd', strtotime($issued_at));
    return 'RCPT-' . $date . '-' . str_pad((string) $payment_id, 5, '0', STR_PAD_LEFT);
}

function determine_invoice_status(string $order_status, string $payment_status): string {
    $payment_status = normalize_payment_status($payment_status);
    if ($order_status === STATUS_CANCELLED) {
        return $payment_status === 'refunded' ? 'refunded' : 'cancelled';
    }

    if ($payment_status === 'refunded') {
        return 'refunded';
    }

    if ($payment_status === 'paid') {
        return 'paid';
    }

    return 'open';
}

function ensure_order_invoice(PDO $pdo, int $order_id, string $order_number, float $amount, string $status): array {
    $stmt = $pdo->prepare("SELECT * FROM order_invoices WHERE order_id = ?");
    $stmt->execute([$order_id]);
    $invoice = $stmt->fetch();

    if (!$invoice) {
        $invoice_number = generate_invoice_number($order_number);
        $issued_at = date('Y-m-d H:i:s');
        $insert_stmt = $pdo->prepare("
            INSERT INTO order_invoices (order_id, invoice_number, amount, status, issued_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        $insert_stmt->execute([$order_id, $invoice_number, $amount, $status, $issued_at]);

        $stmt = $pdo->prepare("SELECT * FROM order_invoices WHERE order_id = ?");
        $stmt->execute([$order_id]);
        return $stmt->fetch();
    }

    if ($status !== $invoice['status']) {
        $update_stmt = $pdo->prepare("UPDATE order_invoices SET status = ?, updated_at = NOW() WHERE order_id = ?");
        $update_stmt->execute([$status, $order_id]);
        $invoice['status'] = $status;
    }

    return $invoice;
}

function ensure_payment_receipt(PDO $pdo, int $payment_id, int $issued_by, string $issued_at): array {
    $stmt = $pdo->prepare("SELECT * FROM payment_receipts WHERE payment_id = ?");
    $stmt->execute([$payment_id]);
    $receipt = $stmt->fetch();

    if (!$receipt) {
        $receipt_number = generate_receipt_number($payment_id, $issued_at);
        $insert_stmt = $pdo->prepare("
            INSERT INTO payment_receipts (payment_id, receipt_number, issued_by, issued_at)
            VALUES (?, ?, ?, ?)
        ");
        $insert_stmt->execute([$payment_id, $receipt_number, $issued_by, $issued_at]);

        $stmt = $pdo->prepare("SELECT * FROM payment_receipts WHERE payment_id = ?");
        $stmt->execute([$payment_id]);
        return $stmt->fetch();
    }

    return $receipt;
}

function ensure_payments_payment_method_column(PDO $pdo): void {
    if (!table_exists($pdo, 'payments') || column_exists($pdo, 'payments', 'payment_method')) {
        return;
    }

    $positionClause = column_exists($pdo, 'payments', 'proof_file') ? ' AFTER proof_file' : '';
    $pdo->exec("ALTER TABLE payments ADD COLUMN payment_method VARCHAR(50) DEFAULT NULL{$positionClause}");
}

function ensure_payment_lifecycle_columns(PDO $pdo): void {
    if(!table_exists($pdo, 'payments')) {
        return;
    }

    $columns = [
        'payer_user_id' => "INT(11) DEFAULT NULL AFTER client_id",
        'expected_amount' => "DECIMAL(10,2) DEFAULT NULL AFTER shop_id",
        'paid_amount' => "DECIMAL(10,2) DEFAULT NULL AFTER expected_amount",
        'reference_number' => "VARCHAR(100) DEFAULT NULL AFTER payment_method",
        'provider_transaction_id' => "VARCHAR(120) DEFAULT NULL AFTER reference_number",
        'notes' => "TEXT DEFAULT NULL AFTER verified_at",
    ];

    foreach($columns as $column => $definition) {
        if(!column_exists($pdo, 'payments', $column)) {
            $pdo->exec("ALTER TABLE payments ADD COLUMN {$column} {$definition}");
        }
    }

    if(column_exists($pdo, 'payments', 'status')) {
        $pdo->exec("UPDATE payments SET status = 'pending' WHERE status = 'refund_pending'");
        $pdo->exec("UPDATE payments SET status = 'pending' WHERE status = 'pending'");
        $pdo->exec("UPDATE payments SET status = 'paid' WHERE status = 'verified'");
        $pdo->exec("UPDATE payments SET status = 'failed' WHERE status = 'rejected'");
        $pdo->exec("ALTER TABLE payments MODIFY status ENUM('unpaid','pending_verification','partially_paid','paid','failed','refunded','cancelled') DEFAULT 'unpaid'");
        $pdo->exec("UPDATE payments SET status = 'pending_verification' WHERE status = 'pending'");
    }

    if(column_exists($pdo, 'orders', 'payment_status')) {
        $pdo->exec("UPDATE orders SET payment_status = 'pending' WHERE payment_status = 'pending'");
        $pdo->exec("UPDATE orders SET payment_status = 'pending' WHERE payment_status = 'refund_pending'");
        $pdo->exec("UPDATE orders SET payment_status = 'failed' WHERE payment_status = 'rejected'");
        $pdo->exec("ALTER TABLE orders MODIFY payment_status ENUM('unpaid','pending_verification','partially_paid','paid','failed','refunded','cancelled') DEFAULT 'unpaid'");
        $pdo->exec("UPDATE orders SET payment_status = 'pending_verification' WHERE payment_status = 'pending'");
        $pdo->exec("UPDATE orders SET payment_status = 'partially_paid' WHERE payment_status = 'pending_verification' AND payment_verified_at IS NOT NULL");
    }

    if(column_exists($pdo, 'payments', 'client_id') && column_exists($pdo, 'payments', 'payer_user_id')) {
        $pdo->exec("UPDATE payments SET payer_user_id = client_id WHERE payer_user_id IS NULL");
    }

    if(column_exists($pdo, 'payments', 'amount') && column_exists($pdo, 'payments', 'expected_amount')) {
        $pdo->exec("UPDATE payments SET expected_amount = amount WHERE expected_amount IS NULL");
    }

    if(column_exists($pdo, 'payments', 'amount') && column_exists($pdo, 'payments', 'paid_amount')) {
        $pdo->exec("UPDATE payments SET paid_amount = amount WHERE paid_amount IS NULL");
    }
}

function payment_calculate_order_financial_status(float $expected, float $paid, string $record_status): string {
    $status = normalize_payment_status($record_status);
    if(in_array($status, ['failed', 'cancelled', 'refunded'], true)) {
        return $status;
    }

    if($paid <= 0) {
        return $status === 'pending_verification' ? 'pending_verification' : 'unpaid';
    }

    if($expected > 0 && $paid + 0.01 < $expected) {
        return 'partially_paid';
    }

    return $status === 'pending_verification' ? 'pending_verification' : 'paid';
}

function payment_build_webhook_event(string $provider, array $payload): array {
    return [
        'provider' => strtolower(trim($provider)),
        'provider_transaction_id' => (string) ($payload['provider_transaction_id'] ?? $payload['transaction_id'] ?? ''),
        'reference_number' => (string) ($payload['reference_number'] ?? $payload['reference'] ?? ''),
        'paid_amount' => isset($payload['paid_amount']) ? (float) $payload['paid_amount'] : null,
        'status' => normalize_payment_status((string) ($payload['status'] ?? 'pending_verification')),
        'notes' => (string) ($payload['notes'] ?? ''),
        'payload' => $payload,
    ];
}

function ensure_orders_payment_release_columns(PDO $pdo): void {
    if(!table_exists($pdo, 'orders')) {
        return;
    }

    if(!column_exists($pdo, 'orders', 'payment_release_status')) {
        $position_clause = column_exists($pdo, 'orders', 'payment_verified_at')
            ? ' AFTER payment_verified_at'
            : '';
        $pdo->exec("ALTER TABLE orders ADD COLUMN payment_release_status ENUM('none','awaiting_confirmation','held','release_pending','released','refunded') DEFAULT 'none'{$position_clause}");
    }

    if(!column_exists($pdo, 'orders', 'payment_released_at')) {
        $position_clause = column_exists($pdo, 'orders', 'payment_release_status')
            ? ' AFTER payment_release_status'
            : '';
        $pdo->exec("ALTER TABLE orders ADD COLUMN payment_released_at DATETIME DEFAULT NULL{$position_clause}");
    }
}

function ensure_payment_gateway_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_attempts (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        order_id INT(11) NOT NULL,
        payment_id INT(11) DEFAULT NULL,
        client_id INT(11) NOT NULL,
        shop_id INT(11) NOT NULL,
        gateway VARCHAR(50) NOT NULL,
        payment_method VARCHAR(50) DEFAULT NULL,
        amount DECIMAL(10,2) NOT NULL,
        reference_number VARCHAR(120) NOT NULL,
        checkout_url TEXT DEFAULT NULL,
        status ENUM('created','pending','paid','failed','expired','cancelled') NOT NULL DEFAULT 'created',
        expires_at DATETIME DEFAULT NULL,
        gateway_payload_json LONGTEXT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_payment_attempts_order (order_id),
        KEY idx_payment_attempts_payment (payment_id),
        KEY idx_payment_attempts_reference (reference_number),
        KEY idx_payment_attempts_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_status_timeline (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        order_id INT(11) NOT NULL,
        payment_id INT(11) DEFAULT NULL,
        payment_attempt_id INT(11) DEFAULT NULL,
        actor_user_id INT(11) DEFAULT NULL,
        actor_role VARCHAR(30) DEFAULT NULL,
        status VARCHAR(50) NOT NULL,
        notes TEXT DEFAULT NULL,
        payload_json LONGTEXT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_payment_timeline_order (order_id),
        KEY idx_payment_timeline_payment (payment_id),
        KEY idx_payment_timeline_attempt (payment_attempt_id),
        KEY idx_payment_timeline_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    if(column_exists($pdo, 'payments', 'proof_file')) {
        $pdo->exec("ALTER TABLE payments MODIFY proof_file VARCHAR(255) DEFAULT NULL");
    }
}

function payment_record_timeline(PDO $pdo, int $order_id, ?int $payment_id, ?int $attempt_id, string $status, ?int $actor_user_id = null, ?string $actor_role = null, ?string $notes = null, array $payload = []): void {
    if(!table_exists($pdo, 'payment_status_timeline')) {
        return;
    }

    $stmt = $pdo->prepare("INSERT INTO payment_status_timeline (order_id, payment_id, payment_attempt_id, actor_user_id, actor_role, status, notes, payload_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $order_id,
        $payment_id,
        $attempt_id,
        $actor_user_id,
        $actor_role,
        $status,
        $notes,
        !empty($payload) ? json_encode($payload) : null,
    ]);
}

function payment_calculate_status_from_amount(float $expected, float $paid, string $requested_status): string {
    $normalized = normalize_payment_status($requested_status);
    if(in_array($normalized, ['failed', 'cancelled', 'refunded'], true)) {
        return $normalized;
    }
    if($paid <= 0) {
        return 'unpaid';
    }
    if($expected > 0 && $paid + 0.01 < $expected) {
        return 'partially_paid';
    }
    if($normalized === 'pending_verification') {
        return 'pending_verification';
    }
    return 'paid';
}

function payment_hold_status(string $order_status, string $payment_status, ?string $payment_release_status = null): array {
    $normalized_order_status = strtolower($order_status);
    $normalized_payment_status = strtolower($payment_status);
    $normalized_release_status = strtolower((string) $payment_release_status);

    if($normalized_release_status === 'released') {
        return ['label' => 'Released', 'class' => 'hold-released'];
    }

    if($normalized_release_status === 'release_pending') {
        return ['label' => 'Release ready', 'class' => 'hold-ready'];
    }

    if($normalized_release_status === 'held') {
        return ['label' => 'On hold', 'class' => 'hold-active'];
    }

    if($normalized_release_status === 'awaiting_confirmation') {
        return ['label' => 'Awaiting confirmation', 'class' => 'hold-pending'];
    }

    if ($normalized_payment_status === 'unpaid') {
        return ['label' => 'Not funded', 'class' => 'hold-unfunded'];
    }

    if (in_array($normalized_payment_status, ['failed', 'cancelled', 'refunded'], true)) {
        return ['label' => 'Hold released', 'class' => 'hold-released'];
    }

    if ($normalized_order_status === STATUS_COMPLETED && $normalized_payment_status === 'paid') {
        return ['label' => 'Release ready', 'class' => 'hold-ready'];
    }

    if ($normalized_order_status === STATUS_CANCELLED) {
        return ['label' => 'Hold cleared', 'class' => 'hold-cleared'];
    }

    if (in_array($normalized_payment_status, ['pending', 'paid', 'refund_pending'], true)) {
        return ['label' => 'On hold', 'class' => 'hold-active'];
    }

    return ['label' => 'Pending review', 'class' => 'hold-pending'];
}
?>
