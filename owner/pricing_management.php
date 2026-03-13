<?php
session_start();

require_once '../config/db.php';
require_once '../config/pricing_helpers.php';
require_once '../includes/media_manager.php';
require_role('owner');

$owner_id = $_SESSION['user']['id'];
$shop_stmt = $pdo->prepare('SELECT * FROM shops WHERE owner_id = ? LIMIT 1');
$shop_stmt->execute([$owner_id]);
$shop = $shop_stmt->fetch(PDO::FETCH_ASSOC);

if (!$shop) {
    header('Location: create_shop.php');
    exit();
}

$success = '';
$error = '';
$pricing_services = [
    'T-shirt Embroidery',
    'Logo Embroidery',
    'Cap Embroidery',
    'Bag Embroidery',
    'Custom',
];
$available_services = $pricing_services;
$default_pricing_settings = [
    'base_prices' => [
        'T-shirt Embroidery' => 180,
        'Logo Embroidery' => 160,
        'Cap Embroidery' => 150,
        'Bag Embroidery' => 200,
        'Custom' => 200,
    ],
    'complexity_multipliers' => [
        'Simple' => 1,
        'Standard' => 1.15,
        'Complex' => 1.35,
    ],
    'rush_fee_percent' => 25,
    'add_ons' => [
        'Metallic Thread' => 50,
        '3D Puff' => 75,
        'Extra Color' => 25,
        'Applique' => 60,
    ],
    'canvas_prices' => [
        ['canvas' => 'Cotton Twill', 'price' => 30],
        ['canvas' => 'Polyester', 'price' => 25],
    ],
    'size_pricing' => [
        ['width' => 4, 'length' => 4, 'price' => 120],
        ['width' => 6, 'length' => 6, 'price' => 180],
        ['width' => 8, 'length' => 8, 'price' => 260],
    ],
    'thread_color_pricing' => [
        ['number_of_colors' => 1, 'price' => 0],
        ['number_of_colors' => 2, 'price' => 30],
        ['number_of_colors' => 3, 'price' => 60],
    ],
    'bulk_pricing' => [
        ['min_qty' => 25, 'discount_percent' => 5],
        ['min_qty' => 50, 'discount_percent' => 10],
    ],
    'quote_formula' => default_embroidery_quote_formula(),
];

function sanitize_pricing_rows(array $rows, string $type): array
{
    $clean = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        if ($type === 'canvas') {
            $canvas = sanitize($row['canvas'] ?? '');
            $price = max(0, (float) ($row['price'] ?? 0));
            if ($canvas === '') {
                continue;
            }
            $clean[] = ['canvas' => $canvas, 'price' => $price];
            continue;
        }

        if ($type === 'size') {
            $width = max(0, (float) ($row['width'] ?? 0));
            $length = max(0, (float) ($row['length'] ?? 0));
            $price = max(0, (float) ($row['price'] ?? 0));
            if ($width <= 0 || $length <= 0) {
                continue;
            }
            $clean[] = [
                'width' => $width,
                'length' => $length,
                'price' => $price,
            ];
            continue;
        }

        if ($type === 'thread_color') {
            $number_of_colors = max(1, (int) ($row['number_of_colors'] ?? 0));
            $price = max(0, (float) ($row['price'] ?? 0));
            if ($number_of_colors <= 0) {
                continue;
            }
            $clean[] = [
                'number_of_colors' => $number_of_colors,
                'price' => $price,
            ];
            continue;
        }

        if ($type === 'bulk') {
            $min_qty = max(1, (int) ($row['min_qty'] ?? 0));
            $discount_percent = min(100, max(0, (float) ($row['discount_percent'] ?? 0)));
            if ($min_qty <= 0 || $discount_percent <= 0) {
                continue;
            }
            $clean[] = [
                'min_qty' => $min_qty,
                'discount_percent' => $discount_percent,
            ];
        }
    }

    return $clean;
}

function build_work_post_description(float $embroidery_width, float $embroidery_height, string $canvas_used, string $description): string
{
    $metadata_lines = [
        'Embroidery Width (in): ' . rtrim(rtrim(number_format($embroidery_width, 2, '.', ''), '0'), '.'),
        'Embroidery Height (in): ' . rtrim(rtrim(number_format($embroidery_height, 2, '.', ''), '0'), '.'),
        'Canvas Used: ' . $canvas_used,
    ];

    $base_description = trim($description);
    if ($base_description !== '') {
        $metadata_lines[] = '';
        $metadata_lines[] = $base_description;
    }

    return implode("\n", $metadata_lines);
}

function parse_work_post_description(?string $description): array
{
    $result = [
        'embroidery_size' => '',
        'embroidery_width' => '',
        'embroidery_height' => '',
        'canvas_used' => '',
        'description' => trim((string) $description),
    ];

    if ($description === null || trim($description) === '') {
        return $result;
    }

    $lines = preg_split('/\r\n|\r|\n/', $description);
    if (!$lines) {
        return $result;
    }

    $first_line = trim($lines[0] ?? '');
    $second_line = trim($lines[1] ?? '');

    if (str_starts_with($first_line, 'Embroidery Width (in): ') && str_starts_with($second_line, 'Embroidery Height (in): ')) {
        $third_line = trim($lines[2] ?? '');
        if (!str_starts_with($third_line, 'Canvas Used: ')) {
            return $result;
        }

        $result['embroidery_width'] = trim(substr($first_line, strlen('Embroidery Width (in): ')));
        $result['embroidery_height'] = trim(substr($second_line, strlen('Embroidery Height (in): ')));
        if ($result['embroidery_width'] !== '' && $result['embroidery_height'] !== '') {
            $result['embroidery_size'] = $result['embroidery_width'] . ' x ' . $result['embroidery_height'] . ' in';
        }
        $result['canvas_used'] = trim(substr($third_line, strlen('Canvas Used: ')));

        $remaining_lines = array_slice($lines, 3);
        if (!empty($remaining_lines) && trim((string) $remaining_lines[0]) === '') {
            array_shift($remaining_lines);
        }
        $result['description'] = trim(implode("\n", $remaining_lines));
        return $result;
    }

    if (str_starts_with($first_line, 'Embroidery Size: ') && str_starts_with($second_line, 'Canvas Used: ')) {
        $result['embroidery_size'] = trim(substr($first_line, strlen('Embroidery Size: ')));
        $result['canvas_used'] = trim(substr($second_line, strlen('Canvas Used: ')));

        $remaining_lines = array_slice($lines, 2);
        if (!empty($remaining_lines) && trim((string) $remaining_lines[0]) === '') {
            array_shift($remaining_lines);
        }
        $result['description'] = trim(implode("\n", $remaining_lines));
    }

    return $result;
}

function resolve_pricing_settings(array $shop, array $defaults): array
{
    if (!empty($shop['pricing_settings'])) {
        $decoded = json_decode($shop['pricing_settings'], true);
        if (is_array($decoded)) {
            return array_replace_recursive($defaults, $decoded);
        }
    }

    return $defaults;
}

$pricing_settings = resolve_pricing_settings($shop, $default_pricing_settings);
$service_settings = $shop['service_settings']
    ? json_decode($shop['service_settings'], true)
    : $available_services;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? 'save_pricing';
        if ($action === 'submit_work_post') {
            $post_title = sanitize($_POST['post_title'] ?? '');
            $post_description = sanitize($_POST['post_description'] ?? '');
            $post_embroidery_width = (float) ($_POST['post_embroidery_width'] ?? 0);
            $post_embroidery_height = (float) ($_POST['post_embroidery_height'] ?? 0);
            $post_canvas_option = sanitize($_POST['post_canvas_option'] ?? '');
            $post_canvas_custom = sanitize($_POST['post_canvas_custom'] ?? '');
            $post_canvas_used = $post_canvas_custom !== '' ? $post_canvas_custom : $post_canvas_option;
            $post_price = (float) ($_POST['post_price'] ?? 0);

            if ($post_title === '') {
                throw new RuntimeException('Work title is required.');
            }
            if ($post_price < 0) {
                throw new RuntimeException('Starting price cannot be negative.');
            }
            if ($post_embroidery_width <= 0 || $post_embroidery_height <= 0) {
                throw new RuntimeException('Embroidery width and height are required and must be greater than zero.');
            }
            if ($post_canvas_used === '') {
                throw new RuntimeException('Canvas used is required.');
            }
            if (empty($_FILES['post_image']['name'])) {
                throw new RuntimeException('Please upload a work image.');
            }

            $upload_result = save_uploaded_media(
                $_FILES['post_image'],
                ['jpg', 'jpeg', 'png', 'webp'],
                MAX_FILE_SIZE,
                'portfolio',
                'work_post',
                (string) $shop['id']
            );

            if (!$upload_result['success']) {
                throw new RuntimeException($upload_result['error']);
            }

            $insert_post_stmt = $pdo->prepare(
                'INSERT INTO shop_portfolio (shop_id, title, description, price, image_path) VALUES (?, ?, ?, ?, ?)'
            );
            $insert_post_stmt->execute([
                $shop['id'],
                $post_title,
                build_work_post_description($post_embroidery_width, $post_embroidery_height, $post_canvas_used, $post_description),
                $post_price,
                $upload_result['path'],
            ]);

            $success = 'Work posted successfully. It is now visible on the client dashboard.';
            throw new RuntimeException('__STOP__');
        }

        $enabled_services = array_values(array_intersect($available_services, $_POST['enabled_services'] ?? []));

        if (empty($enabled_services)) {
            throw new RuntimeException('Please enable at least one service.');
        }

        $canvas_prices = sanitize_pricing_rows($_POST['canvas_prices'] ?? [], 'canvas');
        $size_pricing = sanitize_pricing_rows($_POST['size_pricing'] ?? [], 'size');
        $thread_color_pricing = sanitize_pricing_rows($_POST['thread_color_pricing'] ?? [], 'thread_color');
        $bulk_pricing = sanitize_pricing_rows($_POST['bulk_pricing'] ?? [], 'bulk');

        if (empty($size_pricing)) {
            throw new RuntimeException('Please provide at least one valid size pricing row with width and length.');
        }

        $pricing_settings['canvas_prices'] = $canvas_prices;
        $pricing_settings['size_pricing'] = $size_pricing;
        $pricing_settings['thread_color_pricing'] = $thread_color_pricing;
        $pricing_settings['bulk_pricing'] = $bulk_pricing;

        $base_prices = is_array($_POST['base_prices'] ?? null) ? $_POST['base_prices'] : [];
        foreach ($available_services as $service_name) {
            $submitted_base = isset($base_prices[$service_name]) ? (float) $base_prices[$service_name] : (float) ($pricing_settings['base_prices'][$service_name] ?? 0);
            $pricing_settings['base_prices'][$service_name] = max(0, $submitted_base);
        }

        $submitted_complexity = is_array($_POST['complexity_multipliers'] ?? null) ? $_POST['complexity_multipliers'] : [];
        foreach (['Simple', 'Standard', 'Complex'] as $complexity_level) {
            $value = isset($submitted_complexity[$complexity_level]) ? (float) $submitted_complexity[$complexity_level] : (float) ($pricing_settings['complexity_multipliers'][$complexity_level] ?? 1);
            $pricing_settings['complexity_multipliers'][$complexity_level] = max(1, $value);
        }

        $pricing_settings['rush_fee_percent'] = min(100, max(0, (float) ($_POST['rush_fee_percent'] ?? ($pricing_settings['rush_fee_percent'] ?? 0))));

        $saved_formula = resolve_embroidery_quote_formula($pricing_settings);
        $saved_formula['stitch_rate'] = max(0, (float) ($_POST['quote_formula_stitch_rate'] ?? $saved_formula['stitch_rate']));
        $saved_formula['default_color_rate'] = max(0, (float) ($_POST['quote_formula_default_color_rate'] ?? $saved_formula['default_color_rate']));
        $saved_formula['customization_fee_default'] = max(0, (float) ($_POST['quote_formula_customization_fee_default'] ?? $saved_formula['customization_fee_default']));
        $saved_formula['size_unit'] = (($_POST['quote_formula_size_unit'] ?? $saved_formula['size_unit']) === 'mm') ? 'mm' : 'inch';
        $saved_formula['color_charge_mode'] = (($_POST['quote_formula_color_charge_mode'] ?? $saved_formula['color_charge_mode']) === 'flat') ? 'flat' : 'tier';
        $saved_formula['complexity_scope'] = (($_POST['quote_formula_complexity_scope'] ?? $saved_formula['complexity_scope']) === 'base') ? 'base' : 'all';
        $pricing_settings['quote_formula'] = $saved_formula;

        $update_stmt = $pdo->prepare('UPDATE shops SET service_settings = ?, pricing_settings = ? WHERE id = ?');
        $update_stmt->execute([
            json_encode(array_values($enabled_services)),
            json_encode($pricing_settings),
            $shop['id'],
        ]);

        if (function_exists('log_audit')) {
            log_audit(
                $pdo,
                (int) ($_SESSION['user']['id'] ?? 0),
                $_SESSION['user']['role'] ?? 'owner',
                'shop_pricing_updated',
                'shops',
                (int) $shop['id'],
                [],
                [
                    'enabled_services' => array_values($enabled_services),
                    'quote_formula' => $pricing_settings['quote_formula'] ?? [],
                    'rush_fee_percent' => $pricing_settings['rush_fee_percent'] ?? 0,
                ]
            );
        }

        $shop_stmt->execute([$owner_id]);
        $shop = $shop_stmt->fetch(PDO::FETCH_ASSOC);
        $pricing_settings = resolve_pricing_settings($shop, $default_pricing_settings);
         $service_settings = $shop['service_settings']
            ? json_decode($shop['service_settings'], true)
            : $available_services;

        $success = 'Pricing settings updated. Base prices, quote formula, canvas, size, thread color, and bulk order prices are saved.';
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== '__STOP__') {
            $error = $e->getMessage();
        }
    } catch (PDOException $e) {
        $error = 'Failed to update pricing settings: ' . $e->getMessage();
    }
}

$quote_formula = resolve_embroidery_quote_formula($pricing_settings);

$posts_stmt = $pdo->prepare('SELECT id, title, description, price, image_path, created_at FROM shop_portfolio WHERE shop_id = ? ORDER BY created_at DESC LIMIT 6');
$posts_stmt->execute([$shop['id']]);
$shop_posts = $posts_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pricing Management - <?php echo htmlspecialchars($shop['shop_name']); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .pricing-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
        }
        .pricing-card {
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            background: #fff;
            padding: 14px;
        }
        .pricing-card h5 {
            margin: 0 0 12px;
            color: #1f2937;
        }
        .pricing-helper {
            color: #64748b;
            font-size: 0.85rem;
            }
        .work-post-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, 345px);
            justify-content: center;
            gap: 1rem;
        }
        .work-post-card {
            border: 1px solid #d8e0ef;
            border-radius: 10px;
            background: #fff;
            width: 345px;
            height: 400px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .work-post-card img {
            width: 100%;
            height: 170px;
            object-fit: cover;
            background: #f8fafc;
        }
        .work-post-card-body {
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex: 1;
            min-height: 0;
        }
        .work-post-description {
            overflow: hidden;
            display: -webkit-box;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 3;
            line-clamp: 3;
        }
        .image-preview {
            display: none;
            width: 100%;
            max-width: 220px;
            max-height: 220px;
            object-fit: cover;
            border: 1px solid #d8e0ef;
            border-radius: 8px;
            margin-top: 8px;
            background: #f8fafc;
        }
        .pricing-row-grid {
            display: grid;
            grid-template-columns: 1.3fr 1fr 1fr 1fr auto;
            gap: 10px;
            margin-bottom: 10px;
            align-items: end;
        }
        .pricing-row-grid.canvas-grid {
            grid-template-columns: 1.6fr 1fr auto;
        }
        .pricing-row-grid.bulk-grid {
            grid-template-columns: 1fr 1fr auto;
        }
        .pricing-row-grid.size-grid,
        .pricing-row-grid.thread-grid {
            grid-template-columns: 1fr 1fr 1fr auto;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . "/includes/owner_navbar.php"; ?>

    <div class="container mt-4 mb-4">
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header d-flex justify-between align-center">
                <h3><i class="fas fa-tags"></i> Service Pricing</h3>
                <a href="shop_profile.php" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Back to Profile</a>
            </div>
            <div class="card-body">
                <p class="pricing-helper mb-3">Manage your services and pricing matrix for canvas, embroidery size (width x length), thread color, and bulky orders.</p>

                <form method="POST" action="">
                    <?php echo csrf_field(); ?>
                    <div class="pricing-card mb-3">
                        <h5>Service Availability</h5>
                        <p class="pricing-helper mb-2">Choose which services clients can request from your shop.</p>
                        <div class="pricing-grid">
                            <?php foreach ($available_services as $service): ?>
                                <label style="display:flex;align-items:center;gap:8px;">
                                    <input
                                        type="checkbox"
                                        name="enabled_services[]"
                                        value="<?php echo htmlspecialchars($service); ?>"
                                        <?php echo in_array($service, $service_settings, true) ? 'checked' : ''; ?>
                                    >
                                    <span><?php echo htmlspecialchars($service); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
    
                    <div class="pricing-card mb-3">
                        <h5>Base Price and Quote Formula</h5>
                        <p class="pricing-helper mb-2">Manual base prices remain editable. System quote = base + stitch + color + size + rush + optional customization, with complexity multiplier.</p>
                        <div class="pricing-grid">
                            <?php foreach ($available_services as $service): ?>
                                <div>
                                    <label class="form-label"><?php echo htmlspecialchars($service); ?> Base Price (₱)</label>
                                    <input type="number" name="base_prices[<?php echo htmlspecialchars($service); ?>]" class="form-control" min="0" step="0.01" value="<?php echo htmlspecialchars((string) ($pricing_settings['base_prices'][$service] ?? 0)); ?>">
                                </div>
                            <?php endforeach; ?>
                            <div>
                                <label class="form-label">Simple Complexity Multiplier</label>
                                <input type="number" name="complexity_multipliers[Simple]" class="form-control" min="1" step="0.01" value="<?php echo htmlspecialchars((string) ($pricing_settings['complexity_multipliers']['Simple'] ?? 1)); ?>">
                            </div>
                            <div>
                                <label class="form-label">Standard Complexity Multiplier</label>
                                <input type="number" name="complexity_multipliers[Standard]" class="form-control" min="1" step="0.01" value="<?php echo htmlspecialchars((string) ($pricing_settings['complexity_multipliers']['Standard'] ?? 1.15)); ?>">
                            </div>
                            <div>
                                <label class="form-label">Complex Complexity Multiplier</label>
                                <input type="number" name="complexity_multipliers[Complex]" class="form-control" min="1" step="0.01" value="<?php echo htmlspecialchars((string) ($pricing_settings['complexity_multipliers']['Complex'] ?? 1.35)); ?>">
                            </div>
                            <div>
                                <label class="form-label">Rush Fee (%)</label>
                                <input type="number" name="rush_fee_percent" class="form-control" min="0" max="100" step="0.01" value="<?php echo htmlspecialchars((string) ($pricing_settings['rush_fee_percent'] ?? 0)); ?>">
                            </div>
                            <div>
                                <label class="form-label">Stitch Rate (₱ per stitch)</label>
                                <input type="number" name="quote_formula_stitch_rate" class="form-control" min="0" step="0.0001" value="<?php echo htmlspecialchars((string) ($quote_formula['stitch_rate'] ?? 0)); ?>">
                            </div>
                            <div>
                                <label class="form-label">Default Color Rate (₱)</label>
                                <input type="number" name="quote_formula_default_color_rate" class="form-control" min="0" step="0.01" value="<?php echo htmlspecialchars((string) ($quote_formula['default_color_rate'] ?? 0)); ?>">
                            </div>
                            <div>
                                <label class="form-label">Default Customization Fee (₱)</label>
                                <input type="number" name="quote_formula_customization_fee_default" class="form-control" min="0" step="0.01" value="<?php echo htmlspecialchars((string) ($quote_formula['customization_fee_default'] ?? 0)); ?>">
                            </div>
                            <div>
                                <label class="form-label">Size Tier Unit</label>
                                <select name="quote_formula_size_unit" class="form-control">
                                    <option value="inch" <?php echo ($quote_formula['size_unit'] ?? 'inch') === 'inch' ? 'selected' : ''; ?>>Inches</option>
                                    <option value="mm" <?php echo ($quote_formula['size_unit'] ?? 'inch') === 'mm' ? 'selected' : ''; ?>>Millimeters</option>
                                </select>
                            </div>
                            <div>
                                <label class="form-label">Color Charge Mode</label>
                                <select name="quote_formula_color_charge_mode" class="form-control">
                                    <option value="tier" <?php echo ($quote_formula['color_charge_mode'] ?? 'tier') === 'tier' ? 'selected' : ''; ?>>Use thread color tiers</option>
                                    <option value="flat" <?php echo ($quote_formula['color_charge_mode'] ?? 'tier') === 'flat' ? 'selected' : ''; ?>>Use default color rate per extra color</option>
                                </select>
                            </div>
                            <div>
                                <label class="form-label">Complexity Multiplier Scope</label>
                                <select name="quote_formula_complexity_scope" class="form-control">
                                    <option value="all" <?php echo ($quote_formula['complexity_scope'] ?? 'all') === 'all' ? 'selected' : ''; ?>>Apply to all components</option>
                                    <option value="base" <?php echo ($quote_formula['complexity_scope'] ?? 'all') === 'base' ? 'selected' : ''; ?>>Apply to base price only</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="pricing-card mb-3">
                        <h5>Canvas Pricing</h5>
                        <p class="pricing-helper mb-2">Set additional price per material/canvas type.</p>
                        <div id="canvasPricingRows">
                            <?php foreach (($pricing_settings['canvas_prices'] ?? []) as $index => $row): ?>
                                <div class="pricing-row-grid canvas-grid">
                                    <div>
                                        <label class="form-label">Canvas / Material</label>
                                        <input type="text" name="canvas_prices[<?php echo (int) $index; ?>][canvas]" class="form-control" value="<?php echo htmlspecialchars((string) ($row['canvas'] ?? '')); ?>" placeholder="e.g. Cotton Twill">
                                    </div>
                                    <div>
                                        <label class="form-label">Price (₱)</label>
                                        <input type="number" name="canvas_prices[<?php echo (int) $index; ?>][price]" class="form-control" min="0" step="0.01" value="<?php echo htmlspecialchars((string) ($row['price'] ?? 0)); ?>">
                                    </div>
                                    <button type="button" class="btn btn-outline btn-sm remove-row"><i class="fas fa-trash"></i></button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" id="addCanvasRow" class="btn btn-outline btn-sm"><i class="fas fa-plus"></i> Add Canvas</button>
                    </div>

                    <div class="pricing-card mb-3">
                        <h5>Embroidery Size Pricing (Width x Length)</h5>
                        <p class="pricing-helper mb-2">Define price by size dimensions. At least one row is required.</p>
                        <div id="sizePricingRows">
                            <?php foreach (($pricing_settings['size_pricing'] ?? []) as $index => $row): ?>
                                <div class="pricing-row-grid size-grid">
                                    <div>
                                        <label class="form-label">Width (in)</label>
                                        <input type="number" name="size_pricing[<?php echo (int) $index; ?>][width]" class="form-control" min="0.1" step="0.01" value="<?php echo htmlspecialchars((string) ($row['width'] ?? 0)); ?>">
                                    </div>
                                    <div>
                                        <label class="form-label">Length (in)</label>
                                        <input type="number" name="size_pricing[<?php echo (int) $index; ?>][length]" class="form-control" min="0.1" step="0.01" value="<?php echo htmlspecialchars((string) ($row['length'] ?? 0)); ?>">
                                    </div>
                                    <div>
                                        <label class="form-label">Price (₱)</label>
                                        <input type="number" name="size_pricing[<?php echo (int) $index; ?>][price]" class="form-control" min="0" step="0.01" value="<?php echo htmlspecialchars((string) ($row['price'] ?? 0)); ?>">
                                    </div>
                                    <button type="button" class="btn btn-outline btn-sm remove-row"><i class="fas fa-trash"></i></button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" id="addSizeRow" class="btn btn-outline btn-sm"><i class="fas fa-plus"></i> Add Size Tier</button>
                    </div>

                    <div class="pricing-card mb-3">
                        <h5>Thread Color Pricing</h5>
                        <p class="pricing-helper mb-2">Set additional price based on number of thread colors.</p>
                        <div id="threadColorPricingRows">
                            <?php foreach (($pricing_settings['thread_color_pricing'] ?? []) as $index => $row): ?>
                                <div class="pricing-row-grid thread-grid">
                                    <div>
                                        <label class="form-label">Number of Color</label>
                                        <input type="number" name="thread_color_pricing[<?php echo (int) $index; ?>][number_of_colors]" class="form-control" min="1" step="1" value="<?php echo htmlspecialchars((string) ($row['number_of_colors'] ?? 1)); ?>">
                                    </div>
                                    <div>
                                        <label class="form-label">Price per Number of Color (₱)</label>
                                        <input type="number" name="thread_color_pricing[<?php echo (int) $index; ?>][price]" class="form-control" min="0" step="0.01" value="<?php echo htmlspecialchars((string) ($row['price'] ?? 0)); ?>">
                                    </div>
                                    <button type="button" class="btn btn-outline btn-sm remove-row"><i class="fas fa-trash"></i></button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" id="addThreadColorRow" class="btn btn-outline btn-sm"><i class="fas fa-plus"></i> Add Thread Color Tier</button>
                    </div>

                    <div class="pricing-card mb-3">
                        <h5>Bulk Order Pricing</h5>
                        <p class="pricing-helper mb-2">Set discount for large quantity orders.</p>
                        <div id="bulkPricingRows">
                            <?php foreach (($pricing_settings['bulk_pricing'] ?? []) as $index => $row): ?>
                                <div class="pricing-row-grid bulk-grid">
                                    <div>
                                        <label class="form-label">Minimum Quantity</label>
                                        <input type="number" name="bulk_pricing[<?php echo (int) $index; ?>][min_qty]" class="form-control" min="1" step="1" value="<?php echo htmlspecialchars((string) ($row['min_qty'] ?? 1)); ?>">
                                    </div>
                                    <div>
                                        <label class="form-label">Discount (%)</label>
                                        <input type="number" name="bulk_pricing[<?php echo (int) $index; ?>][discount_percent]" class="form-control" min="0" max="100" step="0.01" value="<?php echo htmlspecialchars((string) ($row['discount_percent'] ?? 0)); ?>">
                                    </div>
                                    <button type="button" class="btn btn-outline btn-sm remove-row"><i class="fas fa-trash"></i></button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" id="addBulkRow" class="btn btn-outline btn-sm"><i class="fas fa-plus"></i> Add Bulk Tier</button>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Pricing & Service Availability
                    </button>
                </form>
                <hr style="margin: 24px 0;">
                <h4><i class="fas fa-image"></i> Post Your Works</h4>
                <p class="pricing-helper mb-3">Add your latest output so clients can discover it from their dashboard.</p>

                <form method="POST" enctype="multipart/form-data" class="pricing-card mb-4">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="submit_work_post">

                    <div class="pricing-grid">
                        <div>
                            <label class="form-label">Work Title *</label>
                            <input type="text" name="post_title" class="form-control" required placeholder="Custom Polo Logo Embroidery">
                        </div>
                        <div>
                            <label class="form-label">Starting Price (₱)</label>
                            <input type="number" name="post_price" class="form-control" min="0" step="0.01" value="0">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Description</label>
                        <textarea name="post_description" class="form-control" rows="3" maxlength="255" placeholder="Share stitch type, fabric, turnaround, or package details."></textarea>
                    </div>

                    <div class="pricing-grid">
                        <div>
                            <label class="form-label">Embroidery Width (in) *</label>
                            <input type="number" name="post_embroidery_width" class="form-control" min="0.1" step="0.01" required placeholder="e.g. 4">
                        </div>
                        <div>
                            <label class="form-label">Embroidery Height (in) *</label>
                            <input type="number" name="post_embroidery_height" class="form-control" min="0.1" step="0.01" required placeholder="e.g. 4">
                        </div>
                    </div>

                    <div class="pricing-grid">
                        <div>
                            <label class="form-label">Canvas Used (Select)</label>
                            <select name="post_canvas_option" class="form-control" id="postCanvasOption">
                                <option value="">Choose a suggested canvas/fabric</option>
                                <optgroup label="Round Neck &amp; V-Neck">
                                    <option value="Round Neck & V-Neck - Cotton (Koton)">Cotton (Koton)</option>
                                    <option value="Round Neck & V-Neck - Tri-blend">Tri-blend</option>
                                    <option value="Round Neck & V-Neck - Dri-fit (Polyester)">Dri-fit (Polyester)</option>
                                </optgroup>
                                <optgroup label="Polo Shirt">
                                    <option value="Polo Shirt - Poly-Cotton">Poly-Cotton</option>
                                    <option value="Polo Shirt - Honeycomb">Honeycomb</option>
                                    <option value="Polo Shirt - Poly-Cotton (Variant 2)">Poly-Cotton (Variant 2)</option>
                                    <option value="Polo Shirt - Dri-fit Mesh">Dri-fit Mesh</option>
                                </optgroup>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Canvas Used (Custom) *</label>
                            <input type="text" name="post_canvas_custom" class="form-control" placeholder="Type a custom canvas/fabric">
                            <small class="pricing-helper">Custom value is used first when provided.</small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Work Image *</label>
                        <input type="file" name="post_image" class="form-control" id="postImageInput" accept=".jpg,.jpeg,.png,.webp" required>
                        <img src="" alt="Image preview" id="postImagePreview" class="image-preview">
                    </div>

                    <div class="text-center mt-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-image"></i> Publish Work Post
                        </button>
                    </div>
                </form>

                <h5>Latest Posted Works</h5>
                <?php if (!empty($shop_posts)): ?>
                    <div class="work-post-grid">
                        <?php foreach ($shop_posts as $post): ?>
                            <?php $post_details = parse_work_post_description($post['description'] ?? null); ?>
                            <div class="work-post-card">
                                <img src="../assets/uploads/<?php echo htmlspecialchars($post['image_path']); ?>" alt="<?php echo htmlspecialchars($post['title']); ?>">
                                <div class="work-post-card-body">
                                    <h5 class="mb-1"><?php echo htmlspecialchars($post['title']); ?></h5>
                                    <small class="text-muted d-block mb-1"><?php echo date('M d, Y', strtotime($post['created_at'])); ?></small>
                                    <p class="mb-1"><strong>₱<?php echo number_format((float) $post['price'], 2); ?></strong></p>
                                    <?php if ($post_details['embroidery_width'] !== ''): ?>
                                        <p class="mb-1"><strong>Embroidery Width:</strong> <?php echo htmlspecialchars($post_details['embroidery_width']); ?> in</p>
                                    <?php endif; ?>
                                    <?php if ($post_details['embroidery_height'] !== ''): ?>
                                        <p class="mb-1"><strong>Embroidery Height:</strong> <?php echo htmlspecialchars($post_details['embroidery_height']); ?> in</p>
                                    <?php endif; ?>
                                    <?php if ($post_details['embroidery_size'] !== '' && $post_details['embroidery_width'] === '' && $post_details['embroidery_height'] === ''): ?>
                                        <p class="mb-1"><strong>Embroidery Size:</strong> <?php echo htmlspecialchars($post_details['embroidery_size']); ?></p>
                                    <?php endif; ?>
                                    <?php if ($post_details['canvas_used'] !== ''): ?>
                                        <p class="mb-1"><strong>Canvas Used:</strong> <?php echo htmlspecialchars($post_details['canvas_used']); ?></p>
                                    <?php endif; ?>
                                    <?php if ($post_details['description'] !== ''): ?>
                                        <p class="text-muted mb-0 work-post-description"><?php echo nl2br(htmlspecialchars($post_details['description'])); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="pricing-helper mb-0">No posted works yet. Publish your first post to appear on the client dashboard.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        (function () {
            function setupRows(config) {
                const container = document.getElementById(config.containerId);
                const addButton = document.getElementById(config.addButtonId);
                if (!container || !addButton) return;

                function bindRemove(button) {
                    button.addEventListener('click', function () {
                        const rows = container.querySelectorAll('.pricing-row-grid');
                        if (config.keepOne && rows.length <= 1) {
                            return;
                        }
                        button.closest('.pricing-row-grid')?.remove();
                    });
                }

                container.querySelectorAll('.remove-row').forEach(bindRemove);

                addButton.addEventListener('click', function () {
                    const index = container.querySelectorAll('.pricing-row-grid').length;
                    const row = document.createElement('div');
                    row.className = `pricing-row-grid ${config.gridClass}`;
                    row.innerHTML = config.template(index);
                    container.appendChild(row);
                    const removeBtn = row.querySelector('.remove-row');
                    if (removeBtn) bindRemove(removeBtn);
                });
            }

            setupRows({
                containerId: 'canvasPricingRows',
                addButtonId: 'addCanvasRow',
                gridClass: 'canvas-grid',
                keepOne: false,
                template: (index) => `
                    <div><label class="form-label">Canvas / Material</label><input type="text" name="canvas_prices[${index}][canvas]" class="form-control" placeholder="e.g. Denim"></div>
                    <div><label class="form-label">Price (₱)</label><input type="number" name="canvas_prices[${index}][price]" class="form-control" min="0" step="0.01" value="0"></div>
                    <button type="button" class="btn btn-outline btn-sm remove-row"><i class="fas fa-trash"></i></button>`
            });

            setupRows({
                containerId: 'sizePricingRows',
                addButtonId: 'addSizeRow',
                gridClass: 'size-grid',
                keepOne: true,
                template: (index) => `
                    <div><label class="form-label">Width (in)</label><input type="number" name="size_pricing[${index}][width]" class="form-control" min="0.1" step="0.01" value="0"></div>
                    <div><label class="form-label">Length (in)</label><input type="number" name="size_pricing[${index}][length]" class="form-control" min="0.1" step="0.01" value="0"></div>
                    <div><label class="form-label">Price (₱)</label><input type="number" name="size_pricing[${index}][price]" class="form-control" min="0" step="0.01" value="0"></div>
                    <button type="button" class="btn btn-outline btn-sm remove-row"><i class="fas fa-trash"></i></button>`
            });

            setupRows({
                containerId: 'threadColorPricingRows',
                addButtonId: 'addThreadColorRow',
                gridClass: 'thread-grid',
                keepOne: false,
                template: (index) => `
                    <div><label class="form-label">Number of Color</label><input type="number" name="thread_color_pricing[${index}][number_of_colors]" class="form-control" min="1" step="1" value="1"></div>
                    <div><label class="form-label">Price per Number of Color (₱)</label><input type="number" name="thread_color_pricing[${index}][price]" class="form-control" min="0" step="0.01" value="0"></div>
                    <button type="button" class="btn btn-outline btn-sm remove-row"><i class="fas fa-trash"></i></button>`
            });

            setupRows({
                containerId: 'bulkPricingRows',
                addButtonId: 'addBulkRow',
                gridClass: 'bulk-grid',
                keepOne: false,
                template: (index) => `
                    <div><label class="form-label">Minimum Quantity</label><input type="number" name="bulk_pricing[${index}][min_qty]" class="form-control" min="1" step="1" value="10"></div>
                    <div><label class="form-label">Discount (%)</label><input type="number" name="bulk_pricing[${index}][discount_percent]" class="form-control" min="0" max="100" step="0.01" value="0"></div>
                    <button type="button" class="btn btn-outline btn-sm remove-row"><i class="fas fa-trash"></i></button>`
            });
            
            const postImageInput = document.getElementById('postImageInput');
            const postImagePreview = document.getElementById('postImagePreview');
            if (postImageInput && postImagePreview) {
                postImageInput.addEventListener('change', function (event) {
                    const file = event.target.files && event.target.files[0];
                    if (!file) {
                        postImagePreview.style.display = 'none';
                        postImagePreview.src = '';
                        return;
                    }

                    const objectUrl = URL.createObjectURL(file);
                    postImagePreview.src = objectUrl;
                    postImagePreview.style.display = 'block';
                    postImagePreview.onload = function () {
                        URL.revokeObjectURL(objectUrl);
                    };
                });
            }
        })();
    </script>

</body>
</html>