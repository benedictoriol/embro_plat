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

function canonicalize_role(?string $role): ?string {
    if ($role === null) {
        return null;
    }

    $normalized = strtolower(trim($role));
    return match ($normalized) {
        'employee' => 'staff',
        'customer', 'customers' => 'client',
        default => $normalized,
    };
}

function canonicalize_user_status(?string $status): string {
    $normalized = strtolower(trim((string) $status));

    return match ($normalized) {
        'approved', 'enabled' => 'active',
        'disabled', 'suspended' => 'inactive',
        '' => 'inactive',
        default => $normalized,
    };
}

function role_access_label(?string $role): ?string {
    $normalized = canonicalize_role($role);
    if ($normalized === null) {
        return null;
    }

    return match ($normalized) {
        'owner' => 'Owner',
        'hr' => 'HR',
        'staff' => 'Staff',
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

function resolve_owner_effective_status(?string $userStatus, ?string $shopStatus): string {
    $normalizedUserStatus = canonicalize_user_status((string) ($userStatus ?? 'pending'));
    $normalizedShopStatus = canonicalize_user_status((string) ($shopStatus ?? ''));

    if ($normalizedUserStatus === 'rejected' || $normalizedShopStatus === 'rejected') {
        return 'rejected';
    }

    // Shop approval is the primary gate for owner workspace access.
    // Some legacy approval flows may activate the shop before syncing user status.
    if ($normalizedShopStatus === 'active') {
        return 'active';
    }

    return 'pending';
}

function fetch_owner_shop_status(int $ownerId, ?PDO $pdo = null): ?string {
    $pdo = $pdo instanceof PDO ? $pdo : ($GLOBALS['pdo'] ?? null);
    if (!$pdo instanceof PDO) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT status FROM shops WHERE owner_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$ownerId]);
    $shopStatus = $stmt->fetchColumn();

    return is_string($shopStatus) ? strtolower($shopStatus) : null;
}

function is_owner_shop_profile_complete(?array $shop): bool {
    if (!is_array($shop) || empty($shop)) {
        return false;
    }

    if (!empty($shop['profile_completed_at'])) {
        return true;
    }

    $requiredFields = ['shop_name', 'shop_description', 'address', 'phone', 'email', 'permit_file'];
    foreach ($requiredFields as $field) {
        if (trim((string) ($shop[$field] ?? '')) === '') {
            return false;
        }
    }

    $businessPermitPayload = json_decode((string) ($shop['business_permit'] ?? ''), true);
    return is_array($businessPermitPayload) && !empty($businessPermitPayload['documents']);
}

function fetch_owner_onboarding_snapshot(int $ownerId, ?PDO $pdo = null): array {
    $pdo = $pdo instanceof PDO ? $pdo : ($GLOBALS['pdo'] ?? null);
    if (!$pdo instanceof PDO) {
        return [
            'state' => 'unknown',
            'user_status' => strtolower((string) ($_SESSION['user']['status'] ?? 'pending')),
            'shop_status' => null,
            'shop_id' => null,
            'profile_complete' => false,
            'shop' => null,
        ];
    }

    $ownerStmt = $pdo->prepare('SELECT status FROM users WHERE id = ? LIMIT 1');
    $ownerStmt->execute([$ownerId]);
    $userStatus = canonicalize_user_status((string) ($ownerStmt->fetchColumn() ?? 'pending'));

    $shopStmt = $pdo->prepare('SELECT * FROM shops WHERE owner_id = ? ORDER BY id DESC LIMIT 1');
    $shopStmt->execute([$ownerId]);
    $shop = $shopStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$shop) {
        return [
            'state' => 'no_shop',
            'user_status' => $userStatus,
            'shop_status' => null,
            'shop_id' => null,
            'profile_complete' => false,
            'shop' => null,
        ];
    }

    $shopStatus = canonicalize_user_status((string) ($shop['status'] ?? 'pending'));
    $profileComplete = is_owner_shop_profile_complete($shop);

    if ($shopStatus === 'rejected' || $userStatus === 'rejected') {
        $state = 'rejected';
    } elseif ($shopStatus === 'active') {
        // Treat an active shop as approved to avoid trapping owners in onboarding
        // when admin approval was completed through a shop-only workflow.
        $state = 'approved';
    } elseif (!$profileComplete) {
        $state = 'profile_incomplete';
    } else {
        $state = 'awaiting_approval';
    }

    return [
        'state' => $state,
        'user_status' => $userStatus,
        'shop_status' => $shopStatus,
        'shop_id' => isset($shop['id']) ? (int) $shop['id'] : null,
        'profile_complete' => $profileComplete,
        'shop' => $shop,
    ];
}

function owner_onboarding_gate_config(): array {
    return [
        'allowed_by_state' => [
            'no_shop' => ['create_shop.php', 'shop_profile.php'],
            'profile_incomplete' => ['shop_profile.php'],
            'awaiting_approval' => ['awaiting_approval.php', 'shop_profile.php', 'profile.php'],
            'rejected' => ['shop_profile.php', 'create_shop.php', 'awaiting_approval.php', 'profile.php'],
        ],
        'redirect_by_state' => [
            'no_shop' => '/owner/create_shop.php',
            'profile_incomplete' => '/owner/shop_profile.php',
            'awaiting_approval' => '/owner/awaiting_approval.php',
            'rejected' => '/owner/shop_profile.php',
        ],
        'message_by_state' => [
            'no_shop' => 'Please create your shop profile before accessing owner modules.',
            'profile_incomplete' => 'Please complete your shop profile first.',
            'awaiting_approval' => 'Your shop profile is complete and awaiting admin approval.',
            'rejected' => 'Your shop has not yet been verified. Please update your shop profile and resubmit for review.',
        ],
    ];
}

function owner_onboarding_access_decision(string $state, string $currentPage): array {
    $config = owner_onboarding_gate_config();
    $allowedByState = $config['allowed_by_state'];

    if ($state === 'approved' || !isset($allowedByState[$state])) {
        return ['allowed' => true, 'state' => $state];
    }

    if (in_array($currentPage, $allowedByState[$state], true)) {
        return ['allowed' => true, 'state' => $state];
    }

    return [
        'allowed' => false,
        'state' => $state,
        'redirect' => $config['redirect_by_state'][$state] ?? '/owner/shop_profile.php',
        'message' => $config['message_by_state'][$state] ?? 'Owner onboarding checks are still in progress.',
    ];
}

function require_owner_onboarding_gate(?PDO $pdo = null): void {
    $user = $_SESSION['user'] ?? null;
    if (!is_array($user) || canonicalize_role($user['role'] ?? null) !== 'owner') {
        return;
    }

    $currentPath = (string) ($_SERVER['PHP_SELF'] ?? '');
    if (!str_starts_with($currentPath, '/owner/')) {
        return;
    }

    $currentPage = basename($currentPath);
    $snapshot = fetch_owner_onboarding_snapshot((int) ($user['id'] ?? 0), $pdo);
    $_SESSION['owner_onboarding_state'] = $snapshot['state'];

    $decision = owner_onboarding_access_decision((string) ($snapshot['state'] ?? 'unknown'), $currentPage);
    if (!empty($decision['allowed'])) {
        return;
    }

    $_SESSION['owner_gate_notice'] = (string) ($decision['message'] ?? 'Owner onboarding checks are still in progress.');
    $redirectTarget = (string) ($decision['redirect'] ?? '/owner/shop_profile.php');
    header('Location: ' . $redirectTarget);
    exit;
}

function owner_requires_approved_shop(?array $user = null, ?PDO $pdo = null): bool {
    $user = is_array($user) ? $user : ($_SESSION['user'] ?? null);
    if (!is_array($user) || canonicalize_role($user['role'] ?? null) !== 'owner') {
        return false;
    }

    $snapshot = fetch_owner_onboarding_snapshot((int) ($user['id'] ?? 0), $pdo);
    return ($snapshot['state'] ?? '') !== 'approved';
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
        $userRole = canonicalize_role($_SESSION['user']['role'] ?? null);
        $normalizedRequiredRoles = array_map('canonicalize_role', $required_role);
        return in_array($userRole, $normalizedRequiredRoles, true);
    }

    return canonicalize_role($_SESSION['user']['role'] ?? null) === canonicalize_role($required_role);
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

    $userRole = canonicalize_role($_SESSION['user']['role']);
    $_SESSION['user']['role'] = $userRole;

    // Normalize to array
    if (!is_array($roles)) {
        $roles = [$roles];
    }

    $roles = array_map('canonicalize_role', $roles);

    if (!in_array($userRole, $roles, true)) {
        header("Location: /auth/login.php");
        exit;
    }
    
    if (!refresh_session_user_status()) {
        end_user_session();
        header("Location: /auth/login.php");
        exit;
    }

    $userRole = canonicalize_role($_SESSION['user']['role'] ?? $userRole);
    $_SESSION['user']['role'] = $userRole;

    if (($_SESSION['user']['status'] ?? null) !== 'active') {
        if ($userRole !== 'owner') {
            end_user_session();
            header("Location: /auth/login.php");
            exit;
        }
    }
    
    if (!can_role_login($userRole)) {
        end_user_session();
        header("Location: /auth/login.php");
        exit;
    }

        require_owner_onboarding_gate($GLOBALS['pdo'] ?? null);
}


/**
 * Redirect to the appropriate dashboard based on the user's role.
 *
 * @param string $role
 * @param string $base_path
 */
function redirect_based_on_role($role, $base_path = '..') {
     $sessionStatus = $_SESSION['user']['status'] ?? null;
     $role = canonicalize_role($role);

    switch ($role) {
        case 'sys_admin':
            header("Location: {$base_path}/sys_admin/dashboard.php");
            break;
        case 'owner':
           $snapshot = fetch_owner_onboarding_snapshot((int) ($_SESSION['user']['id'] ?? 0), $GLOBALS['pdo'] ?? null);
            if (($snapshot['state'] ?? '') === 'approved') {
                header("Location: {$base_path}/owner/dashboard.php");
            } elseif (($snapshot['state'] ?? '') === 'no_shop') {
                header("Location: {$base_path}/owner/create_shop.php");
            } elseif (($snapshot['state'] ?? '') === 'profile_incomplete' || ($snapshot['state'] ?? '') === 'rejected') {
                header("Location: {$base_path}/owner/shop_profile.php");
            } else {
                header("Location: {$base_path}/owner/awaiting_approval.php");
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

    $stmt = $pdo->prepare("SELECT id, status, role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user']['id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return false;
    }

    $userRole = canonicalize_role($user['role'] ?? null);
    $userStatus = canonicalize_user_status((string) ($user['status'] ?? ''));

    $_SESSION['user']['role'] = $userRole;

    if ($userRole === 'owner') {
        $snapshot = fetch_owner_onboarding_snapshot((int) $user['id'], $pdo);
        $_SESSION['user']['account_status'] = $userStatus;
        $_SESSION['user']['shop_status'] = $snapshot['shop_status'] ?? null;
        $_SESSION['user']['owner_onboarding_state'] = $snapshot['state'] ?? 'unknown';
        $_SESSION['user']['status'] = resolve_owner_effective_status($userStatus, $_SESSION['user']['shop_status']);
    } else {
        unset($_SESSION['user']['shop_status']);
        $_SESSION['user']['status'] = $userStatus;
    }

    return $_SESSION['user']['status'] === 'active';
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
    $role = canonicalize_role($_SESSION['user']['role'] ?? null);
    $bypassRoles = array_map('canonicalize_role', $bypassRoles);

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