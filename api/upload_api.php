<?php
session_start();
require_once '../config/db.php';
require_once '../config/constants.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

if (!isset($_FILES['file'])) {
    http_response_code(422);
    echo json_encode(['error' => 'No file uploaded']);
    exit();
}

$file = $_FILES['file'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    http_response_code(422);
    echo json_encode(['error' => 'Upload failed']);
    exit();
}

if ($file['size'] > MAX_FILE_SIZE) {
    http_response_code(422);
    echo json_encode(['error' => 'File exceeds size limit']);
    exit();
}

$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($extension, ALLOWED_IMAGE_TYPES, true)) {
    http_response_code(422);
    echo json_encode(['error' => 'Unsupported file type']);
    exit();
}

$upload_dir = __DIR__ . '/../assets/uploads';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

$filename = uniqid('upload_', true) . '.' . $extension;
$destination = $upload_dir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $destination)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save file']);
    exit();
}

echo json_encode([
    'message' => 'Upload successful',
    'file' => [
        'name' => $filename,
        'path' => 'assets/uploads/' . $filename
    ]
]);
