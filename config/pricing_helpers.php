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

function default_embroidery_quote_formula(): array {
    return [
        'stitch_rate' => (float) EMBROIDERY_STITCH_RATE,
        'default_color_rate' => (float) EMBROIDERY_COLOR_RATE,
        'color_charge_mode' => 'tier',
        'size_unit' => 'inch',
        'complexity_scope' => 'all',
        'customization_fee_default' => 0.0,
        'version' => 1,
    ];
}

function resolve_embroidery_quote_formula(array $shopPricingSettings): array {
    $defaults = default_embroidery_quote_formula();
    $saved = is_array($shopPricingSettings['quote_formula'] ?? null) ? $shopPricingSettings['quote_formula'] : [];

    $formula = array_merge($defaults, $saved);
    $formula['stitch_rate'] = max(0.0, (float) ($formula['stitch_rate'] ?? $defaults['stitch_rate']));
    $formula['default_color_rate'] = max(0.0, (float) ($formula['default_color_rate'] ?? $defaults['default_color_rate']));
    $formula['customization_fee_default'] = max(0.0, (float) ($formula['customization_fee_default'] ?? 0));
    $formula['size_unit'] = strtolower((string) ($formula['size_unit'] ?? 'inch')) === 'mm' ? 'mm' : 'inch';
    $formula['complexity_scope'] = strtolower((string) ($formula['complexity_scope'] ?? 'all')) === 'base' ? 'base' : 'all';
    $formula['color_charge_mode'] = strtolower((string) ($formula['color_charge_mode'] ?? 'tier')) === 'flat' ? 'flat' : 'tier';

    return $formula;
}

function resolve_quote_size_charge(float $width, float $height, array $sizePricing): float {
    if ($width <= 0 || $height <= 0 || empty($sizePricing)) {
        return 0.0;
    }

    usort($sizePricing, static function ($a, $b) {
        $aArea = ((float) ($a['width'] ?? 0)) * ((float) ($a['length'] ?? 0));
        $bArea = ((float) ($b['width'] ?? 0)) * ((float) ($b['length'] ?? 0));
        return $aArea <=> $bArea;
    });

    $area = $width * $height;
    foreach ($sizePricing as $tier) {
        $tierArea = max(0.0, ((float) ($tier['width'] ?? 0)) * ((float) ($tier['length'] ?? 0)));
        if ($tierArea > 0 && $area <= $tierArea) {
            return max(0.0, (float) ($tier['price'] ?? 0));
        }
    }

    $largestTier = end($sizePricing);
    return max(0.0, (float) ($largestTier['price'] ?? 0));
}

function resolve_quote_thread_color_charge(int $threadColors, array $threadColorPricing, float $defaultColorRate, string $mode = 'tier'): float {
    $safeColors = max(0, $threadColors);
    if ($safeColors <= 0) {
        return 0.0;
    }

    if ($mode === 'flat' || empty($threadColorPricing)) {
        return max(0, $safeColors - 1) * max(0.0, $defaultColorRate);
    }

    usort($threadColorPricing, static fn($a, $b) => ((int) ($a['number_of_colors'] ?? 0)) <=> ((int) ($b['number_of_colors'] ?? 0)));

    $charge = 0.0;
    foreach ($threadColorPricing as $tier) {
        $tierColors = max(1, (int) ($tier['number_of_colors'] ?? 1));
        $tierPrice = max(0.0, (float) ($tier['price'] ?? 0));
        if ($safeColors >= $tierColors) {
            $charge = $tierPrice;
            continue;
        }
        break;
    }

    return $charge;
}

function calculate_embroidery_quote(array $input, array $shopPricingSettings = []): array {
    $formula = resolve_embroidery_quote_formula($shopPricingSettings);

    $basePrice = max(0.0, (float) ($input['base_price'] ?? 0));
    $quantity = max(1, (int) ($input['quantity'] ?? 1));
    $stitchCount = max(0, (int) ($input['stitch_count'] ?? 0));
    $threadColors = max(0, (int) ($input['thread_colors'] ?? 0));
    $serviceType = $input['service_type'] ?? null;

    $widthMm = isset($input['width_mm']) ? max(0.0, (float) $input['width_mm']) : 0.0;
    $heightMm = isset($input['height_mm']) ? max(0.0, (float) $input['height_mm']) : 0.0;
    $sizeWidth = $formula['size_unit'] === 'inch' ? ($widthMm / 25.4) : $widthMm;
    $sizeHeight = $formula['size_unit'] === 'inch' ? ($heightMm / 25.4) : $heightMm;

    $sizePricing = is_array($shopPricingSettings['size_pricing'] ?? null) ? $shopPricingSettings['size_pricing'] : [];
    $threadColorPricing = is_array($shopPricingSettings['thread_color_pricing'] ?? null) ? $shopPricingSettings['thread_color_pricing'] : [];
    $complexityMultipliers = is_array($shopPricingSettings['complexity_multipliers'] ?? null) ? $shopPricingSettings['complexity_multipliers'] : [];

    $complexityLevel = (string) ($input['complexity_level'] ?? 'Simple');
    $complexityMultiplier = max(1.0, (float) ($complexityMultipliers[$complexityLevel] ?? 1.0));
    $rushRequested = !empty($input['rush']);
    $rushPercent = max(0.0, (float) ($shopPricingSettings['rush_fee_percent'] ?? 0));
    $customizationFee = max(0.0, (float) ($input['customization_fee'] ?? $formula['customization_fee_default']));
    $itemTypeAdjustment = resolve_item_type_adjustment(is_string($serviceType) ? $serviceType : null);

    $stitchCharge = $stitchCount * $formula['stitch_rate'];
    $threadColorCharge = resolve_quote_thread_color_charge($threadColors, $threadColorPricing, (float) $formula['default_color_rate'], (string) $formula['color_charge_mode']);
    $sizeCharge = resolve_quote_size_charge($sizeWidth, $sizeHeight, $sizePricing);

    $subTotalBeforeComplexity = $basePrice + $stitchCharge + $threadColorCharge + $sizeCharge + $itemTypeAdjustment + $customizationFee;
    $subTotalAfterComplexity = $formula['complexity_scope'] === 'base'
        ? (($basePrice * $complexityMultiplier) + ($subTotalBeforeComplexity - $basePrice))
        : ($subTotalBeforeComplexity * $complexityMultiplier);
    $rushFee = $rushRequested ? ($subTotalAfterComplexity * ($rushPercent / 100)) : 0.0;

    $unitPrice = $subTotalAfterComplexity + $rushFee;
    $totalPrice = $unitPrice * $quantity;

    return [
        'formula_version' => (int) ($formula['version'] ?? 1),
        'base_price' => round($basePrice, 2),
        'quantity' => $quantity,
        'stitch_count' => $stitchCount,
        'thread_colors' => $threadColors,
        'stitch_rate' => round((float) $formula['stitch_rate'], 4),
        'default_color_rate' => round((float) $formula['default_color_rate'], 2),
        'color_charge_mode' => $formula['color_charge_mode'],
        'stitch_charge' => round($stitchCharge, 2),
        'color_charge' => round($threadColorCharge, 2),
        'size_charge' => round($sizeCharge, 2),
        'width_mm' => round($widthMm, 2),
        'height_mm' => round($heightMm, 2),
        'size_unit' => $formula['size_unit'],
        'complexity_level' => $complexityLevel,
        'complexity_multiplier' => round($complexityMultiplier, 2),
        'complexity_scope' => $formula['complexity_scope'],
        'customization_fee' => round($customizationFee, 2),
        'rush' => $rushRequested,
        'rush_fee_percent' => round($rushPercent, 2),
        'rush_fee_amount' => round($rushFee, 2),
        'item_type' => $serviceType,
        'item_type_adjustment' => round($itemTypeAdjustment, 2),
        'unit_price' => round($unitPrice, 2),
        'total_price' => round($totalPrice, 2),
    ];
}