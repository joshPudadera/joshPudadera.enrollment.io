<?php
// ============================================================
//  AUTH_ACTIONS.PHP  (shared/)
//  Handles all user-auth AJAX requests.
// ============================================================
ob_start();
session_start();
require_once __DIR__ . '/db.php';
ob_clean();
header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

function respond(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

function requireSession(): void {
    if (empty($_SESSION['user_id'])) {
        respond(false, 'Not authenticated.');
    }
}

switch ($action) {

case 'register': {
    $username   = trim($_POST['username']        ?? '');
    $email      = trim($_POST['email']           ?? '');
    $first_name = trim($_POST['first_name']      ?? '');
    $last_name  = trim($_POST['last_name']       ?? '');
    $password   = $_POST['password']             ?? '';
    $confirm    = $_POST['confirm_password']     ?? '';
    $role       = $_POST['role']                 ?? 'student';

    if (!$username || !$email || !$first_name || !$last_name || !$password)
        respond(false, 'All fields are required.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        respond(false, 'Invalid email address.');
    if (strlen($password) < 6)
        respond(false, 'Password must be at least 6 characters.');
    if ($password !== $confirm)
        respond(false, 'Passwords do not match.');
    if (!in_array($role, ['admin', 'student']))
        respond(false, 'Invalid role.');

    $stmt = $conn->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
    $stmt->bind_param('ss', $username, $email);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0)
        respond(false, 'Username or email is already taken.');
    $stmt->close();

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare(
        'INSERT INTO users (username, email, first_name, last_name, password_hash, role)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('ssssss', $username, $email, $first_name, $last_name, $hash, $role);
    if ($stmt->execute()) respond(true, 'Account created successfully.');
    respond(false, 'Registration failed. Please try again.');
}

case 'login': {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password']      ?? '';

    if (!$username || !$password)
        respond(false, 'Username and password are required.');

    $stmt = $conn->prepare(
        'SELECT id, username, email, first_name, last_name, password_hash, role
         FROM users WHERE username = ? LIMIT 1'
    );
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || !password_verify($password, $user['password_hash']))
        respond(false, 'Invalid username or password.');

    session_regenerate_id(true);
    $_SESSION['user_id']    = $user['id'];
    $_SESSION['username']   = $user['username'];
    $_SESSION['email']      = $user['email'];
    $_SESSION['first_name'] = $user['first_name'];
    $_SESSION['last_name']  = $user['last_name'];
    $_SESSION['role']       = $user['role'];

    respond(true, 'Login successful.', ['role' => $user['role']]);
}

case 'update_profile': {
    requireSession();
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name']  ?? '');
    $email      = trim($_POST['email']      ?? '');
    $username   = trim($_POST['username']   ?? '');

    if (!$first_name || !$last_name || !$email || !$username)
        respond(false, 'All profile fields are required.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        respond(false, 'Invalid email address.');

    $userId = $_SESSION['user_id'];
    $stmt = $conn->prepare(
        'SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ? LIMIT 1'
    );
    $stmt->bind_param('ssi', $username, $email, $userId);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0)
        respond(false, 'Username or email is already used by another account.');
    $stmt->close();

    $stmt = $conn->prepare(
        'UPDATE users SET first_name=?, last_name=?, email=?, username=? WHERE id=?'
    );
    $stmt->bind_param('ssssi', $first_name, $last_name, $email, $username, $userId);
    if ($stmt->execute()) {
        $_SESSION['first_name'] = $first_name;
        $_SESSION['last_name']  = $last_name;
        $_SESSION['email']      = $email;
        $_SESSION['username']   = $username;
        respond(true, 'Profile updated successfully.');
    }
    respond(false, 'Failed to update profile.');
}

case 'change_password': {
    requireSession();
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password']     ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!$current || !$new || !$confirm)
        respond(false, 'All password fields are required.');
    if (strlen($new) < 6)
        respond(false, 'New password must be at least 6 characters.');
    if ($new !== $confirm)
        respond(false, 'New passwords do not match.');

    $userId = $_SESSION['user_id'];
    $stmt = $conn->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !password_verify($current, $row['password_hash']))
        respond(false, 'Current password is incorrect.');

    $newHash = password_hash($new, PASSWORD_DEFAULT);
    $stmt = $conn->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->bind_param('si', $newHash, $userId);
    if ($stmt->execute()) respond(true, 'Password changed successfully.');
    respond(false, 'Failed to update password.');
}

case 'logout': {
    session_destroy();
    respond(true, 'Logged out.');
}

case 'delete_account': {
    requireSession();
    $userId = $_SESSION['user_id'];
    $stmt = $conn->prepare('DELETE FROM users WHERE id = ?');
    $stmt->bind_param('i', $userId);
    if ($stmt->execute()) {
        session_destroy();
        respond(true, 'Account deleted.');
    }
    respond(false, 'Failed to delete account.');
}

default:
    respond(false, 'Unknown action.');
}

$conn->close();
