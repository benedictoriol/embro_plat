<?php
session_start();

$query = $_SERVER['QUERY_STRING'] ?? '';
$target = 'design_proofing.php';
if($query !== '') {
    $target .= '?' . $query;
}

$_SESSION['pricing_redirect_notice'] = 'Estimated Price and System Suggested Price are now auto-generated from stitch and design data.';

header('Location: ' . $target);
exit;