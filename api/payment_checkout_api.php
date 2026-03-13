<?php
session_start();
require_once '../config/db.php';

header('Content-Type: application/json');

$userRole = canonicalize_role($_SESSION['user']['role'] ?? null);
if(!isset($_SESSION['user']) || $userRole !== 'client') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

if(!verify_csrf_token($_POST['csrf_token'] ?? null)) {
    http_response_code(419);
    echo json_encode(['error' => 'Invalid session token']);
    exit();
}

$client_id = (int) $_SESSION['user']['id'];
$order_id = (int) ($_POST['order_id'] ?? 0);
$payment_method = canonical_payment_method_code(sanitize($_POST['payment_method'] ?? ''));

$submission_methods = array_column(payment_methods_for_submission(), null, 'code');
if(!isset($submission_methods[$payment_method])) {
    http_response_code(422);
    echo json_encode(['error' => 'Selected payment method is not supported for checkout.']);
    exit();
}

$order_stmt = $pdo->prepare("SELECT o.*, u.email AS client_email FROM orders o LEFT JOIN users u ON u.id = o.client_id WHERE o.id = ? AND o.client_id = ? LIMIT 1");
$order_stmt->execute([$order_id, $client_id]);
$order = $order_stmt->fetch();

if(!$order) {
    http_response_code(404);
    echo json_encode(['error' => 'Order not found']);
    exit();
}

$required_amount = round((float) ($order['price'] ?? 0) * 0.20, 2);
if($required_amount <= 0) {
    http_response_code(422);
    echo json_encode(['error' => 'Order amount is not ready for payment']);
    exit();
}

$reference = strtoupper((string) $order['order_number']) . '-' . date('YmdHis');
$successUrl = rtrim((string) getenv('APP_BASE_URL'), '/') . '/client/track_order.php?order_id=' . $order_id . '&payment=success';
$cancelUrl = rtrim((string) getenv('APP_BASE_URL'), '/') . '/client/track_order.php?order_id=' . $order_id . '&payment=cancelled';
$webhookUrl = rtrim((string) getenv('APP_BASE_URL'), '/') . '/api/payment_webhook.php';

if(payment_manual_proof_enabled() && $payment_method !== active_payment_gateway()) {
    http_response_code(422);
    echo json_encode(['error' => 'Manual proof is enabled only for manual methods.']);
    exit();
}

$session = create_checkout_session([
    'reference' => $reference,
    'amount' => $required_amount,
    'currency' => 'PHP',
    'description' => 'Downpayment for order #' . $order['order_number'],
    'success_url' => $successUrl,
    'cancel_url' => $cancelUrl,
    'webhook_url' => $webhookUrl,
    'customer_email' => $order['client_email'] ?? '',
]);

if(empty($session['success'])) {
    http_response_code(502);
    echo json_encode(['error' => $session['message'] ?? 'Failed to create checkout session']);
    exit();
}

$pdo->beginTransaction();
try {
    $payment_stmt = $pdo->prepare("INSERT INTO payments (order_id, client_id, payer_user_id, shop_id, amount, expected_amount, paid_amount, proof_file, payment_method, reference_number, provider_transaction_id, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, 'pending_verification', ?)");
    $payment_stmt->execute([
        $order_id,
        $client_id,
        $client_id,
        $order['shop_id'],
        $required_amount,
        $required_amount,
        0,
        $payment_method,
        $session['reference'],
        $session['provider_transaction_id'] ?? null,
        'Gateway checkout session created.',
    ]);

    $payment_id = (int) $pdo->lastInsertId();

    if(table_exists($pdo, 'payment_attempts')) {
        $attemptStmt = $pdo->prepare("INSERT INTO payment_attempts (order_id, payment_id, client_id, shop_id, gateway, payment_method, amount, reference_number, checkout_url, status, expires_at, gateway_payload_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)");
        $attemptStmt->execute([
            $order_id,
            $payment_id,
            $client_id,
            $order['shop_id'],
            $session['gateway'] ?? active_payment_gateway(),
            $payment_method,
            $required_amount,
            $session['reference'],
            $session['checkout_url'] ?? null,
            $session['expires_at'] ?? null,
            json_encode($session['raw_response'] ?? []),
        ]);
        $attempt_id = (int) $pdo->lastInsertId();
    } else {
        $attempt_id = null;
    }

    $order_update_stmt = $pdo->prepare("UPDATE orders SET payment_status = 'pending_verification', payment_release_status = 'awaiting_confirmation', payment_released_at = NULL WHERE id = ? AND client_id = ?");
    $order_update_stmt->execute([$order_id, $client_id]);

    payment_record_timeline($pdo, $order_id, $payment_id, $attempt_id, 'checkout_created', $client_id, 'client', 'Gateway checkout started.', $session);
    $pdo->commit();
} catch(Throwable $e) {
    if($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create payment record']);
    exit();
}

automation_sync_payment_hold_state($pdo, $order_id, $client_id, 'client', 'client_gateway_checkout');

echo json_encode([
    'success' => true,
    'checkout_url' => $session['checkout_url'] ?? null,
    'reference' => $session['reference'],
    'status' => $session['status'] ?? 'pending_verification',
    'mode' => $session['mode'] ?? 'redirect',
]);
