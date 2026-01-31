<?php
session_start();
require_once '../config/db.php';
require_role('client');

$client_id = $_SESSION['user']['id'];
$unread_notifications = fetch_unread_notification_count($pdo, $client_id);
$error = '';
$success = '';

// Get available shops
$shops_stmt = $pdo->query("
    SELECT * FROM shops 
    WHERE status = 'active' 
    ORDER BY rating DESC, total_orders DESC
");
$shops = $shops_stmt->fetchAll();

// Place order
if(isset($_POST['place_order'])) {
    $shop_id = $_POST['shop_id'];
    $service_type = sanitize($_POST['service_type']);
    $design_description = sanitize($_POST['design_description']);
    $quantity = $_POST['quantity'];
    $client_notes = sanitize($_POST['client_notes']);
    
    // Generate order number
    $order_number = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
    
    try {
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
                    <div class="service-option" onclick="selectService('T-shirt Embroidery')">
                        <h5>T-shirt Embroidery</h5>
                        <p class="text-muted small">Custom embroidery on t-shirts</p>
                        <input type="radio" name="service_type" value="T-shirt Embroidery" style="display: none;">
                    </div>
                    
                    <div class="service-option" onclick="selectService('Logo Embroidery')">
                        <h5>Logo Embroidery</h5>
                        <p class="text-muted small">Company logo on uniforms</p>
                        <input type="radio" name="service_type" value="Logo Embroidery" style="display: none;">
                    </div>
                    
                    <div class="service-option" onclick="selectService('Cap Embroidery')">
                        <h5>Cap Embroidery</h5>
                        <p class="text-muted small">Custom embroidery on caps</p>
                        <input type="radio" name="service_type" value="Cap Embroidery" style="display: none;">
                    </div>
                    
                    <div class="service-option" onclick="selectService('Bag Embroidery')">
                        <h5>Bag Embroidery</h5>
                        <p class="text-muted small">Embroidery on bags and backpacks</p>
                        <input type="radio" name="service_type" value="Bag Embroidery" style="display: none;">
                    </div>
                </div>
                
                <div class="form-group mt-3">
                    <label>Or specify custom service:</label>
                    <input type="text" name="custom_service" class="form-control" 
                           placeholder="Enter custom service type">
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
        
    </script>
</body>
</html>