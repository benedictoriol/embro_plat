<?php
$current_page = basename($_SERVER['PHP_SELF'] ?? 'dashboard.php');
$full_name = htmlspecialchars($_SESSION['user']['fullname'] ?? 'Staff Member');

$can_view_jobs = $can_view_jobs ?? true;
$can_update_status = $can_update_status ?? true;
$can_upload_photos = $can_upload_photos ?? true;

$navItems = [
    ['key' => 'dashboard.php', 'label' => 'Dashboard', 'icon' => 'fas fa-chart-line', 'href' => 'dashboard.php', 'show' => true],
    ['key' => 'assigned_jobs.php', 'label' => 'My Jobs', 'icon' => 'fas fa-list-check', 'href' => 'assigned_jobs.php', 'show' => $can_view_jobs],
    ['key' => 'schedule.php', 'label' => 'Schedule', 'icon' => 'fas fa-calendar-days', 'href' => 'schedule.php', 'show' => $can_view_jobs],
    ['key' => 'update_status.php', 'label' => 'Update Status', 'icon' => 'fas fa-clipboard-check', 'href' => 'update_status.php', 'show' => $can_update_status],
    ['key' => 'upload_photos.php', 'label' => 'Upload Photos', 'icon' => 'fas fa-camera', 'href' => 'upload_photos.php', 'show' => $can_upload_photos],
    ['key' => 'attendance.php', 'label' => 'Attendance', 'icon' => 'fas fa-user-clock', 'href' => 'attendance.php', 'show' => true],
    ['key' => 'notification_preferences.php', 'label' => 'Preferences', 'icon' => 'fas fa-bell', 'href' => 'notification_preferences.php', 'show' => true],
    ['key' => 'profile.php', 'label' => 'Profile', 'icon' => 'fas fa-user-cog', 'href' => 'profile.php', 'show' => true],
];
?>
<nav class="navbar navbar--compact">
    <div class="container d-flex justify-between align-center">
        <div class="d-flex align-center gap-4">
            <a href="dashboard.php" class="navbar-brand">
                <i class="fas fa-user-tie"></i>
                <span>Staff Portal</span>
            </a>
            <div class="d-flex gap-2">
                <span class="badge badge-primary">On Shift</span>
                <span class="text-muted"><?php echo $full_name; ?></span>
            </div>
        </div>

        <ul class="navbar-nav">
            <?php foreach ($navItems as $item): ?>
                <?php if (!$item['show']) { continue; } ?>
                <li>
                    <a href="<?php echo $item['href']; ?>" class="nav-link <?php echo $current_page === $item['key'] ? 'active' : ''; ?>">
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
