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
        'description' => 'Pay via PesoPay checkout or transfer, then submit proof for owner verification.',
        'icon' => 'fa-credit-card',
    ];
}

function pesopay_create_checkout_session(array $payload = []): array {
    $reference = $payload['reference'] ?? ('manual_' . uniqid('', true));

    return [
        'success' => false,
        'gateway' => pesopay_gateway_name(),
        'reference' => $reference,
        'checkout_url' => null,
        'status' => 'pending',
        'mode' => 'manual_proof',
        'message' => 'PesoPay checkout session is not enabled yet. Submit payment proof for verification.',
    ];
}

function pesopay_verify_payment_reference(string $reference, array $context = []): array {
    return [
        'success' => $reference !== '',
        'gateway' => pesopay_gateway_name(),
        'reference' => $reference,
        'raw_status' => $reference !== '' ? 'pending' : 'invalid',
        'normalized_status' => pesopay_normalize_payment_status($reference !== '' ? 'pending' : 'invalid'),
    ];
}

function pesopay_normalize_payment_status(string $status): string {
    return match (strtolower(trim($status))) {
        'paid', 'success', 'successful', 'authorized', 'verified', 'complete', 'completed' => 'verified',
        'failed', 'declined', 'cancelled', 'canceled', 'rejected', 'expired', 'invalid' => 'rejected',
        default => 'pending',
    };
}
