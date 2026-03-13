<?php
require_once '../config/db.php';

header('Content-Type: application/json');

if($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if(!is_array($payload)) {
    $payload = $_POST;
}

$signature = $_SERVER['HTTP_X_PESOPAY_SIGNATURE'] ?? '';
$webhookSecret = trim((string) getenv('PESOPAY_WEBHOOK_SECRET'));
if($webhookSecret !== '') {
    $expected = hash_hmac('sha256', $raw, $webhookSecret);
    if(!hash_equals($expected, (string) $signature)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid signature']);
        exit();
    }
}

$event = payment_build_webhook_event((string) ($payload['provider'] ?? 'pesopay'), [
    'provider_transaction_id' => $payload['transaction_id'] ?? ($payload['provider_transaction_id'] ?? ''),
    'reference_number' => $payload['reference'] ?? ($payload['reference_number'] ?? ''),
    'paid_amount' => $payload['amount'] ?? ($payload['paid_amount'] ?? null),
    'status' => normalize_gateway_payment_status((string) ($payload['status'] ?? 'pending')),
    'notes' => (string) ($payload['message'] ?? 'Webhook callback received.'),
    'payload' => $payload,
]);

if(($event['reference_number'] ?? '') === '' && ($event['provider_transaction_id'] ?? '') === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Missing gateway references']);
    exit();
}

[$ok, $message] = payment_webhook_apply_event($pdo, $event);
if(!$ok) {
    http_response_code(404);
    echo json_encode(['error' => $message]);
    exit();
}

echo json_encode(['success' => true]);
