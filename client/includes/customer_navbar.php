<?php
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$unreadCount = isset($unread_notifications) ? (int) $unread_notifications : 0;

$isHomeActive = $currentPage === 'dashboard.php';
$isMessageActive = $currentPage === 'messages.php';
$isNotificationActive = in_array($currentPage, ['notifications.php', 'notification_preferences.php'], true);
$isCustomerProfileActive = $currentPage === 'customer_profile.php';
?>
<nav class="navbar navbar--compact">
    <div class="container d-flex justify-between align-center">
        <div class="d-flex align-center gap-4"></div>
        <a href="dashboard.php" class="navbar-brand">
            <i class="fas fa-user"></i>
            <span>Client Portal</span>
        </a>
        <div class="d-flex gap-2">
            <span class="badge badge-primary">Online</span>
            <span class="text-muted">Orders & Design</span>
        </div>
    </div>
        <ul class="navbar-nav">
            <li><a href="dashboard.php" class="nav-link<?php echo $isHomeActive ? ' active' : ''; ?>"><i class="fas fa-house"></i> Home</a></li>
            <li><a href="track_order.php" class="nav-link<?php echo $currentPage === 'track_order.php' ? ' active' : ''; ?>"><i class="fas fa-location-dot"></i> Track Orders</a></li>
            <li><a href="payment_handling.php" class="nav-link<?php echo $currentPage === 'payment_handling.php' ? ' active' : ''; ?>"><i class="fas fa-credit-card"></i> Payment Methods</a></li>
            <li><a href="design_editor.php" class="nav-link<?php echo $currentPage === 'design_editor.php' || $currentPage === 'customize_design.php' ? ' active' : ''; ?>"><i class="fas fa-pen-ruler"></i> Customize Design</a></li>
            <li><a href="design_proofing.php" class="nav-link<?php echo $currentPage === 'design_proofing.php' || $currentPage === 'pricing_quotation.php' ? ' active' : ''; ?>"><i class="fas fa-file-signature"></i> Design Proofing & Quotation</a></li>
            <li><a href="client_posting_community.php" class="nav-link<?php echo $currentPage === 'client_posting_community.php' ? ' active' : ''; ?>"><i class="fas fa-users"></i> Client Community</a></li>
            <li><a href="messages.php" class="nav-link<?php echo $isMessageActive ? ' active' : ''; ?>"><i class="fas fa-envelope"></i> Message</a></li>
            <li><a href="notifications.php" class="nav-link<?php echo $isNotificationActive ? ' active' : ''; ?>"><i class="fas fa-bell"></i> Notification
                <?php if ($unreadCount > 0): ?>
                    <span class="badge badge-danger"><?php echo $unreadCount; ?></span>
                <?php endif; ?>
            </a></li>
            <li><a href="customer_profile.php" class="nav-link<?php echo $isCustomerProfileActive ? ' active' : ''; ?>"><i class="fas fa-user-cog"></i> Customer Profile</a></li>
            <li><a href="../auth/logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Log Out</a></li>
        </ul>
    </div>
</nav>
<?php require_once __DIR__ . '/client_messages.php'; ?>