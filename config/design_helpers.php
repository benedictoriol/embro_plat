<?php

if (!defined('CAP_FRONT_WIDTH_MM')) {
    define('CAP_FRONT_WIDTH_MM', 120.0);
}

if (!defined('CAP_FRONT_HEIGHT_MM')) {
    define('CAP_FRONT_HEIGHT_MM', 60.0);
}

function normalize_image_dimension_data(array $sizeData): array {
    $width = 0;
    $height = 0;
    $mime = null;

    if (isset($sizeData[0])) {
        $width = (int) $sizeData[0];
    } elseif (isset($sizeData['width'])) {
        $width = (int) $sizeData['width'];
    }

    if (isset($sizeData[1])) {
        $height = (int) $sizeData[1];
    } elseif (isset($sizeData['height'])) {
        $height = (int) $sizeData['height'];
    }

    if (isset($sizeData['mime']) && is_string($sizeData['mime'])) {
        $mime = trim($sizeData['mime']) !== '' ? $sizeData['mime'] : null;
    }

    return [
        'width_px' => $width > 0 ? $width : null,
        'height_px' => $height > 0 ? $height : null,
        'mime_type' => $mime,
    ];
}

function get_uploaded_image_dimensions(string $absolutePath): array {
    if ($absolutePath === '' || !is_file($absolutePath) || !is_readable($absolutePath)) {
        return [
            'width_px' => null,
            'height_px' => null,
            'mime_type' => null,
        ];
    }

    $sizeData = @getimagesize($absolutePath);
    if (is_array($sizeData)) {
        return normalize_image_dimension_data($sizeData);
    }

    if (class_exists('Imagick')) {
        $imagick = null;
        try {
            $imagickClass = 'Imagick';
            $imagick = new $imagickClass($absolutePath);
            $result = normalize_image_dimension_data([
                'width' => $imagick->getImageWidth(),
                'height' => $imagick->getImageHeight(),
                'mime' => $imagick->getImageMimeType(),
            ]);
            if (!empty($result['width_px']) && !empty($result['height_px'])) {
                return $result;
            }
        } catch (\Throwable $e) {
            // Fallback below.
            } finally {
            if (is_object($imagick)) {
                if (method_exists($imagick, 'clear')) {
                    $imagick->clear();
                }
                if (method_exists($imagick, 'destroy')) {
                    $imagick->destroy();
                }
            }
        }
    }

    if (function_exists('imagecreatefromstring')) {
        $raw = @file_get_contents($absolutePath);
        $image = $raw !== false ? @imagecreatefromstring($raw) : false;
        if ($image !== false) {
            $result = normalize_image_dimension_data([
                'width' => imagesx($image),
                'height' => imagesy($image),
            ]);
            if (function_exists('imagedestroy')) {
            }
            return $result;
        }
    }

    return [
        'width_px' => null,
        'height_px' => null,
        'mime_type' => null,
    ];
}

function detect_uploaded_file_mime_type(string $absolutePath): ?string {
    if ($absolutePath === '' || !is_file($absolutePath) || !is_readable($absolutePath)) {
        return null;
    }

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = finfo_file($finfo, $absolutePath);
            $finfo = null;
            if (is_string($mime) && trim($mime) !== '') {
                return trim($mime);
            }
        }
    }

    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($absolutePath);
        if (is_string($mime) && trim($mime) !== '') {
            return trim($mime);
        }
    }

    return null;
}

function px_to_mm_estimate(int $px, float $dpi = 96): float {
    if ($px <= 0 || $dpi <= 0) {
        return 0.0;
    }

    return round(($px / $dpi) * 25.4, 2);
}

function suggest_cap_scale(float $designWidthMm, float $designHeightMm, float $maxWidthMm, float $maxHeightMm): array {
    $safeWidth = max(0.0, $designWidthMm);
    $safeHeight = max(0.0, $designHeightMm);
    $safeMaxWidth = max(0.0, $maxWidthMm);
    $safeMaxHeight = max(0.0, $maxHeightMm);

    if ($safeWidth <= 0 || $safeHeight <= 0 || $safeMaxWidth <= 0 || $safeMaxHeight <= 0) {
        return [
            'suggested_width_mm' => round($safeWidth, 2),
            'suggested_height_mm' => round($safeHeight, 2),
            'scale_ratio' => 1.0,
        ];
    }

    $widthRatio = $safeMaxWidth / $safeWidth;
    $heightRatio = $safeMaxHeight / $safeHeight;
    $scaleRatio = min(1.0, $widthRatio, $heightRatio);

    return [
        'suggested_width_mm' => round($safeWidth * $scaleRatio, 2),
        'suggested_height_mm' => round($safeHeight * $scaleRatio, 2),
        'scale_ratio' => round($scaleRatio, 4),
    ];
}

function compute_cap_fit(float $designWidthMm, float $designHeightMm): array {
    $maxWidth = (float) CAP_FRONT_WIDTH_MM;
    $maxHeight = (float) CAP_FRONT_HEIGHT_MM;
    $safeWidth = max(0.0, $designWidthMm);
    $safeHeight = max(0.0, $designHeightMm);
    $fits = $safeWidth > 0
        && $safeHeight > 0
        && $safeWidth <= $maxWidth
        && $safeHeight <= $maxHeight;

    $suggestion = suggest_cap_scale($safeWidth, $safeHeight, $maxWidth, $maxHeight);

    return [
        'detected_width_mm' => round($safeWidth, 2),
        'detected_height_mm' => round($safeHeight, 2),
        'max_width_mm' => round($maxWidth, 2),
        'max_height_mm' => round($maxHeight, 2),
        'fits_cap_area' => $fits,
        'suggested_width_mm' => $suggestion['suggested_width_mm'],
        'suggested_height_mm' => $suggestion['suggested_height_mm'],
        'scale_ratio' => $suggestion['scale_ratio'],
    ];
}

function is_cap_service_type(?string $serviceType): bool {
    if (!is_string($serviceType) || trim($serviceType) === '') {
        return false;
    }

    return str_contains(strtolower($serviceType), 'cap');
}

function build_cap_measurements_from_pixels(?int $widthPx, ?int $heightPx, float $dpi = 96): ?array {
    $safeWidthPx = max(0, (int) ($widthPx ?? 0));
    $safeHeightPx = max(0, (int) ($heightPx ?? 0));
    if ($safeWidthPx <= 0 || $safeHeightPx <= 0) {
        return null;
    }

    $widthMm = px_to_mm_estimate($safeWidthPx, $dpi);
    $heightMm = px_to_mm_estimate($safeHeightPx, $dpi);
    return compute_cap_fit($widthMm, $heightMm);
}

function estimate_stitch_count(float $width_mm, float $height_mm, float $complexity_factor = 1.0): array {
    $safeWidthMm = max(0.0, $width_mm);
    $safeHeightMm = max(0.0, $height_mm);
    $safeComplexity = max(0.1, $complexity_factor);
    $designArea = $safeWidthMm * $safeHeightMm;

    if ($designArea <= 0) {
        return [
            'stitch_count' => 0,
            'thread_colors_estimate' => 0,
            'thread_length_estimate_m' => 0.0,
        ];
    }

    $stitchCount = (int) round(($designArea * $safeComplexity) / 3);
    $threadColorsEstimate = (int) max(1, min(15, round(($safeComplexity * 2.5) + ($designArea / 2800))));
    $threadLengthEstimate = round($stitchCount * 0.003, 2);

    return [
        'stitch_count' => $stitchCount,
        'thread_colors_estimate' => $threadColorsEstimate,
        'thread_length_estimate_m' => $threadLengthEstimate,
    ];
}

function resolve_stitch_pricing_inputs(?array $digitizedDesign = null, ?array $quoteDetails = null, ?int $fallbackWidthPx = null, ?int $fallbackHeightPx = null): array {
    $stitchCount = 0;
    $threadColors = 0;
    $source = 'fallback';

    if (is_array($digitizedDesign)) {
        $stitchCount = max(0, (int) ($digitizedDesign['stitch_count'] ?? 0));
        $threadColors = max(0, (int) ($digitizedDesign['thread_colors'] ?? 0));
        if ($stitchCount > 0 || $threadColors > 0) {
            return [
                'stitch_count' => $stitchCount,
                'thread_colors' => $threadColors,
                'source' => 'digitized_designs',
            ];
        }
    }

    if (is_array($quoteDetails)) {
        $clientEstimate = $quoteDetails['client_estimate']['stitch_estimate'] ?? null;
        if (is_array($clientEstimate)) {
            $stitchCount = max(0, (int) ($clientEstimate['stitch_count'] ?? 0));
            $threadColors = max(0, (int) ($clientEstimate['thread_colors_estimate'] ?? 0));
            if ($stitchCount > 0 || $threadColors > 0) {
                return [
                    'stitch_count' => $stitchCount,
                    'thread_colors' => $threadColors,
                    'source' => 'quote_details',
                ];
            }
        }
    }

    $widthMm = px_to_mm_estimate(max(0, (int) ($fallbackWidthPx ?? 0)));
    $heightMm = px_to_mm_estimate(max(0, (int) ($fallbackHeightPx ?? 0)));
    $estimated = estimate_stitch_count($widthMm, $heightMm, 1.0);

    return [
        'stitch_count' => max(0, (int) ($estimated['stitch_count'] ?? 0)),
        'thread_colors' => max(0, (int) ($estimated['thread_colors_estimate'] ?? 0)),
        'source' => $widthMm > 0 && $heightMm > 0 ? 'dimension_estimate' : $source,
    ];
}

function get_default_placement_zone_config(): array {
    return [
        'tshirt' => [
            [
                'zone_key' => 'left_chest',
                'label' => 'Left Chest',
                'placement_type' => 'embroidery',
                'width_limit' => 110,
                'height_limit' => 110,
                'uv_meta' => ['anchor' => ['x' => 0.38, 'y' => 0.42], 'scale' => 0.2],
                'transform_defaults' => ['x' => 0.38, 'y' => 0.42, 'scale' => 0.2, 'rotation' => 0],
                'active' => true,
            ],
            [
                'zone_key' => 'center_chest',
                'label' => 'Center Chest',
                'placement_type' => 'embroidery',
                'width_limit' => 240,
                'height_limit' => 280,
                'uv_meta' => ['anchor' => ['x' => 0.5, 'y' => 0.45], 'scale' => 0.34],
                'transform_defaults' => ['x' => 0.5, 'y' => 0.45, 'scale' => 0.34, 'rotation' => 0],
                'active' => true,
            ],
            [
                'zone_key' => 'sleeve',
                'label' => 'Sleeve',
                'placement_type' => 'embroidery',
                'width_limit' => 100,
                'height_limit' => 90,
                'uv_meta' => ['anchor' => ['x' => 0.18, 'y' => 0.45], 'scale' => 0.16],
                'transform_defaults' => ['x' => 0.18, 'y' => 0.45, 'scale' => 0.16, 'rotation' => 0.1],
                'active' => true,
            ],
            [
                'zone_key' => 'back',
                'label' => 'Back',
                'placement_type' => 'embroidery',
                'width_limit' => 280,
                'height_limit' => 320,
                'uv_meta' => ['anchor' => ['x' => 0.5, 'y' => 0.5], 'scale' => 0.42],
                'transform_defaults' => ['x' => 0.5, 'y' => 0.5, 'scale' => 0.42, 'rotation' => M_PI],
                'active' => true,
            ],
        ],
        'cap' => [
            [
                'zone_key' => 'cap_front_panel',
                'label' => 'Cap Front Panel',
                'placement_type' => 'patch',
                'width_limit' => 120,
                'height_limit' => 60,
                'uv_meta' => ['anchor' => ['x' => 0.5, 'y' => 0.52], 'scale' => 0.21],
                'transform_defaults' => ['x' => 0.5, 'y' => 0.52, 'scale' => 0.21, 'rotation' => 0],
                'active' => true,
            ],
            [
                'zone_key' => 'cap_side_panel',
                'label' => 'Cap Side Panel',
                'placement_type' => 'embroidery',
                'width_limit' => 70,
                'height_limit' => 50,
                'uv_meta' => ['anchor' => ['x' => 0.73, 'y' => 0.5], 'scale' => 0.14],
                'transform_defaults' => ['x' => 0.73, 'y' => 0.5, 'scale' => 0.14, 'rotation' => -0.4],
                'active' => true,
            ],
        ],
        'bag' => [
            [
                'zone_key' => 'tote_center',
                'label' => 'Tote Center',
                'placement_type' => 'print_preview',
                'width_limit' => 220,
                'height_limit' => 240,
                'uv_meta' => ['anchor' => ['x' => 0.5, 'y' => 0.47], 'scale' => 0.38],
                'transform_defaults' => ['x' => 0.5, 'y' => 0.47, 'scale' => 0.38, 'rotation' => 0],
                'active' => true,
            ],
        ],
    ];
}

function resolve_model_key(string $modelRef): string {
    $clean = strtolower(trim($modelRef));
    if ($clean === '') {
        return 'tshirt';
    }
    if (str_contains($clean, 'cap')) {
        return 'cap';
    }
    if (str_contains($clean, 'bag') || str_contains($clean, 'tote')) {
        return 'bag';
    }
    return 'tshirt';
}

function map_placement_zone_row(array $row): array {
    return [
        'id' => (int) $row['id'],
        'product_id' => isset($row['product_id']) ? (int) $row['product_id'] : null,
        'model_key' => $row['model_key'],
        'zone_key' => $row['zone_key'],
        'label' => $row['label'],
        'placement_type' => $row['placement_type'],
        'width_limit' => (float) $row['width_limit'],
        'height_limit' => (float) $row['height_limit'],
        'uv_meta' => json_decode((string) $row['uv_meta_json'], true) ?: new stdClass(),
        'transform_defaults' => json_decode((string) $row['transform_defaults_json'], true) ?: new stdClass(),
        'active' => (int) $row['active'] === 1,
    ];
}

function list_placement_zones(PDO $pdo, ?int $productId, string $modelRef, ?string $placementType = null): array {
    $modelKey = resolve_model_key($modelRef);
    $placementType = is_string($placementType) ? strtolower(trim($placementType)) : null;

    $sql = 'SELECT * FROM placement_zones WHERE active = 1 AND model_key = ?';
    $params = [$modelKey];

    if ($productId !== null && $productId > 0) {
        $sql .= ' AND (product_id IS NULL OR product_id = ?)';
        $params[] = $productId;
    }

    if ($placementType !== null && $placementType !== '') {
        $sql .= ' AND placement_type = ?';
        $params[] = $placementType;
    }

    $sql .= ' ORDER BY zone_key ASC';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        if (!empty($rows)) {
            return array_map('map_placement_zone_row', $rows);
        }
    } catch (Throwable $e) {
        // table may not be migrated yet; fallback to defaults
    }

    $defaults = get_default_placement_zone_config();
    $zones = $defaults[$modelKey] ?? $defaults['tshirt'];
    if ($placementType !== null && $placementType !== '') {
        $zones = array_values(array_filter($zones, static function (array $zone) use ($placementType): bool {
            return ($zone['placement_type'] ?? '') === $placementType;
        }));
    }

    return array_map(static function (array $zone, int $index) use ($productId, $modelKey): array {
        return [
            'id' => -1 * ($index + 1),
            'product_id' => $productId,
            'model_key' => $modelKey,
            'zone_key' => $zone['zone_key'],
            'label' => $zone['label'],
            'placement_type' => $zone['placement_type'],
            'width_limit' => (float) $zone['width_limit'],
            'height_limit' => (float) $zone['height_limit'],
            'uv_meta' => $zone['uv_meta'],
            'transform_defaults' => $zone['transform_defaults'],
            'active' => (bool) $zone['active'],
        ];
    }, $zones, array_keys($zones));
}
