<?php
session_start();
require_once '../config/db.php';
require_once '../config/constants.php';
require_once '../includes/media_manager.php';
require_role('client');

$client_id = $_SESSION['user']['id'];
$unread_notifications = fetch_unread_notification_count($pdo, $client_id);
$error = '';
$success = '';
$max_upload_mb = (int) ceil(MAX_FILE_SIZE / (1024 * 1024));

$default_pricing_settings = [
    'base_prices' => [
        'T-shirt Embroidery' => 180,
        'Logo Embroidery' => 160,
        'Cap Embroidery' => 150,
        'Bag Embroidery' => 200,
        'Custom' => 200,
    ],
    'thread_color_pricing' => [
        ['number_of_colors' => 1, 'price' => 0],
        ['number_of_colors' => 2, 'price' => 30],
        ['number_of_colors' => 3, 'price' => 60],
    ],
    'size_pricing' => [
        ['width' => 4, 'length' => 4, 'price' => 120],
        ['width' => 6, 'length' => 6, 'price' => 180],
        ['width' => 8, 'length' => 8, 'price' => 260],
    ],
];

function is_design_image(?string $filename): bool {
    if(!$filename) {
        return false;
    }
    $path = parse_url($filename, PHP_URL_PATH);
    $extension = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));
    return in_array($extension, ALLOWED_IMAGE_TYPES, true);
}

function proof_file_url(?string $proof_file): ?string {
    if(!$proof_file) {
        return null;
    }

    $normalized = ltrim(trim($proof_file), '/');
    if($normalized === '') {
        return null;
    }

    if(str_starts_with($normalized, 'assets/uploads/')) {
        return '../' . $normalized;
    }

    if(str_starts_with($normalized, 'uploads/')) {
        return '../assets/' . $normalized;
    }

    if(str_contains($normalized, '/')) {
        return '../' . $normalized;
    }

    return '../assets/uploads/designs/' . $normalized;
}

function shop_preview_description(?string $description): string {
    $clean = trim((string) $description);
    if($clean === '') {
        return 'No description provided by this shop yet.';
    }

    if(function_exists('mb_strimwidth')) {
        return mb_strimwidth($clean, 0, 120, '...');
    }

    return strlen($clean) > 120 ? substr($clean, 0, 117) . '...' : $clean;
}

function notify_shop_staff(PDO $pdo, int $shop_id, int $order_id, string $type, string $message): void {
    $staff_stmt = $pdo->prepare("SELECT user_id FROM shop_staffs WHERE shop_id = ? AND status = 'active'");
    $staff_stmt->execute([$shop_id]);
    $staff_ids = $staff_stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach($staff_ids as $staff_id) {
        create_notification($pdo, (int) $staff_id, $order_id, $type, $message);
    }
}

function update_quote_details(PDO $pdo, int $order_id, array $payload): void {
    $details_stmt = $pdo->prepare("SELECT quote_details FROM orders WHERE id = ? LIMIT 1");
    $details_stmt->execute([$order_id]);
    $existing_details = $details_stmt->fetchColumn();
    $quote_details = [];

    if(is_string($existing_details) && $existing_details !== '') {
        $decoded = json_decode($existing_details, true);
        if(is_array($decoded)) {
            $quote_details = $decoded;
        }
    }

    $quote_details = array_merge($quote_details, $payload);
    $update_stmt = $pdo->prepare("UPDATE orders SET quote_details = ?, updated_at = NOW() WHERE id = ?");
    $update_stmt->execute([json_encode($quote_details), $order_id]);
}

function decode_quote_details(?string $raw_quote_details): array {
    if(!is_string($raw_quote_details) || trim($raw_quote_details) === '') {
        return [];
    }

    $decoded = json_decode($raw_quote_details, true);
    return is_array($decoded) ? $decoded : [];
}

function resolve_pricing_settings(array $shop, array $defaults): array {
    if(!empty($shop['pricing_settings'])) {
        $decoded = json_decode($shop['pricing_settings'], true);
        if(is_array($decoded)) {
            return array_replace_recursive($defaults, $decoded);
        }
    }

    return $defaults;
}

function resolve_thread_color_charge(int $color_count, array $thread_color_pricing): float {
    if($color_count <= 0 || empty($thread_color_pricing)) {
        return 0.0;
    }

    usort($thread_color_pricing, static fn($a, $b) => ((int) ($a['number_of_colors'] ?? 0)) <=> ((int) ($b['number_of_colors'] ?? 0)));
    $charge = 0.0;
    foreach($thread_color_pricing as $tier) {
        $tier_colors = max(1, (int) ($tier['number_of_colors'] ?? 1));
        $tier_price = max(0, (float) ($tier['price'] ?? 0));
        if($color_count >= $tier_colors) {
            $charge = $tier_price;
            continue;
        }
        break;
    }

    return $charge;
}

function resolve_size_charge(float $width_in, float $height_in, array $size_pricing): float {
    if($width_in <= 0 || $height_in <= 0 || empty($size_pricing)) {
        return 0.0;
    }

    usort($size_pricing, static function($a, $b) {
        $a_area = ((float) ($a['width'] ?? 0)) * ((float) ($a['length'] ?? 0));
        $b_area = ((float) ($b['width'] ?? 0)) * ((float) ($b['length'] ?? 0));
        return $a_area <=> $b_area;
    });

    $area = $width_in * $height_in;
    foreach($size_pricing as $tier) {
        $tier_area = max(0.0, ((float) ($tier['width'] ?? 0)) * ((float) ($tier['length'] ?? 0)));
        $tier_price = max(0.0, (float) ($tier['price'] ?? 0));
        if($area <= $tier_area && $tier_area > 0) {
            return $tier_price;
        }
    }

    $largest_tier = end($size_pricing);
    return max(0.0, (float) ($largest_tier['price'] ?? 0));
}

function estimate_design_quote_from_image(?string $image_path, string $service_type, array $shop_pricing_settings, ?int $fallback_width = null, ?int $fallback_height = null, ?int $fallback_colors = null): array {
    $width = max(0, (int) ($fallback_width ?? 0));
    $height = max(0, (int) ($fallback_height ?? 0));
    $color_count = max(0, (int) ($fallback_colors ?? 0));

    if($image_path && is_file($image_path)) {
        $meta = get_uploaded_image_dimensions($image_path);
        $width = $width > 0 ? $width : (int) ($meta['width_px'] ?? 0);
        $height = $height > 0 ? $height : (int) ($meta['height_px'] ?? 0);

        if($color_count <= 0 && function_exists('imagecreatefromstring')) {
            $raw = @file_get_contents($image_path);
            $img = $raw !== false ? @imagecreatefromstring($raw) : false;
            if($img !== false) {
                $img_width = imagesx($img);
                $img_height = imagesy($img);
                $sample_step_x = max(1, (int) floor($img_width / 120));
                $sample_step_y = max(1, (int) floor($img_height / 120));
                $samples = [];

                for($y = 0; $y < $img_height; $y += $sample_step_y) {
                    for($x = 0; $x < $img_width; $x += $sample_step_x) {
                        $rgb = imagecolorat($img, $x, $y);
                        $r = ($rgb >> 16) & 0xFF;
                        $g = ($rgb >> 8) & 0xFF;
                        $b = $rgb & 0xFF;
                        $bucket = sprintf('%02x%02x%02x', (int) floor($r / 16), (int) floor($g / 16), (int) floor($b / 16));
                        $samples[$bucket] = true;
                    }
                }

                $color_count = count($samples);
                imagedestroy($img);
            }
        }
    }

    if($width <= 0 || $height <= 0) {
        return [
            'estimated_price' => null,
            'width' => $width,
            'height' => $height,
            'color_count' => $color_count,
            'price_components' => null,
        ];
    }

    $area_sq_in = max(1, ($width / 300) * ($height / 300));
    $width_in = max(1, $width / 300);
    $height_in = max(1, $height / 300);
    $base_prices = is_array($shop_pricing_settings['base_prices'] ?? null) ? $shop_pricing_settings['base_prices'] : [];
    $thread_color_pricing = is_array($shop_pricing_settings['thread_color_pricing'] ?? null) ? $shop_pricing_settings['thread_color_pricing'] : [];
    $size_pricing = is_array($shop_pricing_settings['size_pricing'] ?? null) ? $shop_pricing_settings['size_pricing'] : [];

    $base_price = max(0, (float) ($base_prices[$service_type] ?? ($base_prices['Custom'] ?? 180)));
    $size_charge = resolve_size_charge($width_in, $height_in, $size_pricing);
    $color_charge = resolve_thread_color_charge($color_count, $thread_color_pricing);
    $estimated_price = round(($base_price + $size_charge + $color_charge) / 5) * 5;

    return [
        'estimated_price' => (float) $estimated_price,
        'width' => $width,
        'height' => $height,
        'color_count' => $color_count,
        'price_components' => [
            'base_price' => $base_price,
            'size_charge' => $size_charge,
            'color_charge' => $color_charge,
            'area_sq_in' => round($area_sq_in, 2),
        ],
    ];
}

$shops_stmt = $pdo->query("SELECT id, shop_name, shop_description, address, rating FROM shops WHERE status = 'active' ORDER BY rating DESC, total_orders DESC, shop_name ASC");
$shops = $shops_stmt->fetchAll();

$selected_custom_order = null;
$prefill_order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
if($prefill_order_id > 0) {
    $prefill_stmt = $pdo->prepare("SELECT o.id, o.order_number, o.shop_id, o.service_type, o.design_description, o.design_file, o.width_px, o.height_px, o.detected_width_mm, o.detected_height_mm, o.fits_cap_area, o.suggested_width_mm, o.suggested_height_mm, o.scale_ratio, o.client_notes, s.shop_name
        FROM orders o
        JOIN shops s ON s.id = o.shop_id
        WHERE o.id = ? AND o.client_id = ?");
    $prefill_stmt->execute([$prefill_order_id, $client_id]);
    $selected_custom_order = $prefill_stmt->fetch();
}

if(isset($_POST['request_quote'])) {
    $shop_id = (int) ($_POST['shop_id'] ?? 0);
    $service_type = sanitize($_POST['service_type'] ?? '');
    $design_description = sanitize($_POST['design_description'] ?? '');
    $customize_order_id = (int) ($_POST['customize_order_id'] ?? 0);
    $uploaded_width = 0;
    $uploaded_height = 0;
    $uploaded_colors = (int) ($_POST['design_color_count'] ?? 0);
    $uploaded_estimate = (float) ($_POST['estimated_design_price'] ?? 0);
    $detected_width_mm = null;
    $detected_height_mm = null;
    $fits_cap_area = null;
    $suggested_width_mm = null;
    $suggested_height_mm = null;
    $scale_ratio = null;

    if($shop_id <= 0) {
        $error = 'Please select the shop where you want to request design proofing and quotation.';
    } elseif($service_type === '') {
        $error = 'Please choose a service type for quotation.';
    } elseif($design_description === '') {
        $error = 'Please provide your design requirements so the shop can prepare proofing and quotation.';
    }

    $shop_stmt = $pdo->prepare("SELECT id, owner_id, shop_name, pricing_settings FROM shops WHERE id = ? AND status = 'active' LIMIT 1");
    $shop_stmt->execute([$shop_id]);
    $shop = $shop_stmt->fetch();

    if($error === '' && !$shop) {
        $error = 'Selected shop is not available.';
    }

    $uploaded_design_file = null;
    if($error === '' && isset($_FILES['design_file']) && $_FILES['design_file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $allowed_extensions = array_merge(ALLOWED_IMAGE_TYPES, ALLOWED_DOC_TYPES);
        $upload = save_uploaded_media(
            $_FILES['design_file'],
            $allowed_extensions,
            MAX_FILE_SIZE,
            'designs',
            'quote',
            (string) $client_id
        );

        if(!$upload['success']) {
            $error = $upload['error'] === 'File size exceeds the limit.'
                ? 'Uploaded file is too large. Maximum size is ' . $max_upload_mb . 'MB.'
                : 'Unsupported file format. Please upload JPG, PNG, GIF, PDF, DOC, or DOCX.';
        } else {
            $uploaded_design_file = $upload['filename'];
            $uploaded_path = media_upload_dir('designs') . '/' . basename($uploaded_design_file);
            $dimension_data = get_uploaded_image_dimensions($uploaded_path);
            $uploaded_width = (int) ($dimension_data['width_px'] ?? 0);
            $uploaded_height = (int) ($dimension_data['height_px'] ?? 0);
        }
    }

    if($error === '' && !$uploaded_design_file && $customize_order_id > 0) {
        $customized_stmt = $pdo->prepare("SELECT id, design_file, width_px, height_px, detected_width_mm, detected_height_mm, fits_cap_area, suggested_width_mm, suggested_height_mm, scale_ratio FROM orders WHERE id = ? AND client_id = ? LIMIT 1");
        $customized_stmt->execute([$customize_order_id, $client_id]);
        $customized_order = $customized_stmt->fetch();
        if($customized_order) {
            $uploaded_design_file = $customized_order['design_file'] ?: null;
            $uploaded_width = max(0, (int) ($customized_order['width_px'] ?? 0));
            $uploaded_height = max(0, (int) ($customized_order['height_px'] ?? 0));
            $detected_width_mm = isset($customized_order['detected_width_mm']) ? (float) $customized_order['detected_width_mm'] : null;
            $detected_height_mm = isset($customized_order['detected_height_mm']) ? (float) $customized_order['detected_height_mm'] : null;
            $fits_cap_area = isset($customized_order['fits_cap_area']) ? (int) $customized_order['fits_cap_area'] : null;
            $suggested_width_mm = isset($customized_order['suggested_width_mm']) ? (float) $customized_order['suggested_width_mm'] : null;
            $suggested_height_mm = isset($customized_order['suggested_height_mm']) ? (float) $customized_order['suggested_height_mm'] : null;
            $scale_ratio = isset($customized_order['scale_ratio']) ? (float) $customized_order['scale_ratio'] : null;
            if($uploaded_design_file && ($uploaded_width <= 0 || $uploaded_height <= 0) && is_design_image($uploaded_design_file)) {
                $uploaded_path = media_upload_dir('designs') . '/' . basename($uploaded_design_file);
                $dimension_data = get_uploaded_image_dimensions($uploaded_path);
                $uploaded_width = max($uploaded_width, (int) ($dimension_data['width_px'] ?? 0));
                $uploaded_height = max($uploaded_height, (int) ($dimension_data['height_px'] ?? 0));
            }
        }
    }

    if($error === '') {
        $uploaded_design_abs_path = null;
        if($uploaded_design_file && is_design_image($uploaded_design_file)) {
            $uploaded_design_abs_path = media_upload_dir('designs') . '/' . basename($uploaded_design_file);
        }

        $shop_pricing_settings = resolve_pricing_settings($shop, $default_pricing_settings);
        $design_estimate = estimate_design_quote_from_image(
            $uploaded_design_abs_path,
            $service_type,
            $shop_pricing_settings,
            $uploaded_width,
            $uploaded_height,
            $uploaded_colors
        );
        if($design_estimate['estimated_price'] === null && $uploaded_estimate > 0) {
            $design_estimate['estimated_price'] = $uploaded_estimate;
        }

        if (is_cap_service_type($service_type)) {
            $capMeasurement = build_cap_measurements_from_pixels($uploaded_width, $uploaded_height);
            if ($capMeasurement !== null) {
                $detected_width_mm = $capMeasurement['detected_width_mm'];
                $detected_height_mm = $capMeasurement['detected_height_mm'];
                $fits_cap_area = $capMeasurement['fits_cap_area'] ? 1 : 0;
                $suggested_width_mm = $capMeasurement['suggested_width_mm'];
                $suggested_height_mm = $capMeasurement['suggested_height_mm'];
                $scale_ratio = $capMeasurement['scale_ratio'];
            }
        }

        $order_number = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        $quote_details = [
            'requested_from_services' => true,
            'owner_request_status' => 'pending_acceptance',
            'customize_order_id' => $customize_order_id > 0 ? $customize_order_id : null,
            'requested_at' => date('c'),
            'client_estimate' => [
                'estimated_price' => $design_estimate['estimated_price'],
                'width' => $design_estimate['width'],
                'height' => $design_estimate['height'],
                'color_count' => $design_estimate['color_count'],
                'price_components' => $design_estimate['price_components'],
                'source' => $uploaded_design_abs_path ? 'uploaded_design_file' : 'client_input',
                'cap_measurement' => is_cap_service_type($service_type) ? [
                    'detected_width_mm' => $detected_width_mm,
                    'detected_height_mm' => $detected_height_mm,
                    'max_width_mm' => (float) CAP_FRONT_WIDTH_MM,
                    'max_height_mm' => (float) CAP_FRONT_HEIGHT_MM,
                    'fits_cap_area' => $fits_cap_area !== null ? (bool) $fits_cap_area : null,
                    'suggested_width_mm' => $suggested_width_mm,
                    'suggested_height_mm' => $suggested_height_mm,
                    'scale_ratio' => $scale_ratio,
                ] : null,
            ],
        ];

        $insert_stmt = $pdo->prepare("INSERT INTO orders (
                order_number, client_id, shop_id, service_type, design_description,
                quantity, price, client_notes, quote_details, design_file, width_px, height_px, detected_width_mm, detected_height_mm, fits_cap_area, suggested_width_mm, suggested_height_mm, scale_ratio, status, design_approved
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 0)");

        $insert_stmt->execute([
            $order_number,
            $client_id,
            $shop_id,
            $service_type,
            $design_description,
            1,
            null,
            'Quote request submitted via Services page.',
            json_encode($quote_details),
            $uploaded_design_file,
            $design_estimate['width'] > 0 ? (int) $design_estimate['width'] : null,
            $design_estimate['height'] > 0 ? (int) $design_estimate['height'] : null,
            $detected_width_mm,
            $detected_height_mm,
            $fits_cap_area,
            $suggested_width_mm,
            $suggested_height_mm,
            $scale_ratio,
        ]);

        $order_id = (int) $pdo->lastInsertId();
        $message = 'New design proofing and quotation request #' . $order_number . ' from ' . ($_SESSION['user']['fullname'] ?? 'a client') . '.';
        if(!empty($shop['owner_id'])) {
            create_notification($pdo, (int) $shop['owner_id'], $order_id, 'order_status', $message);
        }

        notify_shop_staff($pdo, $shop_id, $order_id, 'order_status', $message);
        create_notification($pdo, $client_id, $order_id, 'success', 'Your design proofing and quotation request has been sent to ' . $shop['shop_name'] . '.');
        cleanup_media($pdo);
        if(!empty($design_estimate['estimated_price'])) {
            $success = 'Request submitted! Estimated starting price based on your uploaded design is ₱' . number_format((float) $design_estimate['estimated_price'], 2) . '. The selected shop will confirm the final quotation shortly.';
        } else {
            $success = 'Request submitted! The selected shop will prepare your design proofing and price quotation shortly.';
        }
    }
}

$selected_shop_id = (int) ($_POST['shop_id'] ?? ($selected_custom_order['shop_id'] ?? 0));
$selected_service_type = trim((string) ($_POST['service_type'] ?? ($selected_custom_order['service_type'] ?? 'Custom Embroidery Design')));
$selected_design_description = trim((string) ($_POST['design_description'] ?? ($selected_custom_order['design_description'] ?? '')));

$service_type_options = [
    'Custom Embroidery Design',
    'Logo Embroidery',
    'Uniform Embroidery',
    'Cap Embroidery',
    'Bag Embroidery',
    'Patch Embroidery',
    'Other Embroidery Service',
];

if($selected_service_type !== '' && !in_array($selected_service_type, $service_type_options, true)) {
    $service_type_options[] = $selected_service_type;
}


if(isset($_POST['approve_proof'])) {
    $order_id = (int) ($_POST['order_id'] ?? 0);
    $approval_stmt = $pdo->prepare("
        SELECT o.id, o.order_number, o.shop_id, o.client_id, s.owner_id, s.shop_name,
               da.id as approval_id,
               COALESCE(da.design_file, o.design_file) as proof_file,
               da.status as approval_status
        FROM orders o
        JOIN shops s ON o.shop_id = s.id
        LEFT JOIN design_approvals da ON da.order_id = o.id
        WHERE o.id = ? AND o.client_id = ?
        ORDER BY da.updated_at DESC, da.id DESC
        LIMIT 1
    ");
    $approval_stmt->execute([$order_id, $client_id]);
    $approval = $approval_stmt->fetch();

    if(!$approval) {
        $error = 'Unable to locate the proof for approval.';
    } elseif(empty($approval['proof_file'])) {
        $error = 'There is no proof file to approve yet.';
    } elseif($approval['approval_status'] === 'approved') {
        $error = 'This proof has already been approved.';
    } else {
         if(!empty($approval['approval_id'])) {
            $update_stmt = $pdo->prepare("
            UPDATE design_approvals
            SET status = 'approved', approved_at = NOW(), updated_at = NOW()
            WHERE id = ?
        ");
        $update_stmt->execute([$approval['approval_id']]);
        }

        $order_update = $pdo->prepare("UPDATE orders SET design_approved = 1, updated_at = NOW() WHERE id = ?");
        $order_update->execute([$order_id]);

        $message = sprintf('Design proof approved for order #%s.', $approval['order_number']);
        create_notification($pdo, $client_id, $order_id, 'success', $message);
        if(!empty($approval['owner_id'])) {
            create_notification($pdo, (int) $approval['owner_id'], $order_id, 'order_status', $message);
        }
        notify_shop_staff($pdo, (int) $approval['shop_id'], $order_id, 'order_status', $message);

        $success = 'Thank you! The proof is approved and production can begin.';
    }
}

if(isset($_POST['request_revision'])) {
    $order_id = (int) ($_POST['order_id'] ?? 0);
    $revision_notes = sanitize($_POST['revision_notes'] ?? '');

    $approval_stmt = $pdo->prepare("
        SELECT o.id, o.order_number, o.shop_id, o.client_id, s.owner_id, s.shop_name,
                da.id as approval_id,
               COALESCE(da.design_file, o.design_file) as proof_file,
               da.status as approval_status
        FROM orders o
        JOIN shops s ON o.shop_id = s.id
        LEFT JOIN design_approvals da ON da.order_id = o.id
        WHERE o.id = ? AND o.client_id = ?
        ORDER BY da.updated_at DESC, da.id DESC
        LIMIT 1
    ");
    $approval_stmt->execute([$order_id, $client_id]);
    $approval = $approval_stmt->fetch();

    if(!$approval) {
        $error = 'Unable to locate the proof for revision.';
    } elseif($revision_notes === '') {
        $error = 'Please add revision notes for the shop.';
     } elseif(empty($approval['proof_file'])) {
        $error = 'There is no proof file to revise yet.';
    } else {
        if(!empty($approval['approval_id'])) {
            $update_stmt = $pdo->prepare("
            UPDATE design_approvals
            SET status = 'revision', revision_count = revision_count + 1, customer_notes = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $update_stmt->execute([$revision_notes, $approval['approval_id']]);
        }

        $order_update = $pdo->prepare("
            UPDATE orders
            SET revision_count = revision_count + 1,
                revision_notes = ?,
                revision_requested_at = NOW(),
                design_approved = 0,
                updated_at = NOW()
            WHERE id = ?
        ");
        $order_update->execute([$revision_notes, $order_id]);

        $message = sprintf('Revision requested for order #%s.', $approval['order_number']);
        create_notification($pdo, $client_id, $order_id, 'warning', $message);
        if(!empty($approval['owner_id'])) {
            create_notification($pdo, (int) $approval['owner_id'], $order_id, 'order_status', $message);
        }
        notify_shop_staff($pdo, (int) $approval['shop_id'], $order_id, 'order_status', $message);

        $success = 'Your revision request has been sent to the shop.';
    }
}

if(isset($_POST['reject_proof'])) {
    $order_id = (int) ($_POST['order_id'] ?? 0);
    $rejection_notes = sanitize($_POST['rejection_notes'] ?? '');

     $approval_stmt = $pdo->prepare("\n        SELECT o.id, o.order_number, o.shop_id, o.client_id, s.owner_id, s.shop_name,
               da.id as approval_id,
               COALESCE(da.design_file, o.design_file) as proof_file,
               da.status as approval_status
        FROM orders o
        JOIN shops s ON o.shop_id = s.id
        LEFT JOIN design_approvals da ON da.order_id = o.id
        WHERE o.id = ? AND o.client_id = ?
        ORDER BY da.updated_at DESC, da.id DESC
        LIMIT 1
    ");
    $approval_stmt->execute([$order_id, $client_id]);
    $approval = $approval_stmt->fetch();

    if(!$approval) {
        $error = 'Unable to locate the proof to reject.';
    } elseif($rejection_notes === '') {
        $error = 'Please provide rejection notes for the shop.';
    } elseif(empty($approval['proof_file'])) {
        $error = 'There is no proof file to reject yet.';
    } elseif($approval['approval_status'] === 'approved') {
        $error = 'This proof is already approved and cannot be rejected.';
    } else {
       if(!empty($approval['approval_id'])) {
            $update_stmt = $pdo->prepare("\n            UPDATE design_approvals\n            SET status = 'rejected', customer_notes = ?, updated_at = NOW()\n            WHERE id = ?\n        ");
            $update_stmt->execute([$rejection_notes, $approval['approval_id']]);
        }

        $order_update = $pdo->prepare("\n            UPDATE orders\n            SET design_approved = 0,\n                revision_notes = ?,\n                revision_requested_at = NOW(),\n                updated_at = NOW()\n            WHERE id = ?\n        ");
        $order_update->execute([$rejection_notes, $order_id]);

        $message = sprintf('Design proof rejected for order #%s.', $approval['order_number']);
        create_notification($pdo, $client_id, $order_id, 'warning', $message);
        if(!empty($approval['owner_id'])) {
            create_notification($pdo, (int) $approval['owner_id'], $order_id, 'order_status', $message);
        }
        notify_shop_staff($pdo, (int) $approval['shop_id'], $order_id, 'order_status', $message);

        $success = 'The proof was rejected and sent back to the shop for updates.';
    }
}


if(isset($_POST['accept_price_quote']) || isset($_POST['reject_price_quote']) || isset($_POST['negotiate_price_quote']) || isset($_POST['reject_shop_quote'])) {
    $order_id = (int) ($_POST['order_id'] ?? 0);
    $comment = sanitize($_POST['quote_comment'] ?? '');

    $quote_stmt = $pdo->prepare("SELECT o.id, o.order_number, o.shop_id, o.client_id, s.owner_id, s.shop_name
        FROM orders o
        JOIN shops s ON s.id = o.shop_id
        WHERE o.id = ? AND o.client_id = ?
        LIMIT 1");
    $quote_stmt->execute([$order_id, $client_id]);
    $order_quote = $quote_stmt->fetch();

    if(!$order_quote) {
        $error = 'Unable to locate this quote request.';
    } elseif(isset($_POST['accept_price_quote'])) {
        update_quote_details($pdo, $order_id, [
            'owner_request_status' => 'accepted',
            'owner_request_accepted_at' => date('c'),
            'owner_request_accepted_by' => $_SESSION['user']['fullname'] ?? 'Client',
            'price_quote_status' => 'accepted',
            'price_quote_comment' => $comment !== '' ? $comment : null,
            'price_quote_updated_at' => date('c'),
        ]);
        $message = sprintf('Client accepted the price quote for order #%s.', $order_quote['order_number']);
        if(!empty($order_quote['owner_id'])) {
            create_notification($pdo, (int) $order_quote['owner_id'], $order_id, 'success', $message);
        }
        notify_shop_staff($pdo, (int) $order_quote['shop_id'], $order_id, 'success', $message);
       $success = 'Price quote accepted. This request is now an official order and the shop has been notified.';
    } elseif(isset($_POST['reject_price_quote'])) {
        if($comment === '') {
            $error = 'Please share a reason before rejecting the quoted price.';
        } else {
            update_quote_details($pdo, $order_id, [
                'owner_request_status' => 'rejected',
                'owner_request_rejected_at' => date('c'),
                'price_quote_status' => 'rejected',
                'price_quote_comment' => $comment,
                'price_quote_updated_at' => date('c'),
            ]);
            $message = sprintf('Client rejected the price quote for order #%s.', $order_quote['order_number']);
            if(!empty($order_quote['owner_id'])) {
                create_notification($pdo, (int) $order_quote['owner_id'], $order_id, 'warning', $message);
            }
            notify_shop_staff($pdo, (int) $order_quote['shop_id'], $order_id, 'warning', $message);
            $success = 'Price quote rejected. You may now negotiate or choose another shop.';
        }
    } elseif(isset($_POST['negotiate_price_quote'])) {
        if($comment === '') {
            $error = 'Please add your negotiation recommendation for the shop.';
        } else {
            update_quote_details($pdo, $order_id, [
                'owner_request_status' => 'pending_acceptance',
                'price_quote_status' => 'negotiation_requested',
                'price_quote_comment' => $comment,
                'price_quote_updated_at' => date('c'),
            ]);
            $message = sprintf('Client requested a price negotiation for order #%s.', $order_quote['order_number']);
            if(!empty($order_quote['owner_id'])) {
                create_notification($pdo, (int) $order_quote['owner_id'], $order_id, 'order_status', $message);
            }
            notify_shop_staff($pdo, (int) $order_quote['shop_id'], $order_id, 'order_status', $message);
            $success = 'Negotiation request sent to the shop.';
        }
    } elseif(isset($_POST['reject_shop_quote'])) {
        update_quote_details($pdo, $order_id, [
            'owner_request_status' => 'rejected',
            'owner_request_rejected_at' => date('c'),
            'price_quote_status' => 'shop_rejected',
            'price_quote_comment' => $comment !== '' ? $comment : 'Client opted to select another shop.',
            'price_quote_updated_at' => date('c'),
        ]);
        $success = 'Shop quote rejected. You can submit a new request above and select another shop.';
    }
}

$approvals_stmt = $pdo->prepare("
    SELECT o.id as order_id, o.order_number, o.status as order_status, o.design_approved,
           o.design_version_id,o.design_file as order_design_file,
           o.service_type, o.design_description, o.price, o.quote_details,
           s.shop_name, s.owner_id,
           da.status as approval_status, da.design_file, da.provider_notes, da.revision_count,
           COALESCE(da.updated_at, o.updated_at) as updated_at,
           dv.version_no as design_version_no, dv.preview_file as design_version_preview,
           dv.created_at as design_version_created_at, dp.title as design_project_title
    FROM orders o
    JOIN shops s ON o.shop_id = s.id
    LEFT JOIN design_approvals da ON da.order_id = o.id
    LEFT JOIN design_versions dv ON dv.id = o.design_version_id
    LEFT JOIN design_projects dp ON dp.id = dv.project_id
    WHERE o.client_id = ?
      AND o.status IN ('accepted', 'in_progress')
      AND o.design_approved = 0
      AND (
        (da.id IS NOT NULL AND da.status IN ('pending', 'revision'))
        OR o.design_file IS NOT NULL
      )
    ORDER BY COALESCE(da.updated_at, o.updated_at) DESC
");
$approvals_stmt->execute([$client_id]);
$approvals = $approvals_stmt->fetchAll();

$requests_stmt = $pdo->prepare("
    SELECT o.id, o.order_number, o.service_type, o.design_description, o.design_file, o.status, o.price,
           o.width_px, o.height_px, o.detected_width_mm, o.detected_height_mm, o.fits_cap_area, o.suggested_width_mm, o.suggested_height_mm, o.scale_ratio,         
           o.created_at, o.updated_at, o.design_approved, o.quote_details, s.shop_name
    FROM orders o
    JOIN shops s ON s.id = o.shop_id
    WHERE o.client_id = ?
      AND o.client_notes = 'Quote request submitted via Services page.'
      AND o.design_approved = 0
      AND o.status IN ('pending', 'accepted', 'in_progress')
    ORDER BY o.updated_at DESC, o.created_at DESC
");
$requests_stmt->execute([$client_id]);
$request_history = $requests_stmt->fetchAll();

$client_quote_status_labels = [
    'waiting_owner' => 'Waiting for shop quote format',
    'accepted' => 'Accepted by you',
    'rejected' => 'Rejected by you',
    'negotiation_requested' => 'Negotiation requested',
    'shop_rejected' => 'Shop quote rejected',
];

foreach($request_history as &$request) {
    $quote_details = decode_quote_details($request['quote_details'] ?? null);
    $owner_quote = $quote_details['owner_quote_update'] ?? null;
    if(!is_array($owner_quote)) {
        $owner_quote = null;
    }

    $request['owner_quote'] = $owner_quote;
    $request['owner_request_status'] = $quote_details['owner_request_status'] ?? 'pending_acceptance';
    $request['price_quote_status'] = $quote_details['price_quote_status'] ?? 'waiting_owner';
    $request['price_quote_comment'] = (string) ($quote_details['price_quote_comment'] ?? '');
    $request['can_client_decide'] = $owner_quote !== null && $request['owner_request_status'] !== 'accepted';
    $request['client_quote_status_label'] = $client_quote_status_labels[$request['price_quote_status']] ?? ucfirst(str_replace('_', ' ', $request['price_quote_status']));
    $request['design_file_url'] = proof_file_url($request['design_file'] ?? null);
    $request['design_file_is_image'] = is_design_image($request['design_file'] ?? null);
    $request['is_cap_service'] = is_cap_service_type($request['service_type'] ?? null);
}
unset($request);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Design Proofing &amp; Approval Module - Client</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .proofing-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin: 2rem 0;
        }

        .proof-card {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            background: var(--bg-primary);
            padding: 1.5rem;
        }

        .proof-card img {
            width: 100%;
            height: auto;
            border-radius: var(--radius);
            border: 1px solid var(--gray-200);
            margin-top: 1rem;
        }

        .proof-actions {
            display: grid;
            gap: 0.75rem;
            margin-top: 1rem;
        }
        .quotation-layout {
            display: grid;
            grid-template-columns: 1.15fr .85fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .service-note {
            border: 1px solid #dbeafe;
            background: #eff6ff;
            border-radius: var(--radius);
            padding: 1rem;
        }

        .upload-preview {
            margin-top: 0.75rem;
            border: 1px dashed var(--gray-300);
            border-radius: var(--radius);
            padding: 0.75rem;
            background: var(--bg-secondary);
        }

        .upload-preview-media {
            margin-top: 0.75rem;
            width: 100%;
            max-height: 280px;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            background: #fff;
            object-fit: contain;
        }

        .quote-meta {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 0.75rem;
            background: var(--bg-secondary);
            margin-top: 0.75rem;
        }

        .shop-selection-list {
            display: grid;
            gap: 0.75rem;
        }

        .shop-option {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 0.75rem;
            background: var(--bg-secondary);
        }

        .shop-option.active {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 1px rgba(59, 130, 246, 0.2);
        }

        .request-history {
            margin-top: 2rem;
            display: grid;
            gap: 1rem;
        }

        .request-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            background: var(--bg-primary);
        }

        .request-design-preview {
            margin-top: 0.75rem;
            padding: 0.75rem;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            background: var(--bg-secondary);
        }

        .request-design-preview img {
            width: 100%;
            max-height: 260px;
            object-fit: contain;
            border-radius: var(--radius);
            border: 1px solid var(--gray-200);
            background: #fff;
            margin-top: 0.5rem;
        }

        .editor-draft-card {
            display: none;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            padding: 1rem;
            margin-bottom: 1rem;
            background: var(--bg-secondary);
        }

        .editor-draft-card img {
            width: 100%;
            max-height: 260px;
            object-fit: contain;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius);
            background: #fff;
            margin-bottom: 0.75rem;
        }

        @media(max-width: 900px) {
            .quotation-layout {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
   <?php require_once __DIR__ . '/includes/customer_navbar.php'; ?>
    <div class="container">
        <div class="dashboard-header fade-in">
            <div class="d-flex justify-between align-center">
                <div>
                    <h2>Design Proofing &amp; Approval</h2>
                    <p class="text-muted">Review proofs and approve before production begins.</p>
                </div>
                <span class="badge badge-primary"><i class="fas fa-clipboard-check"></i> Module 9</span>
            </div>
        </div>

        <?php if(!empty($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if(!empty($success)): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

         <div class="quotation-layout">
            <div class="card">
                <div class="card-header">
                    <h3><i class="fas fa-file-signature text-primary"></i> Request Design Proofing &amp; Quote</h3>
                   <p class="text-muted">Use the dropdown fields below to submit design proofing and price quote requests.</p>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="customize_order_id" value="<?php echo (int) ($selected_custom_order['id'] ?? 0); ?>">

                    <div class="form-group">
                         <label>Service Selection</label>
                        <select class="form-control" name="service_type" id="serviceTypeField" required>
                            <?php foreach($service_type_options as $service_option): ?>
                                <option value="<?php echo htmlspecialchars($service_option); ?>" <?php echo $selected_service_type === $service_option ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($service_option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Design Description</label>
                        <textarea class="form-control" id="designDescriptionField" name="design_description" rows="5" required placeholder="Describe the design you want for proofing and price quotation."><?php echo htmlspecialchars($selected_design_description); ?></textarea>
                    </div>

                    <div id="editorDraftCard" class="editor-draft-card">
                        <h4 class="mb-2"><i class="fas fa-wand-magic-sparkles text-primary"></i> Imported design details</h4>
                        <img id="editorDraftImage" src="" alt="Editor design preview">
                        <p class="mb-1" id="editorDraftEstimate"></p>
                        <small class="text-muted" id="editorDraftMeta"></small>
                    </div>

                    <input type="hidden" name="shop_id" value="<?php echo (int) $selected_shop_id; ?>" required>

                    <div class="form-group">
                        <label>Upload Design File (Optional)</label>
                        <input type="file" class="form-control" name="design_file" id="designFileInput" accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx">
                        <input type="hidden" name="design_width" id="designWidthInput" value="0">
                        <input type="hidden" name="design_height" id="designHeightInput" value="0">
                        <input type="hidden" name="design_color_count" id="designColorCountInput" value="0">
                        <input type="hidden" name="estimated_design_price" id="estimatedDesignPriceInput" value="0">
                        <small class="text-muted">Max <?php echo $max_upload_mb; ?>MB.</small>
                        <small class="text-muted" id="detectedDimensionLabel" style="display:block; margin-top:4px;">
                            <?php if(!empty($selected_custom_order['width_px']) && !empty($selected_custom_order['height_px'])): ?>
                                Stored uploaded image dimensions: <?php echo (int) $selected_custom_order['width_px']; ?> × <?php echo (int) $selected_custom_order['height_px']; ?> px
                            <?php else: ?>
                                Uploaded image dimensions are auto-detected after upload.
                            <?php endif; ?>
                        </small>
                        <small class="text-muted" style="display:block; margin-top:4px;">Cap embroidery limit: <?php echo number_format((float) CAP_FRONT_WIDTH_MM, 0); ?> × <?php echo number_format((float) CAP_FRONT_HEIGHT_MM, 0); ?> mm.</small>
                        <div class="upload-preview" id="uploadPreview" style="display:none;"></div>
                        <small class="text-muted" id="uploadEstimateLabel" style="display:none;"></small>
                    </div>

                    <button type="submit" name="request_quote" class="btn btn-primary btn-block">
                        <i class="fas fa-paper-plane"></i> Request Design Proofing and Price Quotation
                    </button>
                </form>
            </div>

            <div class="d-flex flex-column gap-3">
                <?php if($selected_custom_order): ?>
                    <div class="service-note">
                        <h4 class="mb-1"><i class="fas fa-link"></i> From Customize Design</h4>
                        <p class="mb-1">Order: <strong>#<?php echo htmlspecialchars($selected_custom_order['order_number']); ?></strong> from <?php echo htmlspecialchars($selected_custom_order['shop_name']); ?>.</p>
                    </div>
                <?php endif; ?>
                <div class="card">
                    <h4><i class="fas fa-store text-primary"></i> Shop Selection</h4>
                    <p class="text-muted">Choose from active shops. Each includes a quick description to help with your selection.</p>
                    <div class="shop-selection-list" id="shopSelectionList">
                        <?php foreach($shops as $shop): ?>
                            <?php $is_active_shop = $selected_shop_id === (int) $shop['id']; ?>
                            <button
                                type="button"
                                class="shop-option <?php echo $is_active_shop ? 'active' : ''; ?>"
                                data-shop-option
                                data-shop-id="<?php echo (int) $shop['id']; ?>"
                            >
                                <div class="d-flex justify-between align-center">
                                    <strong><?php echo htmlspecialchars($shop['shop_name']); ?></strong>
                                    <span class="badge badge-primary"><?php echo number_format((float) ($shop['rating'] ?? 0), 1); ?> ★</span>
                                </div>
                                <p class="text-muted mb-1"><?php echo htmlspecialchars(shop_preview_description($shop['shop_description'] ?? '')); ?></p>
                                <?php if(!empty($shop['address'])): ?>
                                    <small class="text-muted"><i class="fas fa-location-dot"></i> <?php echo htmlspecialchars($shop['address']); ?></small>
                                <?php endif; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

         <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-clock-rotate-left text-primary"></i> Requested Design Proofing &amp; Price Quotations</h3>
                <p class="text-muted mb-0">These are your submitted requests that are still waiting for approval progress.</p>
            </div>
            <?php if(!empty($request_history)): ?>
                <div class="request-history">
                    <?php foreach($request_history as $request): ?>
                        <div class="request-item">
                            <div class="d-flex justify-between align-center mb-2">
                                <strong>Order #<?php echo htmlspecialchars($request['order_number']); ?></strong>
                                <span class="badge badge-warning"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $request['status']))); ?></span>
                            </div>
                            <p class="mb-1"><i class="fas fa-store"></i> <?php echo htmlspecialchars($request['shop_name']); ?></p>
                            <p class="mb-1"><strong>Service:</strong> <?php echo htmlspecialchars($request['service_type'] ?: 'Custom Embroidery Design'); ?></p>
                            <?php if(!empty($request['is_cap_service'])): ?>
                                <p class="mb-1"><strong>Cap allowed area:</strong> <?php echo number_format((float) CAP_FRONT_WIDTH_MM, 0); ?> × <?php echo number_format((float) CAP_FRONT_HEIGHT_MM, 0); ?> mm</p>
                                <?php if(!empty($request['detected_width_mm']) && !empty($request['detected_height_mm'])): ?>
                                    <p class="mb-1"><strong>Detected design size:</strong> <?php echo number_format((float) $request['detected_width_mm'], 2); ?> × <?php echo number_format((float) $request['detected_height_mm'], 2); ?> mm</p>
                                    <p class="mb-1"><strong>Cap fit:</strong> <?php echo !empty($request['fits_cap_area']) ? 'Fits' : 'Needs scaling'; ?></p>
                                    <?php if(empty($request['fits_cap_area']) && !empty($request['suggested_width_mm']) && !empty($request['suggested_height_mm'])): ?>
                                        <p class="mb-1"><strong>Suggested corrected size:</strong> <?php echo number_format((float) $request['suggested_width_mm'], 2); ?> × <?php echo number_format((float) $request['suggested_height_mm'], 2); ?> mm (<?php echo number_format(((float) ($request['scale_ratio'] ?? 1)) * 100, 1); ?>% scale)</p>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                            <p class="mb-1"><strong>Design request:</strong> <?php echo htmlspecialchars($request['design_description'] ?: 'No design details provided.'); ?></p>
                            <p class="mb-1"><strong>Quoted price:</strong> <?php echo $request['price'] !== null ? '₱' . number_format((float) $request['price'], 2) : 'Waiting for shop quotation'; ?></p>

                            <?php if(!empty($request['design_file_url'])): ?>
                                <div class="request-design-preview">
                                    <p class="mb-0"><strong>Uploaded reference file:</strong></p>
                                    <?php if(!empty($request['design_file_is_image'])): ?>
                                        <img src="<?php echo htmlspecialchars($request['design_file_url']); ?>" alt="Uploaded design reference for order <?php echo htmlspecialchars($request['order_number']); ?>">
                                    <?php else: ?>
                                        <a class="btn btn-sm btn-outline mt-2" href="<?php echo htmlspecialchars($request['design_file_url']); ?>" target="_blank" rel="noopener">
                                            <i class="fas fa-file-arrow-down"></i> View uploaded file
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if(!empty($request['owner_quote'])): ?>
                                <div class="quote-meta">
                                    <p class="mb-1"><strong>Design proofing status:</strong> <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $request['owner_quote']['approval_status'] ?? 'pending'))); ?></p>
                                    <p class="mb-1"><strong>Downpayment:</strong> <?php echo isset($request['owner_quote']['downpayment_percent']) ? number_format((float) $request['owner_quote']['downpayment_percent'], 2) . '%' : 'Not set'; ?></p>
                                    <p class="mb-1"><strong>Timeline:</strong> <?php echo !empty($request['owner_quote']['timeline_days']) ? (int) $request['owner_quote']['timeline_days'] . ' day(s)' : 'Not set'; ?></p>
                                    <?php if(!empty($request['owner_quote']['scope_summary'])): ?>
                                        <p class="mb-1"><strong>Scope:</strong> <?php echo htmlspecialchars($request['owner_quote']['scope_summary']); ?></p>
                                    <?php endif; ?>
                                    <p class="mb-0"><strong>Shop message:</strong> <?php echo htmlspecialchars($request['owner_quote']['owner_message'] ?? 'No message yet.'); ?></p>
                                </div>
                            <?php endif; ?>

                            <div class="quote-meta">
                                <p class="mb-1"><strong>Your response status:</strong> <?php echo htmlspecialchars($request['client_quote_status_label']); ?></p>
                                <?php if($request['price_quote_comment'] !== ''): ?>
                                    <p class="mb-0"><strong>Your latest note:</strong> <?php echo htmlspecialchars($request['price_quote_comment']); ?></p>
                                <?php endif; ?>
                            </div>

                            <?php if($request['can_client_decide']): ?>
                                <form method="POST" class="mt-2">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="order_id" value="<?php echo (int) $request['id']; ?>">
                                    <textarea name="quote_comment" class="form-control mb-2" rows="2" maxlength="500" placeholder="Optional note for accept. Required for reject or negotiate."><?php echo htmlspecialchars($request['price_quote_comment']); ?></textarea>
                                    <div class="d-flex gap-2" style="flex-wrap: wrap;">
                                        <button type="submit" name="accept_price_quote" class="btn btn-sm btn-success"><i class="fas fa-check"></i> Accept Request</button>
                                        <button type="submit" name="negotiate_price_quote" class="btn btn-sm btn-outline"><i class="fas fa-comments"></i> Negotiate</button>
                                        <button type="submit" name="reject_price_quote" class="btn btn-sm btn-danger"><i class="fas fa-times"></i> Reject Request</button>
                                    </div>
                                    <small class="text-muted">Accepting will mark this as an official order.</small>
                                </form>
                            <?php elseif($request['owner_request_status'] === 'accepted'): ?>
                                <p class="text-success mb-0 mt-2"><i class="fas fa-circle-check"></i> This request is now an official order.</p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-muted mb-0">No active requests found. Submit a design proofing request above to get started.</p>
            <?php endif; ?>
        </div>
        <script>
        const designFileInput = document.getElementById('designFileInput');
        const uploadPreview = document.getElementById('uploadPreview');
        const shopOptions = document.querySelectorAll('[data-shop-option]');
        const shopSelect = document.querySelector('[name="shop_id"]');
        const serviceTypeField = document.getElementById('serviceTypeField');
        const designDescriptionField = document.getElementById('designDescriptionField');
        const editorDraftCard = document.getElementById('editorDraftCard');
        const editorDraftImage = document.getElementById('editorDraftImage');
        const editorDraftEstimate = document.getElementById('editorDraftEstimate');
        const editorDraftMeta = document.getElementById('editorDraftMeta');
        const designWidthInput = document.getElementById('designWidthInput');
        const designHeightInput = document.getElementById('designHeightInput');
        const designColorCountInput = document.getElementById('designColorCountInput');
        const estimatedDesignPriceInput = document.getElementById('estimatedDesignPriceInput');
        const uploadEstimateLabel = document.getElementById('uploadEstimateLabel');
        let activePreviewUrl = null;

        function resetEstimateFields() {
            if (designWidthInput) designWidthInput.value = '0';
            if (designHeightInput) designHeightInput.value = '0';
            if (designColorCountInput) designColorCountInput.value = '0';
            if (estimatedDesignPriceInput) estimatedDesignPriceInput.value = '0';
            if (uploadEstimateLabel) {
                uploadEstimateLabel.style.display = 'none';
                uploadEstimateLabel.textContent = '';
            }
        }

        function estimateClientPrice(width, height, colorCount) {
            const areaSqIn = Math.max(1, (width / 300) * (height / 300));
            const basePrice = 180;
            const sizeCharge = areaSqIn * 55;
            const colorCharge = colorCount <= 4 ? 0 : (colorCount <= 8 ? 60 : (colorCount <= 16 ? 120 : 180));
            return Math.round((basePrice + sizeCharge + colorCharge) / 5) * 5;
        }

        function analyzeImageFile(file) {
            if (!file || !file.type.startsWith('image/')) {
                resetEstimateFields();
                return;
            }

            const reader = new FileReader();
            reader.onload = () => {
                const image = new Image();
                image.onload = () => {
                    const width = image.naturalWidth || 0;
                    const height = image.naturalHeight || 0;
                    if (!width || !height) {
                        resetEstimateFields();
                        return;
                    }

                    const canvas = document.createElement('canvas');
                    const sampleWidth = Math.min(width, 160);
                    const sampleHeight = Math.max(1, Math.round((sampleWidth / width) * height));
                    canvas.width = sampleWidth;
                    canvas.height = sampleHeight;
                    const context = canvas.getContext('2d', { willReadFrequently: true });
                    if (!context) {
                        resetEstimateFields();
                        return;
                    }

                    context.drawImage(image, 0, 0, sampleWidth, sampleHeight);
                    const pixels = context.getImageData(0, 0, sampleWidth, sampleHeight).data;
                    const buckets = new Set();

                    for (let i = 0; i < pixels.length; i += 4) {
                        const r = Math.floor(pixels[i] / 16).toString(16);
                        const g = Math.floor(pixels[i + 1] / 16).toString(16);
                        const b = Math.floor(pixels[i + 2] / 16).toString(16);
                        buckets.add(`${r}${g}${b}`);
                    }

                    const colorCount = buckets.size;
                    const estimate = estimateClientPrice(width, height, colorCount);

                    if (designWidthInput) designWidthInput.value = String(width);
                    if (designHeightInput) designHeightInput.value = String(height);
                    if (designColorCountInput) designColorCountInput.value = String(colorCount);
                    if (estimatedDesignPriceInput) estimatedDesignPriceInput.value = String(estimate);
                    if (uploadEstimateLabel) {
                        uploadEstimateLabel.style.display = 'block';
                        uploadEstimateLabel.textContent = `Estimated starting price: ₱${estimate.toFixed(2)} (size: ${width}×${height}px, colors detected: ${colorCount}).`;
                    }
                };

                image.src = String(reader.result || '');
            };

            reader.readAsDataURL(file);
        }

        function refreshUploadPreview() {
            if(!designFileInput || !uploadPreview) return;
            const file = designFileInput.files && designFileInput.files[0];
            
            if (activePreviewUrl) {
                URL.revokeObjectURL(activePreviewUrl);
                activePreviewUrl = null;
            }

            if(!file) {
                uploadPreview.style.display = 'none';
                uploadPreview.innerHTML = '';
                resetEstimateFields();
                return;
            }

            const fileSizeKb = Math.max(1, Math.round(file.size / 1024));
            activePreviewUrl = URL.createObjectURL(file);
            const isImage = file.type.startsWith('image/');
            const isPdf = file.type === 'application/pdf';
            let previewMarkup = '';

            if (isImage) {
                previewMarkup = `<img src="${activePreviewUrl}" alt="Selected image preview" class="upload-preview-media">`;
            } else if (isPdf) {
                previewMarkup = `<iframe src="${activePreviewUrl}" title="Selected PDF preview" class="upload-preview-media"></iframe>`;
            }

            uploadPreview.style.display = 'block';
            uploadPreview.innerHTML = `
                <strong>Selected file:</strong> ${file.name} (${fileSizeKb} KB)
                <div class="mt-2">
                    <a href="${activePreviewUrl}" target="_blank" rel="noopener" class="btn btn-sm btn-outline">
                        <i class="fas fa-eye"></i> View selected file
                    </a>
                    <button type="button" class="btn btn-outline-danger btn-sm" id="removeUploadBtn"><i class="fas fa-trash"></i> Remove selected design</button>
                </div>
                ${previewMarkup}
            `;
            const removeBtn = document.getElementById('removeUploadBtn');
            if(removeBtn) {
                removeBtn.addEventListener('click', () => {
                    designFileInput.value = '';
                    refreshUploadPreview();
                });
            }

            analyzeImageFile(file);
        }

        if(designFileInput) {
            designFileInput.addEventListener('change', refreshUploadPreview);
        }

        if(shopSelect && shopOptions.length > 0) {
            shopOptions.forEach((option) => {
                option.addEventListener('click', () => {
                    const selectedId = option.getAttribute('data-shop-id');
                    shopSelect.value = selectedId;
                    shopOptions.forEach((item) => item.classList.remove('active'));
                    option.classList.add('active');
                });
            });

            shopSelect.addEventListener('change', () => {
                shopOptions.forEach((item) => {
                    item.classList.toggle('active', item.getAttribute('data-shop-id') === shopSelect.value);
                });
            });
        }
        
        (function hydrateProofingDraftFromDesignEditor() {
            const params = new URLSearchParams(window.location.search);
            if (!params.get('from_design_editor')) {
                return;
            }

            const draftRaw = localStorage.getItem('embroider_proofing_quote_draft');
            if (!draftRaw) {
                return;
            }

            try {
                const draft = JSON.parse(draftRaw);

                if (serviceTypeField && draft.service_type) {
                    serviceTypeField.value = draft.service_type;
                }

                if (designDescriptionField && draft.design_description) {
                    designDescriptionField.value = draft.design_description;
                }

                if (editorDraftCard && editorDraftImage && draft.design_preview) {
                    editorDraftCard.style.display = 'block';
                    editorDraftImage.src = draft.design_preview;
                }

                if (editorDraftEstimate && draft.estimated_price_label) {
                    editorDraftEstimate.textContent = `Initial estimated budget: ₱${draft.estimated_price_label} (not the final price).`;
                }

                if (editorDraftMeta && draft.design_details) {
                    editorDraftMeta.textContent = `Canvas: ${draft.design_details.canvas_type || '-'} (${draft.design_details.canvas_color || '-'}) • Placement: ${draft.design_details.placement_method || '-'} • Hoop: ${draft.design_details.hoop_preset || '-'} • Thread: ${draft.design_details.thread_color || '-'} • Elements: ${draft.design_details.total_elements || 0}`;
                }

                localStorage.removeItem('embroider_proofing_quote_draft');
            } catch (error) {
                console.warn('Unable to prefill proofing draft from design editor.', error);
            }
        })();
    </script>
</body>
</html>
