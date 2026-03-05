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
        <a href="dashboard.php" class="navbar-brand">
            <i class="fas fa-user"></i> Client Portal
        </a>
        <ul class="navbar-nav">
            <li><a href="dashboard.php" class="nav-link<?php echo $isHomeActive ? ' active' : ''; ?>">Home</a></li>
            <li><a href="track_order.php" class="nav-link<?php echo $currentPage === 'track_order.php' ? ' active' : ''; ?>">Track Orders</a></li>
            <li><a href="rate_provider.php" class="nav-link<?php echo $currentPage === 'rate_provider.php' ? ' active' : ''; ?>">Rate Orders</a></li>
            <li><a href="payment_handling.php" class="nav-link<?php echo $currentPage === 'payment_handling.php' ? ' active' : ''; ?>">Payment Methods</a></li>
            <li><a href="design_editor.php" class="nav-link<?php echo $currentPage === 'design_editor.php' || $currentPage === 'customize_design.php' ? ' active' : ''; ?>">Customize Design</a></li>
            <li><a href="design_proofing.php" class="nav-link<?php echo $currentPage === 'design_proofing.php' || $currentPage === 'pricing_quotation.php' ? ' active' : ''; ?>">Design Proofing & Quotation</a></li>
            <li><a href="client_posting_community.php" class="nav-link<?php echo $currentPage === 'client_posting_community.php' ? ' active' : ''; ?>">Client Community</a></li>
            <li><a href="messages.php" class="nav-link<?php echo $isMessageActive ? ' active' : ''; ?>">Message</a></li>
            <li><a href="notifications.php" class="nav-link<?php echo $isNotificationActive ? ' active' : ''; ?>">Notification
                <?php if ($unreadCount > 0): ?>
                    <span class="badge badge-danger"><?php echo $unreadCount; ?></span>
                <?php endif; ?>
            </a></li>
            <li><a href="customer_profile.php" class="nav-link<?php echo $isCustomerProfileActive ? ' active' : ''; ?>">Customer Profile</a></li>
            <li><a href="../auth/logout.php" class="nav-link">Log Out</a></li>
        </ul>
    </div>
</nav>