<?php
$current_page = basename($_SERVER['PHP_SELF'] ?? 'dashboard.php');
$active_page = $active_page ?? match ($current_page) {
    'dashboard.php' => 'dashboard',
    'hiring_management.php' => 'hiring',
    'create_staff.php' => 'create_staff',
    'attendance_management.php' => 'attendance',
    'staff_productivity_performance.php' => 'productivity',
    'payroll_compensation.php' => 'payroll',
    'analytics_reporting.php' => 'analytics',
    'notification_preferences.php' => 'preferences',
    default => '',
};

$hr_name = htmlspecialchars($_SESSION['user']['fullname'] ?? 'HR Manager');
$shop_name = htmlspecialchars($shop_name ?? 'HR Portal');
$items = [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'fas fa-chart-line', 'href' => 'dashboard.php'],
    ['key' => 'hiring', 'label' => 'Hiring', 'icon' => 'fas fa-user-plus', 'href' => 'hiring_management.php'],
    ['key' => 'create_staff', 'label' => 'Create Staff', 'icon' => 'fas fa-id-card', 'href' => 'create_staff.php'],
    ['key' => 'attendance', 'label' => 'Attendance', 'icon' => 'fas fa-user-check', 'href' => 'attendance_management.php'],
    ['key' => 'productivity', 'label' => 'Productivity', 'icon' => 'fas fa-chart-column', 'href' => 'staff_productivity_performance.php'],
    ['key' => 'payroll', 'label' => 'Payroll', 'icon' => 'fas fa-money-check-dollar', 'href' => 'payroll_compensation.php'],
    ['key' => 'analytics', 'label' => 'Analytics', 'icon' => 'fas fa-chart-pie', 'href' => 'analytics_reporting.php'],
    ['key' => 'preferences', 'label' => 'Preferences', 'icon' => 'fas fa-bell', 'href' => 'notification_preferences.php'],
];
?>
<nav class="navbar navbar--compact">
    <div class="container d-flex justify-between align-center">
        <div class="d-flex align-center gap-4">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-user-gear"></i>
                <span>HR Portal</span>
            </a>
            <div class="d-flex gap-2">
                <span class="badge badge-primary">Online</span>
                <span class="text-muted"><?php echo $shop_name; ?></span>
            </div>
        </div>

        <ul class="navbar-nav">
            <?php foreach ($items as $item): ?>
                <li>
                    <a href="<?php echo $item['href']; ?>" class="nav-link <?php echo $active_page === $item['key'] ? 'active' : ''; ?>">
                        <i class="<?php echo $item['icon']; ?>"></i> <?php echo $item['label']; ?>
                    </a>
                </li>
            <?php endforeach; ?>
            <li>
                <a href="../auth/logout.php" class="nav-link">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </li>
        </ul>
    </div>
</nav>
