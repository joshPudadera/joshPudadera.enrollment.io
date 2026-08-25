<?php
// TEMP DEBUG — delete after use
session_start();
require_once __DIR__ . '/db.php';
if (($_SESSION['role'] ?? '') !== 'admin') die('admin only');

$res = $conn->query("
    SELECT lt.id, lt.token, lt.used, lt.expires_at,
           u.id AS uid, u.username, u.email, u.role
    FROM login_tokens lt
    JOIN users u ON lt.user_id = u.id
    ORDER BY lt.id DESC LIMIT 10
");
echo '<pre style="font-family:monospace;padding:20px;">';
while ($r = $res->fetch_assoc()) {
    echo "Token ID: {$r['id']}\n";
    echo "  User:    [{$r['uid']}] {$r['username']} <{$r['email']}> role={$r['role']}\n";
    echo "  Used:    {$r['used']}\n";
    echo "  Expires: {$r['expires_at']}\n";
    echo "  Link:    http://localhost/sms/app/auth/login_via_token.php?token={$r['token']}\n\n";
}
echo '</pre>';
$conn->close();
