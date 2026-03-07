<?php

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
        try {
            $imagick = new Imagick($absolutePath);
            $result = normalize_image_dimension_data([
                'width' => $imagick->getImageWidth(),
                'height' => $imagick->getImageHeight(),
                'mime' => $imagick->getImageMimeType(),
            ]);
            $imagick->clear();
            $imagick->destroy();
            if (!empty($result['width_px']) && !empty($result['height_px'])) {
                return $result;
            }
        } catch (Throwable $e) {
            // Fallback below.
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
            imagedestroy($image);
            return $result;
        }
    }

    return [
        'width_px' => null,
        'height_px' => null,
        'mime_type' => null,
    ];
}
