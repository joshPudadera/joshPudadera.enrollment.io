<?php
// ============================================================
//  PW_RESET_ACTION.PHP  (system_tab/)
//  Admin-only AJAX endpoint. Generates a one-time password
//  reset token for any user and returns the login link as JSON.
//
//  POST params:  user_id (int)
//  Returns JSON: { success, link, name, email, expires } | { success:false, error }
// ============================================================
session_start();                          // must be first
require_once __DIR__ . '/../shared/db.php';

header('Content-Type: application/json');

// Auth guard
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user_id = (int)($_POST['user_id'] ?? 0);
if (!$user_id) {
    echo json_encode(['success' => false, 'error' => 'Missing user_id']);
    exit;
}

// ── Confirm user exists ───────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT id, username, email, first_name, last_name FROM users WHERE id = ? LIMIT 1"
);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    echo json_encode(['success' => false, 'error' => 'User not found']);
    exit;
}

// ── Invalidate any existing unused tokens for this user ───────
$inv = $conn->prepare("UPDATE login_tokens SET used = 1 WHERE user_id = ? AND used = 0");
$inv->bind_param('i', $user_id);
$inv->execute();
$inv->close();

// ── Insert new token ──────────────────────────────────────────
$token      = bin2hex(random_bytes(32));   // 64-char hex
$expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));

$ins = $conn->prepare(
    "INSERT INTO login_tokens (user_id, token, used, expires_at) VALUES (?, ?, 0, ?)"
);
$ins->bind_param('iss', $user_id, $token, $expires_at);

if (!$ins->execute()) {
    $err = $conn->error;
    $ins->close();
    echo json_encode(['success' => false, 'error' => 'DB error: ' . $err]);
    exit;
}
$ins->close();
$conn->close();

// ── Build the reset URL ───────────────────────────────────────
// Priority: APP_URL from .env > derived from DOCUMENT_ROOT > HTTP_HOST fallback.
$app_url = '';

// Try .env first
$env_file = __DIR__ . '/../.env';
if (file_exists($env_file)) {
    foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (str_starts_with($line, 'APP_URL=')) {
            $app_url = trim(substr($line, 8));
            break;
        }
    }
}

// Fallback: derive from DOCUMENT_ROOT + __FILE__
if (!$app_url) {
    $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'];
    $doc_root = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
    $app_root = rtrim(str_replace('\\', '/', dirname(dirname(__FILE__))), '/');
    $app_path = substr($app_root, strlen($doc_root));
    $app_url  = $scheme . '://' . $host . rtrim($app_path, '/');
}

$app_url = rtrim($app_url, '/');
$link    = $app_url . '/auth/login_via_token.php?token=' . $token;

echo json_encode([
    'success' => true,
    'link'    => $link,
    'name'    => trim($user['first_name'] . ' ' . $user['last_name']),
    'email'   => $user['email'],
    'expires' => $expires_at,
]);
exit;
