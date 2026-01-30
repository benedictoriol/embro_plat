<?php
// config/auth_functions.php

/**
 * Check if user has required role
 * Check if user is logged in.
 */
function is_logged_in() {
    return isset($_SESSION['user']);
}

/**
 *
 * @param string $required_role Role required to access the page
 */
function check_role($required_role) {
    if (!isset($_SESSION['user'])) {
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
?>