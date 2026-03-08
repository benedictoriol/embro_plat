<?php

if (!defined('EMBROIDERY_STITCH_RATE')) {
    define('EMBROIDERY_STITCH_RATE', 0.02);
}

if (!defined('EMBROIDERY_COLOR_RATE')) {
    define('EMBROIDERY_COLOR_RATE', 15.0);
}

if (!defined('EMBROIDERY_ITEM_ADJUSTMENTS')) {
    define('EMBROIDERY_ITEM_ADJUSTMENTS', [
        'cap' => 35.0,
        'cap embroidery' => 35.0,
        'hat' => 35.0,
        'bag' => 20.0,
        'bag embroidery' => 20.0,
        'uniform' => 25.0,
        'jacket' => 40.0,
        'patch' => 10.0,
    ]);
}

function resolve_stitch_complexity_factor(?int $colorCount = null, ?int $elementCount = null, ?float $explicitFactor = null): float {
    if ($explicitFactor !== null && $explicitFactor > 0) {
        return round(max(0.1, $explicitFactor), 2);
    }

    $safeColorCount = max(0, (int) ($colorCount ?? 0));
    $safeElementCount = max(0, (int) ($elementCount ?? 0));

    $factorFromColors = $safeColorCount > 0 ? min(2.2, 1.0 + ($safeColorCount * 0.05)) : 1.0;
    $factorFromElements = $safeElementCount > 0 ? min(1.8, 1.0 + ($safeElementCount * 0.04)) : 1.0;

    return round(max(1.0, ($factorFromColors + $factorFromElements) / 2), 2);
}


function resolve_item_type_adjustment(?string $item_type): float {
    if (!is_string($item_type) || trim($item_type) === '') {
        return 0.0;
    }

    $normalized = strtolower(trim($item_type));
    $adjustments = EMBROIDERY_ITEM_ADJUSTMENTS;
    if (isset($adjustments[$normalized])) {
        return (float) $adjustments[$normalized];
    }

    foreach ($adjustments as $key => $amount) {
        if (str_contains($normalized, (string) $key)) {
            return (float) $amount;
        }
    }

    return 0.0;
}

function calculate_embroidery_price(
    int $stitch_count,
    int $thread_colors,
    float $base_price,
    int $quantity = 1,
    ?string $item_type = null
): array {
    $safeStitches = max(0, $stitch_count);
    $safeColors = max(0, $thread_colors);
    $safeBasePrice = max(0.0, $base_price);
    $safeQuantity = max(1, $quantity);

    $stitchRate = (float) EMBROIDERY_STITCH_RATE;
    $colorRate = (float) EMBROIDERY_COLOR_RATE;
    $itemAdjustment = resolve_item_type_adjustment($item_type);

    $stitchCharge = $safeStitches * $stitchRate;
    $colorCharge = $safeColors * $colorRate;
    $unitPrice = $safeBasePrice + $stitchCharge + $colorCharge + $itemAdjustment;
    $totalPrice = $unitPrice * $safeQuantity;

    return [
        'base_price' => round($safeBasePrice, 2),
        'stitch_count' => $safeStitches,
        'thread_colors' => $safeColors,
        'quantity' => $safeQuantity,
        'item_type' => $item_type,
        'stitch_rate' => $stitchRate,
        'color_rate' => $colorRate,
        'item_type_adjustment' => round($itemAdjustment, 2),
        'stitch_charge' => round($stitchCharge, 2),
        'color_charge' => round($colorCharge, 2),
        'unit_price' => round($unitPrice, 2),
        'total_price' => round($totalPrice, 2),
    ];
}