<?php

require_once __DIR__ . '/pesopay_helpers.php';

function active_payment_gateway(): string {
    $configured = strtolower(trim((string) getenv('PAYMENT_GATEWAY')));
    return $configured !== '' ? $configured : pesopay_gateway_name();
}

function payment_gateway_method_definition(): array {
    return match (active_payment_gateway()) {
        pesopay_gateway_name() => pesopay_method_definition(),
        default => pesopay_method_definition(),
    };
}

function payment_gateway_legacy_method_aliases(): array {
    return [
        'paymongo' => pesopay_gateway_name(),
        'stripe' => pesopay_gateway_name(),
    ];
}

function canonical_payment_method_code(?string $method_code): string {
    $normalized = strtolower(trim((string) $method_code));
    if ($normalized === '') {
        return '';
    }

    $aliases = payment_gateway_legacy_method_aliases();
    return $aliases[$normalized] ?? $normalized;
}

function create_checkout_session(array $payload = []): array {
    return match (active_payment_gateway()) {
        pesopay_gateway_name() => pesopay_create_checkout_session($payload),
        default => pesopay_create_checkout_session($payload),
    };
}

function verify_payment_reference(string $reference, array $context = []): array {
    return match (active_payment_gateway()) {
        pesopay_gateway_name() => pesopay_verify_payment_reference($reference, $context),
        default => pesopay_verify_payment_reference($reference, $context),
    };
}

function normalize_gateway_payment_status(string $status): string {
    return match (active_payment_gateway()) {
        pesopay_gateway_name() => pesopay_normalize_payment_status($status),
        default => pesopay_normalize_payment_status($status),
    };
}
