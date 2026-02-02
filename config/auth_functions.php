<?php
// config/auth_functions.php

/**
 * Check if user has required role
 * Check if user is logged in.
 */
function is_logged_in() {
    return isset($_SESSION['user']);
}

function is_active_user(): bool {
    return isset($_SESSION['user']) && ($_SESSION['user']['status'] ?? null) === 'active';
}

/**
 *
 * @param string $required_role Role required to access the page
 */
function check_role($required_role) {
    if (!isset($_SESSION['user'])) {
        return false;
    }

    if (!is_active_user()) {
        return false;
    }

    return $_SESSION['user']['role'] === $required_role;
}

/**
 * Redirect if not logged in or wrong role.
 *
 * @param string $required_role Role required to access the page
 */
function require_role($required_role) {
    if (!check_role($required_role)) {
        if (isset($_SESSION['user']) && !is_active_user()) {
            session_unset();
            session_destroy();
        }
        header("Location: ../auth/login.php");
        exit();
    }
}

/**
 * Redirect to the appropriate dashboard based on the user's role.
 *
 * @param string $role
 * @param string $base_path
 */
function redirect_based_on_role($role, $base_path = '..') {
    switch ($role) {
        case 'sys_admin':
            header("Location: {$base_path}/sys_admin/dashboard.php");
            break;
        case 'owner':
            header("Location: {$base_path}/owner/dashboard.php");
            break;
        case 'employee':
            header("Location: {$base_path}/employee/dashboard.php");
            break;
        case 'client':
            header("Location: {$base_path}/client/dashboard.php");
            break;
        default:
            header("Location: {$base_path}/index.php");
    }
    exit();
}
function employee_permission_defaults(): array {
    return [
        'view_jobs' => true,
        'update_status' => true,
        'upload_photos' => true,
    ];
}

function fetch_employee_permissions(PDO $pdo, int $userId): array {
    $defaults = employee_permission_defaults();
    $stmt = $pdo->prepare("
        SELECT permissions 
        FROM shop_employees 
        WHERE user_id = ? AND status = 'active'
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $permissionsJson = $stmt->fetchColumn();

    if (!$permissionsJson) {
        return $defaults;
    }

    $decoded = json_decode($permissionsJson, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    $permissions = [];
    foreach ($defaults as $key => $defaultValue) {
        $permissions[$key] = array_key_exists($key, $decoded) ? (bool) $decoded[$key] : $defaultValue;
    }

    return $permissions;
}

function require_employee_permission(PDO $pdo, int $userId, string $permissionKey): void {
    $permissions = fetch_employee_permissions($pdo, $userId);

    if (empty($permissions[$permissionKey])) {
        http_response_code(403);
        echo "You do not have permission to access this page.";
        exit();
    }
}
?>