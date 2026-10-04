<?php
// Securely serves a generated document to the admin.
session_start();
require_once __DIR__ . '/../shared/db.php';
if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403); exit;
}

$id  = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); exit; }

$stmt = $conn->prepare("SELECT file_name, file_path FROM generated_documents WHERE id=? LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

if (!$row) { http_response_code(404); die('Document not found.'); }

// Resolve absolute path — file_path is relative to app root (e.g. admin/generated_docs/...)
$abs = realpath(__DIR__ . '/../') . DIRECTORY_SEPARATOR
     . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($row['file_path'], '/\\'));

if (!file_exists($abs)) { http_response_code(404); die('File not found on disk.'); }

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . basename($row['file_name']) . '"');
header('Content-Length: ' . filesize($abs));
header('Cache-Control: no-cache, must-revalidate');
readfile($abs);
exit;
