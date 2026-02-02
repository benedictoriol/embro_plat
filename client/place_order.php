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
$shops = array_map(function($shop) use ($available_services) {
    $shop['operating_days_list'] = resolve_operating_days($shop);
    $shop['service_list'] = resolve_shop_services($shop, $available_services);
    $shop['is_open'] = is_shop_open($shop);
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

        // Start transaction
        $pdo->beginTransaction();
        
        // Insert order
        $order_stmt = $pdo->prepare("
            INSERT INTO orders (order_number, client_id, shop_id, service_type, design_description, 
                                quantity, price, client_notes, status) 
            VALUES (?, ?, ?, ?, ?, ?, NULL, ?, 'pending')
        ");
        
        $order_stmt->execute([
            $order_number,
            $client_id,
            $shop_id,
            $service_type,
            $design_description,
            $quantity,
            $client_notes
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

            <!-- Step 3: Design Details -->
            <div class="card mb-4">
                <h3>Step 3: Design Details</h3>
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

            <!-- Step 4: Submit -->
            <div class="card mb-4">
                <h3>Step 4: Submit</h3>
                
                <div class="alert alert-info mt-3">
                    <h6><i class="fas fa-info-circle"></i> Important Notes:</h6>
                    <ul class="mb-0">
                        <li>Shop owner will set the estimated price after reviewing your order</li>
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
        }
        document.querySelectorAll('.shop-card').forEach(card => {
            const services = card.dataset.services || '[]';
            card.dataset.services = services;
        });
    </script>
</body>
</html>