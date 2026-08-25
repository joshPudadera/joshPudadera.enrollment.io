<?php
// ============================================================
//  LOGIN_VIA_TOKEN.PHP
//  Handles the one-time login link sent via email.
//  URL format: /auth/login_via_token.php?token=XXXX
//  On success: logs the student in and redirects to set_password.php
// ============================================================
session_start();
require_once __DIR__ . '/../shared/db.php';

$token = trim($_GET['token'] ?? '');

if (!$token) {
    header('Location: signin.php?err=invalid_token'); exit;
}

// Fetch token — must be unused and not expired
$stmt = $conn->prepare(
    "SELECT lt.*, u.id AS uid, u.username, u.email,
            u.first_name, u.last_name, u.role
     FROM login_tokens lt
     JOIN users u ON lt.user_id = u.id
     WHERE lt.token = ? AND lt.used = 0 AND lt.expires_at > NOW()
     LIMIT 1"
);
$stmt->bind_param('s', $token);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    // Token invalid, used, or expired
    $conn->close();
    header('Location: signin.php?err=token_expired'); exit;
}

// Mark token as used
$upd = $conn->prepare("UPDATE login_tokens SET used=1 WHERE token=?");
$upd->bind_param('s', $token);
$upd->execute();
$upd->close();

// Destroy any existing session (e.g. admin is logged in) before logging in as student
session_unset();
session_destroy();
session_start();
session_regenerate_id(true);

// Log the student in
$_SESSION['user_id']           = $row['uid'];
$_SESSION['username']          = $row['username'];
$_SESSION['email']             = $row['email'];
$_SESSION['first_name']        = $row['first_name'];
$_SESSION['last_name']         = $row['last_name'];
$_SESSION['role']              = $row['role'];
$_SESSION['must_set_password'] = true;   // forces the password-change screen

$conn->close();
header('Location: set_password.php');
exit;
