<?php
session_start();
require_once '../config/db.php';
require_role('owner');

$query = $_GET;
unset($query['legacy']);
$query['legacy'] = 'add_staff';
$target = 'manage_staff.php';
if (!empty($query)) {
    $target .= '?' . http_build_query($query);
}

header('Location: ' . $target, true, 302);
exit();