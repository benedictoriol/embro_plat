<?php
session_start();
require_once '../config/db.php';
require_role('client');

$client_id = $_SESSION['user']['id'];
$unread_notifications = fetch_unread_notification_count($pdo, $client_id);
$error = '';
$success = '';
$available_services = [
    'T-shirt Embroidery',
    'Logo Embroidery',
    'Cap Embroidery',
    'Bag Embroidery',
    'Custom',
];
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
];

function resolve_pricing_settings(array $shop, array $defaults): array {
    if (!empty($shop['pricing_settings'])) {
        $decoded = json_decode($shop['pricing_settings'], true);
        if (is_array($decoded)) {
            return array_replace_recursive($defaults, $decoded);
        }
    }

    return $defaults;
}

function resolve_shop_services(array $shop, array $available_services): array {
    if (!empty($shop['service_settings'])) {
        $decoded = json_decode($shop['service_settings'], true);
        if (is_array($decoded)) {
            return array_values(array_intersect($available_services, $decoded));
        }
    }

    return $available_services;
}

function resolve_operating_days(array $shop): array {
    if (!empty($shop['operating_days'])) {
        $decoded = json_decode($shop['operating_days'], true);
        if (is_array($decoded)) {
            return array_map('intval', $decoded);
        }
    }

    return [1, 2, 3, 4, 5, 6];
}

function is_shop_open(array $shop): bool {
    $operating_days = resolve_operating_days($shop);
    $today = (int) date('w');
    if (!in_array($today, $operating_days, true)) {
        return false;
    }

    $opening_time = $shop['opening_time'] ?: '08:00';
    $closing_time = $shop['closing_time'] ?: '18:00';
    $current_minutes = ((int) date('H')) * 60 + (int) date('i');
    [$open_hour, $open_minute] = array_map('intval', explode(':', $opening_time));
    [$close_hour, $close_minute] = array_map('intval', explode(':', $closing_time));
    $open_minutes = $open_hour * 60 + $open_minute;
    $close_minutes = $close_hour * 60 + $close_minute;

    return $current_minutes >= $open_minutes && $current_minutes <= $close_minutes;
}

// Get available shops
$shops_stmt = $pdo->query("
    SELECT * FROM shops 
    WHERE status = 'active' 
    ORDER BY rating DESC, total_orders DESC
");
$shops = $shops_stmt->fetchAll();
$shops = array_map(function($shop) use ($available_services, $default_pricing_settings) {
    $shop['operating_days_list'] = resolve_operating_days($shop);
    $shop['service_list'] = resolve_shop_services($shop, $available_services);
    $shop['is_open'] = is_shop_open($shop);
    $shop['pricing_settings'] = resolve_pricing_settings($shop, $default_pricing_settings);
    return $shop;
}, $shops);

// Place order
if(isset($_POST['place_order'])) {
    $shop_id = $_POST['shop_id'];
    $service_type = sanitize($_POST['service_type'] ?? '');
    $custom_service = sanitize($_POST['custom_service'] ?? '');
    $design_description = sanitize($_POST['design_description']);
    $quantity = $_POST['quantity'];
    $client_notes = sanitize($_POST['client_notes']);
    $complexity_level = sanitize($_POST['complexity_level'] ?? 'Standard');
    $requested_add_ons = $_POST['add_ons'] ?? [];
    if (!is_array($requested_add_ons)) {
        $requested_add_ons = [];
    }
    $rush_requested = ($_POST['rush_service'] ?? '') === '1';
    
    // Generate order number
    $order_number = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
    
    try {
        $shop_policy_stmt = $pdo->prepare("SELECT * FROM shops WHERE id = ? AND status = 'active'");
        $shop_policy_stmt->execute([$shop_id]);
        $shop_policy = $shop_policy_stmt->fetch();
        if (!$shop_policy) {
            throw new RuntimeException('Selected shop is no longer available.');
        }

        $enabled_services = resolve_shop_services($shop_policy, $available_services);
        $is_custom_allowed = in_array('Custom', $enabled_services, true);
        if ($custom_service && $is_custom_allowed) {
            $service_type = $custom_service;
        }

        if (!$service_type) {
            throw new RuntimeException('Please select a service type.');
        }

        if (!$is_custom_allowed && !in_array($service_type, $enabled_services, true)) {
            throw new RuntimeException('Selected service is not available for this shop.');
        }

        if (!is_shop_open($shop_policy)) {
            throw new RuntimeException('Selected shop is currently closed and cannot accept new orders.');
        }

        $pricing_settings = resolve_pricing_settings($shop_policy, $default_pricing_settings);
        $base_prices = $pricing_settings['base_prices'] ?? [];
        $add_on_fees = $pricing_settings['add_ons'] ?? [];
        $complexity_multipliers = $pricing_settings['complexity_multipliers'] ?? [];
        $rush_fee_percent = (float) ($pricing_settings['rush_fee_percent'] ?? 0);

        $base_price = (float) ($base_prices[$service_type] ?? ($base_prices['Custom'] ?? 0));
        $selected_add_ons = array_values(array_intersect($requested_add_ons, array_keys($add_on_fees)));
        $add_on_total = 0.0;
        foreach ($selected_add_ons as $addon) {
            $add_on_total += (float) ($add_on_fees[$addon] ?? 0);
        }
        $complexity_multiplier = (float) ($complexity_multipliers[$complexity_level] ?? 1);
        $rush_multiplier = $rush_requested ? 1 + ($rush_fee_percent / 100) : 1;
        $estimated_unit_price = ($base_price + $add_on_total) * $complexity_multiplier * $rush_multiplier;
        $estimated_total = $estimated_unit_price * (int) $quantity;
        $quote_details = [
            'service_type' => $service_type,
            'complexity' => $complexity_level,
            'add_ons' => $selected_add_ons,
            'rush' => $rush_requested,
            'breakdown' => [
                'base_price' => round($base_price, 2),
                'add_on_total' => round($add_on_total, 2),
                'complexity_multiplier' => round($complexity_multiplier, 2),
                'rush_fee_percent' => $rush_requested ? round($rush_fee_percent, 2) : 0,
            ],
            'estimated_unit_price' => round($estimated_unit_price, 2),
            'estimated_total' => round($estimated_total, 2),
        ];
        $quote_details_json = json_encode($quote_details);

        // Start transaction
        $pdo->beginTransaction();
        
        // Insert order
        $order_stmt = $pdo->prepare("
            INSERT INTO orders (order_number, client_id, shop_id, service_type, design_description, 
                                quantity, price, client_notes, quote_details, status) 
            VALUES (?, ?, ?, ?, ?, ?, NULL, ?, ?, 'pending')
        ");
        
        $order_stmt->execute([
            $order_number,
            $client_id,
            $shop_id,
            $service_type,
            $design_description,
            $quantity,
            $client_notes,
            $quote_details_json
        ]);
        
        $order_id = $pdo->lastInsertId();
        
        // Handle file upload
        if(isset($_FILES['design_file']) && $_FILES['design_file']['error'] == 0) {
            $upload_dir = '../assets/uploads/designs/';
            if(!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $filename = $order_id . '_' . basename($_FILES['design_file']['name']);
            $target_file = $upload_dir . $filename;
            
            if(move_uploaded_file($_FILES['design_file']['tmp_name'], $target_file)) {
                $update_stmt = $pdo->prepare("UPDATE orders SET design_file = ? WHERE id = ?");
                $update_stmt->execute([$filename, $order_id]);
            }
        }
        
        // Update shop statistics
        $shop_stmt = $pdo->prepare("UPDATE shops SET total_orders = total_orders + 1 WHERE id = ?");
        $shop_stmt->execute([$shop_id]);
        
        create_notification(
            $pdo,
            $client_id,
            $order_id,
            'info',
            'Your order #' . $order_number . ' has been submitted and is awaiting shop acceptance.'
        );
        
        $pdo->commit();
        
        $success = "Order placed successfully! Your order number is: <strong>$order_number</strong>";
        
    } catch(RuntimeException $e) {
        $error = $e->getMessage();
    } catch(PDOException $e) {
        $pdo->rollBack();
        $error = "Failed to place order: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Place Order - Embroidery Services</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .shop-card {
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .shop-card:hover {
            border-color: #4361ee;
            background: #f8f9ff;
        }
        .shop-card.selected {
            border-color: #4361ee;
            background: #4361ee;
            color: white;
        }
        .service-option {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            cursor: pointer;
        }
        .service-option:hover {
            border-color: #4361ee;
        }
        .service-option.selected {
            border-color: #4361ee;
            background: #f8f9ff;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar--compact">
        <div class="container d-flex justify-between align-center">
            <a href="dashboard.php" class="navbar-brand">

                <i class="fas fa-user"></i> Client Portal

            </a>
            <ul class="navbar-nav">
                <li><a href="dashboard.php" class="nav-link">Dashboard</a></li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle active">
                        <i class="fas fa-clipboard-list"></i> Orders
                    </a>
                    <div class="dropdown-menu">
                        <a href="place_order.php" class="dropdown-item active"><i class="fas fa-plus-circle"></i> Place Order</a>
                        <a href="track_order.php" class="dropdown-item"><i class="fas fa-route"></i> Track Orders</a>
                    </div>
                </li>
                <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle">
                        <i class="fas fa-layer-group"></i> Services
                    </a>
                    <div class="dropdown-menu">
                        <a href="customize_design.php" class="dropdown-item"><i class="fas fa-paint-brush"></i> Customize Design</a>
                        <a href="rate_provider.php" class="dropdown-item"><i class="fas fa-star"></i> Rate Provider</a>
                    </div>
                </li>
                <li><a href="notifications.php" class="nav-link">Notifications
                    <?php if($unread_notifications > 0): ?>
                        <span class="badge badge-danger"><?php echo $unread_notifications; ?></span>
                    <?php endif; ?>
                </a></li>
                 <li class="dropdown">
                    <a href="#" class="nav-link dropdown-toggle">
                        <i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($_SESSION['user']['fullname']); ?>
                    </a>
                    <div class="dropdown-menu">
                        <a href="../auth/logout.php" class="dropdown-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    </div>
                </li>

            </ul>
        </div>
    </nav>

    <div class="container">
        <div class="dashboard-header">
            <h2>Place New Order</h2>
            <p class="text-muted">Fill in the details below to place your embroidery order</p>
        </div>

        <?php if($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if($success): ?>
            <div class="alert alert-success">
                <?php echo $success; ?>
                <div class="mt-3">
                    <a href="track_order.php" class="btn btn-primary">Track Order</a>
                    <a href="dashboard.php" class="btn btn-outline-primary">Back to Dashboard</a>
                </div>
            </div>
        <?php else: ?>
        <form method="POST" enctype="multipart/form-data" id="orderForm">
            <!-- Step 1: Select Shop -->
            <div class="card mb-4">
                <h3>Step 1: Select Service Provider</h3>
                <div class="row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px;">
                    <?php foreach($shops as $shop): ?>
                    <div class="shop-card" onclick="selectShop(<?php echo $shop['id']; ?>)"
                         data-services="<?php echo htmlspecialchars(json_encode($shop['service_list']), ENT_QUOTES, 'UTF-8'); ?>"
                         data-pricing="<?php echo htmlspecialchars(json_encode($shop['pricing_settings']), ENT_QUOTES, 'UTF-8'); ?>"
                         data-is-open="<?php echo $shop['is_open'] ? '1' : '0'; ?>"
                         id="shop-<?php echo $shop['id']; ?>">
                        <div class="d-flex align-center">
                            <?php if($shop['logo']): ?>
                                <img src="../assets/uploads/logos/<?php echo $shop['logo']; ?>" 
                                     style="width: 60px; height: 60px; border-radius: 50%; object-fit: cover; margin-right: 15px;">
                            <?php else: ?>
                                <div style="width: 60px; height: 60px; background: #f0f0f0; border-radius: 50%; 
                                            display: flex; align-items: center; justify-content: center; margin-right: 15px;">
                                    <i class="fas fa-store fa-2x text-muted"></i>
                                </div>
                            <?php endif; ?>
                            <div>
                                <h5 class="mb-1"><?php echo htmlspecialchars($shop['shop_name']); ?></h5>
                                <div class="mb-1">
                                    <?php for($i = 1; $i <= 5; $i++): ?>
                                        <i class="fas fa-star<?php echo $i <= $shop['rating'] ? ' text-warning' : ' text-muted'; ?>"></i>
                                    <?php endfor; ?>
                                    <small>(<?php echo $shop['total_orders']; ?> orders)</small>
                                </div>
                                <p class="text-muted small mb-0"><?php echo substr($shop['shop_description'], 0, 100); ?>...</p>
                                <div class="text-muted small">
                                    <strong>Status:</strong>
                                    <?php if ($shop['is_open']): ?>
                                        <span class="text-success">Open</span>
                                    <?php else: ?>
                                        <span class="text-danger">Closed</span>
                                    <?php endif; ?>
                                    <span> • Hours: <?php echo htmlspecialchars($shop['opening_time'] ? substr($shop['opening_time'], 0, 5) : '08:00'); ?>-<?php echo htmlspecialchars($shop['closing_time'] ? substr($shop['closing_time'], 0, 5) : '18:00'); ?></span>
                                </div>
                            </div>
                        </div>
                        <input type="radio" name="shop_id" value="<?php echo $shop['id']; ?>" 
                               style="display: none;" required>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Step 2: Service Type -->
            <div class="card mb-4">
                <h3>Step 2: Select Service Type</h3>
                <div class="row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;">
                    <div class="service-option" data-service="T-shirt Embroidery" onclick="selectService('T-shirt Embroidery')">
                        <h5>T-shirt Embroidery</h5>
                        <p class="text-muted small">Custom embroidery on t-shirts</p>
                        <input type="radio" name="service_type" value="T-shirt Embroidery" style="display: none;">
                    </div>
                    
                    <div class="service-option" data-service="Logo Embroidery" onclick="selectService('Logo Embroidery')">
                        <h5>Logo Embroidery</h5>
                        <p class="text-muted small">Company logo on uniforms</p>
                        <input type="radio" name="service_type" value="Logo Embroidery" style="display: none;">
                    </div>
                    
                    <div class="service-option" data-service="Cap Embroidery" onclick="selectService('Cap Embroidery')">
                        <h5>Cap Embroidery</h5>
                        <p class="text-muted small">Custom embroidery on caps</p>
                        <input type="radio" name="service_type" value="Cap Embroidery" style="display: none;">
                    </div>
                    
                    <div class="service-option" data-service="Bag Embroidery" onclick="selectService('Bag Embroidery')">
                        <h5>Bag Embroidery</h5>
                        <p class="text-muted small">Embroidery on bags and backpacks</p>
                        <input type="radio" name="service_type" value="Bag Embroidery" style="display: none;">
                    </div>
                </div>
                
                <div class="form-group mt-3">
                    <label>Or specify custom service:</label>
                    <input type="text" name="custom_service" class="form-control" 
                           placeholder="Enter custom service type">
                    <small class="text-muted" id="customServiceHint">Custom services depend on the shop's availability.</small>
                </div>
            </div>

            <!-- Step 3: Quote Preferences -->
            <div class="card mb-4">
                <h3>Step 3: Quote Preferences</h3>
                <p class="text-muted">Help the shop prepare a structured quote. Final pricing will be confirmed by the shop.</p>
                <div class="row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px;">
                    <div class="form-group">
                        <label>Complexity Level</label>
                        <select name="complexity_level" class="form-control" id="complexitySelect">
                            <option value="Simple">Simple</option>
                            <option value="Standard" selected>Standard</option>
                            <option value="Complex">Complex</option>
                        </select>
                        <small class="text-muted" id="complexityHint">Select a shop to view multipliers.</small>
                    </div>
                    <div class="form-group">
                        <label>Rush Service</label>
                        <div class="d-flex align-center" style="gap: 8px;">
                            <input type="checkbox" id="rushService" name="rush_service" value="1">
                            <label for="rushService" class="mb-0">Request rush turnaround</label>
                        </div>
                        <small class="text-muted" id="rushHint">Select a shop to view rush fees.</small>
                    </div>
                </div>

                <div class="form-group">
                    <label>Optional Add-ons</label>
                    <div id="addOnOptions" class="row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 10px;"></div>
                    <small class="text-muted" id="addOnHint">Select a shop to see available add-ons and fees.</small>
                </div>

                <div class="alert alert-info mt-3">
                    <strong>Estimated quote:</strong>
                    <span id="quoteEstimate">Select a shop and service to see estimates.</span>
                    <div class="text-muted small mt-2">Estimates are based on shop rules and quantity, and may change after review.</div>
                </div>
            </div>

            <!-- Step 4: Design Details -->
            <div class="card mb-4">
                <h3>Step 4: Design Details</h3>
                <div class="form-group">
                    <label>Design Description *</label>
                    <textarea name="design_description" class="form-control" rows="4" required
                              placeholder="Describe your design in detail..."></textarea>
                </div>
                
                <div class="form-group">
                    <label>Upload Design File (Optional)</label>
                    <input type="file" name="design_file" class="form-control" accept=".jpg,.jpeg,.png,.pdf,.ai,.eps">
                    <small class="text-muted">Accepted formats: JPG, PNG, PDF, AI, EPS (Max 10MB)</small>
                </div>
                
                <div class="row" style="display: flex; gap: 15px;">
                    <div class="form-group" style="flex: 1;">
                        <label>Quantity *</label>
                        <input type="number" name="quantity" class="form-control" value="1" min="1" required>
                    </div>
                    
                    <div class="form-group" style="flex: 2;">
                        <label>Additional Notes (Optional)</label>
                        <textarea name="client_notes" class="form-control" rows="2"
                                  placeholder="Any special instructions or requirements..."></textarea>
                    </div>
                </div>
            </div>

            <!-- Step 5: Submit -->
            <div class="card mb-4">
                <h3>Step 5: Submit</h3>
                
                <div class="alert alert-info mt-3">
                    <h6><i class="fas fa-info-circle"></i> Important Notes:</h6>
                    <ul class="mb-0">
                        <li>Shop owner will set the estimated price after reviewing your order</li>
                        <li>The quote will follow the complexity, add-ons, and rush preferences you selected</li>
                        <li>You'll receive a confirmation email</li>
                        <li>Shop owner may contact you for clarifications</li>
                        <li>Payment details will be provided after order acceptance</li>
                    </ul>
                </div>
            </div>

            <div class="text-center">
                <button type="submit" name="place_order" class="btn btn-primary btn-lg">
                    <i class="fas fa-paper-plane"></i> Place Order Now
                </button>
                <a href="dashboard.php" class="btn btn-secondary btn-lg">Cancel</a>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <script>
        const pricingState = {
            base_prices: {},
            add_ons: {},
            complexity_multipliers: {},
            rush_fee_percent: 0
        };

        function formatCurrency(value) {
            return '₱' + Number(value || 0).toFixed(2);
        }

        function getSelectedService() {
            const selected = document.querySelector('input[name="service_type"]:checked');
            if (selected) {
                return selected.value;
            }
            const custom = document.querySelector('input[name="custom_service"]');
            return custom && custom.value.trim() ? custom.value.trim() : '';
        }

        function updateQuoteEstimate() {
            const quoteEstimate = document.getElementById('quoteEstimate');
            const service = getSelectedService();
            const quantity = parseInt(document.querySelector('input[name="quantity"]').value || '0', 10);
            if (!service || Object.keys(pricingState.base_prices).length === 0 || quantity <= 0) {
                quoteEstimate.textContent = 'Select a shop and service to see estimates.';
                return;
            }

            const basePrice = pricingState.base_prices[service] ?? pricingState.base_prices.Custom ?? 0;
            const addOns = Array.from(document.querySelectorAll('input[name="add_ons[]"]:checked'))
                .map((input) => input.value);
            const addOnTotal = addOns.reduce((sum, addon) => sum + (pricingState.add_ons[addon] || 0), 0);
            const complexity = document.getElementById('complexitySelect').value;
            const complexityMultiplier = pricingState.complexity_multipliers[complexity] ?? 1;
            const rushRequested = document.getElementById('rushService').checked;
            const rushMultiplier = rushRequested ? 1 + (pricingState.rush_fee_percent / 100) : 1;

            const unitEstimate = (Number(basePrice) + Number(addOnTotal)) * complexityMultiplier * rushMultiplier;
            const totalEstimate = unitEstimate * quantity;

            quoteEstimate.textContent = `${formatCurrency(unitEstimate)} per item • ${formatCurrency(totalEstimate)} total`;
        }

        function renderAddOns(addOns) {
            const container = document.getElementById('addOnOptions');
            container.innerHTML = '';
            const entries = Object.entries(addOns);
            if (!entries.length) {
                container.innerHTML = '<span class="text-muted">No add-ons configured for this shop.</span>';
                return;
            }
            entries.forEach(([name, fee]) => {
                const wrapper = document.createElement('label');
                wrapper.style.display = 'flex';
                wrapper.style.alignItems = 'center';
                wrapper.style.gap = '8px';
                const input = document.createElement('input');
                input.type = 'checkbox';
                input.name = 'add_ons[]';
                input.value = name;
                input.addEventListener('change', updateQuoteEstimate);
                const text = document.createElement('span');
                text.textContent = `${name} (+${formatCurrency(fee)})`;
                wrapper.appendChild(input);
                wrapper.appendChild(text);
                container.appendChild(wrapper);
            });
        }

        function updateComplexityOptions(multipliers) {
            const complexitySelect = document.getElementById('complexitySelect');
            Array.from(complexitySelect.options).forEach(option => {
                const multiplier = multipliers[option.value];
                option.textContent = multiplier ? `${option.value} (x${Number(multiplier).toFixed(2)})` : option.value;
            });
        }

        function setPricingState(pricing) {
            pricingState.base_prices = pricing.base_prices || {};
            pricingState.add_ons = pricing.add_ons || {};
            pricingState.complexity_multipliers = pricing.complexity_multipliers || {};
            pricingState.rush_fee_percent = Number(pricing.rush_fee_percent || 0);

            renderAddOns(pricingState.add_ons);
            updateComplexityOptions(pricingState.complexity_multipliers);

            document.getElementById('complexityHint').textContent = 'Multipliers shown for this shop.';
            document.getElementById('rushHint').textContent = pricingState.rush_fee_percent
                ? `Rush fee: +${pricingState.rush_fee_percent}%`
                : 'Rush service is not configured.';
            document.getElementById('addOnHint').textContent = 'Add-ons pulled from shop pricing rules.';

            updateQuoteEstimate();
        }

        // Shop selection
        function selectShop(shopId) {
            // Remove selected class from all shops
            document.querySelectorAll('.shop-card').forEach(card => {
                card.classList.remove('selected');
            });
            
            // Add selected class to clicked shop
            const shopCard = document.getElementById('shop-' + shopId);
            shopCard.classList.add('selected');
            
            // Check the radio button
            const radio = shopCard.querySelector('input[type="radio"]');
            radio.checked = true;
            
            const services = JSON.parse(shopCard.dataset.services || '[]');
            const isOpen = shopCard.dataset.isOpen === '1';
            const customServiceInput = document.querySelector('input[name="custom_service"]');
            const customServiceHint = document.getElementById('customServiceHint');

            document.querySelectorAll('.service-option').forEach(option => {
                const serviceName = option.dataset.service;
                const enabled = isOpen && services.includes(serviceName);
                option.classList.toggle('disabled', !enabled);
                option.style.opacity = enabled ? '1' : '0.5';
                option.style.pointerEvents = enabled ? 'auto' : 'none';
                const radioInput = option.querySelector('input[type="radio"]');
                if (!enabled) {
                    radioInput.checked = false;
                    option.classList.remove('selected');
                }
            });

            const customAllowed = services.includes('Custom');
            customServiceInput.disabled = !customAllowed || !isOpen;
            if (!customAllowed || !isOpen) {
                customServiceInput.value = '';
            }
            customServiceHint.textContent = customAllowed
                ? (isOpen ? 'Custom services are available for this shop.' : 'This shop is closed right now.')
                : 'Custom services are not offered by this shop.';
                
            const pricing = JSON.parse(shopCard.dataset.pricing || '{}');
            setPricingState(pricing);
        }
        
        // Service selection
        function selectService(service) {
            // Remove selected class from all services
            document.querySelectorAll('.service-option').forEach(option => {
                option.classList.remove('selected');
            });
            
            // Add to clicked service
            event.currentTarget.classList.add('selected');
            
            // Check the radio button
            const radio = event.currentTarget.querySelector('input[type="radio"]');
            radio.checked = true;
            
            // Update custom service field
            document.querySelector('input[name="custom_service"]').value = service;
            updateQuoteEstimate();
        }

        document.querySelectorAll('.shop-card').forEach(card => {
            const services = card.dataset.services || '[]';
            card.dataset.services = services;
        });
        
        document.getElementById('complexitySelect').addEventListener('change', updateQuoteEstimate);
        document.getElementById('rushService').addEventListener('change', updateQuoteEstimate);
        document.querySelector('input[name="quantity"]').addEventListener('input', updateQuoteEstimate);
        document.querySelector('input[name="custom_service"]').addEventListener('input', updateQuoteEstimate);
    </script>
</body>
</html>