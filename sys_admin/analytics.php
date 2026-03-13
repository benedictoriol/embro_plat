<?php
session_start();
require_once '../config/db.php';
require_role('sys_admin');

$query = $_GET;
unset($query['legacy']);
$query['legacy'] = 'analytics';
$target = 'analytics_reporting.php';
if (!empty($query)) {
    $target .= '?' . http_build_query($query);
}

header('Location: ' . $target, true, 302);
exit();