<?php

function pesopay_gateway_name(): string {
    return 'pesopay';
}

function pesopay_gateway_label(): string {
    return 'PesoPay';
}

function pesopay_credentials(): array {
    return [
        'merchant_id' => trim((string) getenv('PESOPAY_MERCHANT_ID')),
        'access_key' => trim((string) getenv('PESOPAY_ACCESS_KEY')),
        'secret_key' => trim((string) getenv('PESOPAY_SECRET_KEY')),
        'endpoint' => trim((string) getenv('PESOPAY_ENDPOINT')),
        'webhook_secret' => trim((string) getenv('PESOPAY_WEBHOOK_SECRET')),
        'sandbox_mode' => in_array(strtolower(trim((string) getenv('PESOPAY_SANDBOX_MODE'))), ['1', 'true', 'yes', 'on'], true),
    ];
}

function pesopay_is_configured(): bool {
    $credentials = pesopay_credentials();
    return $credentials['merchant_id'] !== '' && $credentials['secret_key'] !== '';
}

function pesopay_method_definition(): array {
    return [
        'code' => pesopay_gateway_name(),
        'label' => pesopay_gateway_label(),
        'description' => 'Pay using PesoPay secure checkout.',
        'icon' => 'fa-credit-card',
    ];
}

function pesopay_sign_payload(array $payload, string $secret): string {
    ksort($payload);
    return hash_hmac('sha256', json_encode($payload), $secret);
}

function pesopay_create_checkout_session(array $payload = []): array {
    $credentials = pesopay_credentials();
    $reference = (string) ($payload['reference'] ?? ('PESO-' . strtoupper(bin2hex(random_bytes(5)))));

    $amount = (float) ($payload['amount'] ?? 0);
    $requestPayload = [
        'merchant_id' => $credentials['merchant_id'],
        'reference' => $reference,
        'amount' => number_format($amount, 2, '.', ''),
        'currency' => $payload['currency'] ?? 'PHP',
        'description' => $payload['description'] ?? ('Order payment ' . $reference),
        'success_url' => $payload['success_url'] ?? '',
        'cancel_url' => $payload['cancel_url'] ?? '',
        'webhook_url' => $payload['webhook_url'] ?? '',
        'customer_email' => $payload['customer_email'] ?? '',
    ];

    if(!empty($credentials['access_key'])) {
        $requestPayload['access_key'] = $credentials['access_key'];
    }

    if($credentials['secret_key'] !== '') {
        $requestPayload['signature'] = pesopay_sign_payload($requestPayload, $credentials['secret_key']);
    }

    if($credentials['endpoint'] !== '' && !$credentials['sandbox_mode']) {
        $ch = curl_init(rtrim($credentials['endpoint'], '/') . '/checkout/session');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($requestPayload),
            CURLOPT_TIMEOUT => 15,
        ]);

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        unset($ch);

        if($raw !== false && $httpCode >= 200 && $httpCode < 300) {
            $decoded = json_decode((string) $raw, true);
            if(is_array($decoded) && !empty($decoded['checkout_url'])) {
                return [
                    'success' => true,
                    'gateway' => pesopay_gateway_name(),
                    'reference' => $reference,
                    'provider_transaction_id' => (string) ($decoded['transaction_id'] ?? ''),
                    'checkout_url' => (string) $decoded['checkout_url'],
                    'status' => 'pending_verification',
                    'mode' => 'redirect',
                    'expires_at' => $decoded['expires_at'] ?? date('Y-m-d H:i:s', strtotime('+30 minutes')),
                    'raw_response' => $decoded,
                ];
            }
        }

        return [
            'success' => false,
            'gateway' => pesopay_gateway_name(),
            'reference' => $reference,
            'checkout_url' => null,
            'status' => 'failed',
            'mode' => 'redirect',
            'message' => $curlErr !== '' ? $curlErr : 'Unable to initialize checkout session.',
        ];
    }

    $query = http_build_query([
        'reference' => $reference,
        'amount' => number_format($amount, 2, '.', ''),
        'status' => 'pending',
    ]);
    $sandboxBase = $credentials['endpoint'] !== '' ? rtrim($credentials['endpoint'], '/') : 'https://sandbox.pesopay.local/checkout';

    return [
        'success' => true,
        'gateway' => pesopay_gateway_name(),
        'reference' => $reference,
        'provider_transaction_id' => '',
        'checkout_url' => $sandboxBase . '?' . $query,
        'status' => 'pending_verification',
        'mode' => 'sandbox_redirect',
        'expires_at' => date('Y-m-d H:i:s', strtotime('+30 minutes')),
        'message' => 'Sandbox checkout session initialized.',
        'raw_response' => ['sandbox' => true],
    ];
}

function pesopay_verify_payment_reference(string $reference, array $context = []): array {
    if($reference === '') {
        return [
            'success' => false,
            'gateway' => pesopay_gateway_name(),
            'reference' => '',
            'raw_status' => 'invalid',
            'normalized_status' => 'failed',
            'message' => 'Missing payment reference.',
        ];
    }

    $status = (string) ($context['status'] ?? 'pending');
    return [
        'success' => true,
        'gateway' => pesopay_gateway_name(),
        'reference' => $reference,
        'raw_status' => $status,
        'normalized_status' => normalize_payment_status(pesopay_normalize_payment_status($status)),
    ];
}

function pesopay_normalize_payment_status(string $status): string {
    return match (strtolower(trim($status))) {
        'paid', 'success', 'successful', 'authorized', 'verified', 'complete', 'completed' => 'paid',
        'failed', 'declined', 'cancelled', 'canceled' => 'failed',
        'expired' => 'cancelled',
        default => 'pending_verification',
    };
}
