<?php

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
