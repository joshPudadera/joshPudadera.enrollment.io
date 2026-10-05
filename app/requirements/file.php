<?php
// ============================================================
//  FILE.PHP  (requirements/)
//  Serves uploaded requirement documents securely.
//  Usage: requirements/file.php?path=uploads/req_XXX.png
// ============================================================
session_start();
require_once __DIR__ . '/../shared/db.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(403); die('Access denied.');
}

$rel_path = $_GET['path'] ?? '';

// Sanitize — only allow files inside uploads/ or with safe relative paths
if (!preg_match('#^[a-zA-Z0-9_./ -]+$#', $rel_path) || str_contains($rel_path, '..')) {
    http_response_code(400); die('Invalid path.');
}

// ── Resolve absolute path ─────────────────────────────────────
// Files are always stored in requirements/uploads/ on disk.
// The DB path may be stored as "uploads/req_xxx.jpg" (relative to requirements/)
// or as an absolute path. Normalize to always look in requirements/uploads/.
$abs_path = '';

// If the stored path starts with 'uploads/', resolve relative to requirements/
if (str_starts_with($rel_path, 'uploads/')) {
    $abs_path = __DIR__ . '/' . $rel_path;
}
// If it's already an absolute path (starts with /)
elseif (str_starts_with($rel_path, '/')) {
    $abs_path = $rel_path;
}
// Any other relative path — resolve from requirements/
else {
    $abs_path = __DIR__ . '/' . $rel_path;
}

// Ensure the uploads directory exists (create if missing)
$uploads_dir_path = __DIR__ . '/uploads';
if (!is_dir($uploads_dir_path)) {
    @mkdir($uploads_dir_path, 0755, true);
}

// Ensure the resolved path is actually inside the uploads directory
// Use string comparison if realpath fails (file doesn't exist yet)
$uploads_dir = realpath($uploads_dir_path) ?: $uploads_dir_path;
$real         = realpath($abs_path);

if (!$real) {
    // realpath fails if file doesn't exist — try the basename in uploads/
    $filename  = basename($rel_path);
    $try_path  = $uploads_dir . '/' . $filename;
    if (file_exists($try_path)) {
        $real = $try_path;
    }
}

if (!$real || !file_exists($real)) {
    http_response_code(404);
    error_log('[BCP file.php] File not found. rel_path=' . $rel_path . ' abs_path=' . $abs_path . ' uploads_dir=' . $uploads_dir);
    die('File not found. Path: ' . htmlspecialchars($rel_path));
}

// Security: must be inside uploads dir
if (!str_starts_with(str_replace('\\','/',$real), str_replace('\\','/',$uploads_dir))) {
    http_response_code(403); die('Access denied.');
}

// ── Access control ────────────────────────────────────────────
$role = $_SESSION['role'] ?? 'student';

// Admins and staff can view any document
if ($role !== 'admin' && $role !== 'staff') {
    $uid      = (int)$_SESSION['user_id'];
    // Normalise the stored path to match what's in the DB
    $db_path  = 'uploads/' . basename($real);
    $stmt     = $conn->prepare(
        "SELECT id FROM enrollment_documents
         WHERE (file_path=? OR redacted_path=? OR file_path=? OR redacted_path=?)
           AND (user_id=? OR pre_reg_id IN (
               SELECT id FROM pre_registrations WHERE user_id=?
           ))
         LIMIT 1"
    );
    $stmt->bind_param('ssssii', $rel_path, $rel_path, $db_path, $db_path, $uid, $uid);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        http_response_code(403); die('Access denied.');
    }
    $stmt->close();
}

// ── Serve file ────────────────────────────────────────────────
$ext  = strtolower(pathinfo($real, PATHINFO_EXTENSION));
$mime = match($ext) {
    'pdf'          => 'application/pdf',
    'jpg', 'jpeg'  => 'image/jpeg',
    'png'          => 'image/png',
    default        => 'application/octet-stream',
};

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($real));
header('Content-Disposition: inline; filename="' . basename($real) . '"');
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($real);
exit;
