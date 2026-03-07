<?php
$current_page = basename($_SERVER['PHP_SELF'] ?? '');

$brand_name = $shop['shop_name'] ?? ($_SESSION['user']['fullname'] ?? 'Owner Portal');

$active_groups = [
    'dashboard' => ['dashboard.php'],
    'pricing' => ['pricing_management.php'],
    'staff' => ['manage_staff.php', 'add_staff.php', 'edit_employee.php', 'create_hr.php'],
    'orders' => ['shop_orders.php', 'quotation_requests.php', 'view_order.php', 'accept_order.php', 'reject_order.php', 'view_invoice.php', 'view_receipt.php'],
    'community' => ['client_community_posts.php', 'shop_posting_content.php'],
    'messages' => ['messages.php'],
    'delivery' => ['delivery_management.php'],
    'finance' => ['payment_verifications.php', 'earnings.php'],
    'operations' => [
        'supplier_management.php',
        'supplier_list.php',
        'raw_material_inventory.php',
        'inventory_production_supply_chain_automation.php',
        'production_tracking.php',
        'quality_control.php',
        'storage_warehouse_management.php',
        'finished_goods_order_storage.php',
        'workforce_scheduling.php',
        'dispute_resolution.php',
    ],
    'analytics' => ['analytics_reporting.php'],
    'preferences' => ['notification_preferences.php'],
    'profile' => ['profile.php'],
];

$is_active = static function (string $key) use ($active_groups, $current_page): bool {
    return in_array($current_page, $active_groups[$key] ?? [], true);
};
?>
<nav class="navbar navbar--compact">
    <div class="container d-flex justify-between align-center">
        <div class="d-flex align-center gap-4">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-store"></i>
                <span><?php echo htmlspecialchars($brand_name); ?></span>
            </a>
            <div class="d-flex gap-2">
                <span class="badge badge-primary">Online</span>
                <span class="text-muted">Owner Portal</span>
            </div>
        </div>
        <ul class="navbar-nav">
            <li><a href="dashboard.php" class="nav-link <?php echo $is_active('dashboard') ? 'active' : ''; ?>"><i class="fas fa-chart-line"></i> Dashboard</a></li>
            <li><a href="pricing_management.php" class="nav-link <?php echo $is_active('pricing') ? 'active' : ''; ?>"><i class="fas fa-tags"></i> Pricing</a></li>
            <li><a href="manage_staff.php" class="nav-link <?php echo $is_active('staff') ? 'active' : ''; ?>"><i class="fas fa-user-gear"></i> Staff</a></li>
            <li><a href="shop_orders.php" class="nav-link <?php echo $is_active('orders') ? 'active' : ''; ?>"><i class="fas fa-box-open"></i> Orders</a></li>
            <li><a href="quotation_requests.php" class="nav-link <?php echo $current_page === 'quotation_requests.php' ? 'active' : ''; ?>"><i class="fas fa-file-signature"></i> Quote Requests</a></li>
            <li><a href="client_community_posts.php" class="nav-link <?php echo $is_active('community') ? 'active' : ''; ?>"><i class="fas fa-comments"></i> Community Posts</a></li>
            <li><a href="messages.php" class="nav-link <?php echo $is_active('messages') ? 'active' : ''; ?>"><i class="fas fa-envelope"></i> Messages</a></li>
            <li><a href="delivery_management.php" class="nav-link <?php echo $is_active('delivery') ? 'active' : ''; ?>"><i class="fas fa-truck"></i> Delivery & Pickup</a></li>
            <li><a href="payment_verifications.php" class="nav-link <?php echo $current_page === 'payment_verifications.php' ? 'active' : ''; ?>"><i class="fas fa-money-check-dollar"></i> Payments</a></li>
            <li><a href="earnings.php" class="nav-link <?php echo $current_page === 'earnings.php' ? 'active' : ''; ?>"><i class="fas fa-sack-dollar"></i> Earnings</a></li>
            <li><a href="supplier_management.php" class="nav-link <?php echo $current_page === 'supplier_management.php' ? 'active' : ''; ?>"><i class="fas fa-truck-ramp-box"></i> Supplier Management</a></li>
            <li><a href="raw_material_inventory.php" class="nav-link <?php echo $current_page === 'raw_material_inventory.php' ? 'active' : ''; ?>"><i class="fas fa-boxes-stacked"></i> Raw Materials</a></li>
            <li><a href="inventory_production_supply_chain_automation.php" class="nav-link <?php echo $current_page === 'inventory_production_supply_chain_automation.php' ? 'active' : ''; ?>"><i class="fas fa-link"></i> Supply Chain</a></li>
            <li><a href="production_tracking.php" class="nav-link <?php echo $current_page === 'production_tracking.php' ? 'active' : ''; ?>"><i class="fas fa-industry"></i> Production Tracking</a></li>
            <li><a href="quality_control.php" class="nav-link <?php echo $current_page === 'quality_control.php' ? 'active' : ''; ?>"><i class="fas fa-shield-halved"></i> Quality Control</a></li>
            <li><a href="storage_warehouse_management.php" class="nav-link <?php echo $current_page === 'storage_warehouse_management.php' ? 'active' : ''; ?>"><i class="fas fa-warehouse"></i> Warehouse</a></li>
            <li><a href="finished_goods_order_storage.php" class="nav-link <?php echo $current_page === 'finished_goods_order_storage.php' ? 'active' : ''; ?>"><i class="fas fa-box"></i> Finished Goods</a></li>
            <li><a href="workforce_scheduling.php" class="nav-link <?php echo $current_page === 'workforce_scheduling.php' ? 'active' : ''; ?>"><i class="fas fa-calendar-days"></i> Workforce Scheduling</a></li>
            <li><a href="dispute_resolution.php" class="nav-link <?php echo $current_page === 'dispute_resolution.php' ? 'active' : ''; ?>"><i class="fas fa-scale-balanced"></i> Dispute Resolution</a></li>
            <li><a href="analytics_reporting.php" class="nav-link <?php echo $is_active('analytics') ? 'active' : ''; ?>"><i class="fas fa-chart-pie"></i> Analytics</a></li>
            <li><a href="notification_preferences.php" class="nav-link <?php echo $is_active('preferences') ? 'active' : ''; ?>"><i class="fas fa-bell"></i> Preferences</a></li>
            <li><a href="profile.php" class="nav-link <?php echo $is_active('profile') ? 'active' : ''; ?>"><i class="fas fa-user-cog"></i> <?php echo htmlspecialchars($_SESSION['user']['fullname']); ?></a></li>
            <li><a href="../auth/logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>
</nav>

<?php include __DIR__ . '/owner_messages.php'; ?>