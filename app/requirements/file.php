<?php
// ============================================================
//  FILE.PHP  (requirements/)
//  Serves uploaded requirement documents securely.
//  Only accessible to the file owner (student) or admin.
//  Usage: requirements/file.php?path=uploads/req_XXX.png
// ============================================================
session_start();
require_once __DIR__ . '/../shared/db.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(403); die('Access denied.');
}

$rel_path = $_GET['path'] ?? '';

// Sanitize — only allow files inside uploads/ with safe characters
if (!preg_match('#^uploads/[a-zA-Z0-9_./-]+$#', $rel_path)) {
    http_response_code(400); die('Invalid path.');
}

$abs_path = __DIR__ . '/' . $rel_path;

if (!file_exists($abs_path) || !is_file($abs_path)) {
    http_response_code(404); die('File not found.');
}

// Admins can view any file
$role = $_SESSION['role'] ?? 'student';
if ($role !== 'admin') {
    // Students can only view their own files
    $uid  = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare(
        "SELECT id FROM enrollment_documents WHERE file_path=? AND user_id=? LIMIT 1"
    );
    $stmt->bind_param('si', $rel_path, $uid);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        http_response_code(403); die('Access denied.');
    }
    $stmt->close();
}

// Determine MIME type
$ext  = strtolower(pathinfo($abs_path, PATHINFO_EXTENSION));
$mime = match($ext) {
    'pdf'  => 'application/pdf',
    'jpg', 'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    default => 'application/octet-stream',
};

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($abs_path));
header('Content-Disposition: inline; filename="' . basename($abs_path) . '"');
header('Cache-Control: private, max-age=3600');
readfile($abs_path);
exit;
