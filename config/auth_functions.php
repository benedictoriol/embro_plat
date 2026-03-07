<?php
// config/auth_functions.php

/**
 * Check if user has required role
 * Check if user is logged in.
 */
function is_logged_in() {
    return isset($_SESSION['user']);
}

function role_access_matrix_setting_key(): string {
    return 'user_access_control_matrix';
}

function role_access_label(?string $role): ?string {
    if ($role === null) {
        return null;
    }

    $normalized = strtolower(trim($role));
    return match ($normalized) {
        'owner' => 'Owner',
        'hr' => 'HR',
        'staff', 'employee' => 'Staff',
        'client' => 'Client',
        default => null,
    };
}

function can_role_login(?string $role, ?PDO $pdo = null): bool {
    $roleLabel = role_access_label($role);
    if ($roleLabel === null) {
        return true;
    }

    $pdo = $pdo instanceof PDO ? $pdo : ($GLOBALS['pdo'] ?? null);
    if (!$pdo instanceof PDO) {
        return true;
    }

    try {
        $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([role_access_matrix_setting_key()]);
        $rawValue = $stmt->fetchColumn();
    } catch (Throwable $e) {
        return true;
    }

    if (!is_string($rawValue) || $rawValue === '') {
        return true;
    }

    $matrix = json_decode($rawValue, true);
    if (!is_array($matrix)) {
        return true;
    }

    foreach ($matrix as $entry) {
        if (!is_array($entry) || ($entry['role_name'] ?? null) !== $roleLabel) {
            continue;
        }

        if (array_key_exists('manage', $entry) && !$entry['manage']) {
            return false;
        }

        $permissions = $entry['permissions'] ?? [];
        if (!is_array($permissions)) {
            return false;
        }

        return in_array('Authentication: Login / Logout', $permissions, true);
    }

    return true;
}
function is_active_user(): bool {
    if (!isset($_SESSION['user'])) {
        return false;
    }

    if (is_session_expired()) {
        end_user_session();
        return false;
    }

    touch_session_activity();

    return ($_SESSION['user']['status'] ?? null) === 'active';
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

    if (is_array($required_role)) {
        return in_array($_SESSION['user']['role'], $required_role, true);
    }

    return $_SESSION['user']['role'] === $required_role;
}

/**
 * Redirect if not logged in or wrong role.
 *
 * @param string $required_role Role required to access the page
 */
function require_role($roles)
{
    if (!isset($_SESSION['user']['role'])) {
        header("Location: /auth/login.php");
        exit;
    }

    $userRole = $_SESSION['user']['role'];

    // Normalize to array
    if (!is_array($roles)) {
        $roles = [$roles];
    }

    if (!in_array($userRole, $roles, true)) {
        header("Location: /auth/login.php");
        exit;
    }
    
    if (!can_role_login($userRole)) {
        end_user_session();
        header("Location: /auth/login.php");
        exit;
    }
     $userStatus = $_SESSION['user']['status'] ?? null;
    if ($userRole === 'owner' && $userStatus !== 'active') {
        $currentPath = $_SERVER['PHP_SELF'] ?? '';
        $allowedPendingOwnerPages = [
            '/owner/shop_profile.php',
            '/owner/create_shop.php',
        ];

        if (!in_array($currentPath, $allowedPendingOwnerPages, true)) {
            $_SESSION['owner_pending_notice'] = 'Please complete your shop profile and wait for admin verification before accessing business modules.';
            header('Location: /owner/shop_profile.php');
            exit;
        }
    }
}


/**
 * Redirect to the appropriate dashboard based on the user's role.
 *
 * @param string $role
 * @param string $base_path
 */
function redirect_based_on_role($role, $base_path = '..') {
     $sessionStatus = $_SESSION['user']['status'] ?? null;

    switch ($role) {
        case 'sys_admin':
            header("Location: {$base_path}/sys_admin/dashboard.php");
            break;
        case 'owner':
           if ($sessionStatus !== 'active') {
                header("Location: {$base_path}/owner/shop_profile.php");
            } else {
                header("Location: {$base_path}/owner/dashboard.php");
            }
            break;
        case 'hr':
            header("Location: {$base_path}/hr/dashboard.php");
            break;
        case 'staff':
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

function session_timeout_seconds(): int {
    if (defined('SESSION_TIMEOUT_SECONDS')) {
        return (int) SESSION_TIMEOUT_SECONDS;
    }

    return 1800;
}

function is_session_expired(): bool {
    if (!isset($_SESSION['last_activity'])) {
        return false;
    }

    return (time() - (int) $_SESSION['last_activity']) > session_timeout_seconds();
}

function touch_session_activity(): void {
    $_SESSION['last_activity'] = time();
}

function end_user_session(): void {
    session_unset();
    session_destroy();
}

function refresh_session_user_status(): bool {
    if (!isset($_SESSION['user']['id'])) {
        return false;
    }

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        return true;
    }

    $stmt = $pdo->prepare("SELECT status, role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user']['id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return false;
    }

    $_SESSION['user']['status'] = $user['status'];
    $_SESSION['user']['role'] = $user['role'];

    return $user['status'] === 'active';
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_token_from_request(): ?string {
    return $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
}

function verify_csrf_token(?string $token): bool {
    if (!$token || empty($_SESSION['csrf_token'])) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

function enforce_csrf_protection(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    if (!verify_csrf_token(csrf_token_from_request())) {
        http_response_code(403);
        echo 'Invalid CSRF token.';
        exit();
    }
}

function staff_permission_defaults(): array {
    return [
        'view_jobs' => true,
        'update_status' => true,
        'upload_photos' => true,
    ];
}

function fetch_staff_permissions(PDO $pdo, int $userId): array {
    $defaults = staff_permission_defaults();
    $stmt = $pdo->prepare("
        SELECT permissions 
        FROM shop_staffs 
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

function require_staff_permission(PDO $pdo, int $userId, string $permissionKey): void {
    $permissions = fetch_staff_permissions($pdo, $userId);

    if (empty($permissions[$permissionKey])) {
        http_response_code(403);
        echo "You do not have permission to access this page.";
        exit();
    }
}

function normalize_staff_position(?string $position): string {
    $normalized = strtolower(trim((string) $position));
    return str_replace([' ', '-'], '_', $normalized);
}

function fetch_user_staff_position(PDO $pdo, int $userId): ?string {
    $stmt = $pdo->prepare(
        "SELECT position
         FROM shop_staffs
         WHERE user_id = ? AND status = 'active'
         ORDER BY created_at DESC
         LIMIT 1"
    );
    $stmt->execute([$userId]);
    $position = $stmt->fetchColumn();

    if (!is_string($position) || trim($position) === '') {
        return null;
    }

    return normalize_staff_position($position);
}

function user_has_position($user, $positions, ?PDO $pdo = null): bool {
    if (!is_array($positions)) {
        $positions = [$positions];
    }

    $allowedPositions = array_values(array_filter(array_map(static function ($position) {
        return normalize_staff_position((string) $position);
    }, $positions)));

    if (empty($allowedPositions) || !is_array($user)) {
        return false;
    }

    $userPosition = normalize_staff_position((string) ($user['position'] ?? ''));

    if ($userPosition === '' && isset($user['id'])) {
        $pdo = $pdo instanceof PDO ? $pdo : ($GLOBALS['pdo'] ?? null);
        if ($pdo instanceof PDO) {
            $dbPosition = fetch_user_staff_position($pdo, (int) $user['id']);
            if ($dbPosition !== null) {
                $userPosition = $dbPosition;
                $_SESSION['user']['position'] = $dbPosition;
            }
        }
    }

    return $userPosition !== '' && in_array($userPosition, $allowedPositions, true);
}

function require_staff_position($allowed_positions, array $options = []): void {
    if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
        header('Location: /auth/login.php');
        exit;
    }

    $bypassRoles = $options['bypass_roles'] ?? ['owner', 'sys_admin', 'hr'];
    $role = $_SESSION['user']['role'] ?? null;

    if (in_array($role, $bypassRoles, true)) {
        return;
    }

    if (!user_has_position($_SESSION['user'], $allowed_positions)) {
        http_response_code(403);
        echo 'You do not have permission to access this page.';
        exit;
    }
}
?>