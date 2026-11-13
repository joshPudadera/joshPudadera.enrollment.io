<?php
session_start();
require_once __DIR__ . '/../shared/db.php';

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403); exit;
}

$file = basename($_GET['file'] ?? '');
if (!preg_match('/^logs_week_[\d-]+\.json\.gz$/', $file)) {
    http_response_code(400); exit;
}

$path = __DIR__ . '/../../logs_archive/' . $file;
if (!file_exists($path)) { http_response_code(404); exit; }

header('Content-Type: application/gzip');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
