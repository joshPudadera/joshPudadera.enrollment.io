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

// ── REGISTER ─────────────────────────────────────────────────
case 'register':
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
    if (!in_array($role, ['admin', 'student', 'staff']))
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

// ── LOGIN ─────────────────────────────────────────────────────
case 'login':
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

    // MFA for admin and staff
    if (in_array($user['role'], ['admin', 'staff'])) {
        $mfa_code    = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $mfa_expires = time() + 300;

        // Store in session WITHOUT regenerating the session ID here.
        // session_regenerate_id can cause the new ID to not reach the browser
        // on hosted platforms when triggered from an AJAX fetch (no page reload).
        // The session data is written to the same session — the browser already
        // has the cookie — so the MFA page will find mfa_pending correctly.
        $_SESSION['mfa_pending'] = [
            'user_id'    => $user['id'],
            'username'   => $user['username'],
            'email'      => $user['email'],
            'first_name' => $user['first_name'],
            'last_name'  => $user['last_name'],
            'role'       => $user['role'],
            'code'       => $mfa_code,
            'expires'    => $mfa_expires,
            'attempts'   => 0,
        ];

        require_once __DIR__ . '/mailer.php';
        $mfa_to   = 'enrollment.systembcp@gmail.com';
        $mfa_body = email_template(
            'BCP Staff Login Verification',
            "<p style='color:#333;font-size:14px;line-height:1.7;'>
               A login attempt was made for account <strong>" . htmlspecialchars($user['username']) . "</strong>
               (<em>" . ucfirst($user['role']) . "</em>).
             </p>
             <p style='color:#333;font-size:14px;line-height:1.7;'>Your verification code is:</p>
             <div style='text-align:center;margin:24px 0;'>
               <span style='font-size:36px;font-weight:900;letter-spacing:.18em;color:#1a3a8c;
                            background:#eff6ff;padding:14px 32px;border-radius:12px;
                            display:inline-block;'>{$mfa_code}</span>
             </div>
             <p style='color:#888;font-size:12px;text-align:center;'>
               This code expires in <strong>5 minutes</strong>.<br>
               If you did not attempt to log in, please secure your account immediately.
             </p>"
        );

        $sent       = false;
        $send_error = '';
        try {
            send_email($mfa_to, 'BCP Portal Login Code: ' . $mfa_code, $mfa_body);
            $sent = true;
        } catch (Throwable $e) {
            $send_error = $e->getMessage();
            error_log('[BCP MFA] Failed to send code to ' . $mfa_to . ': ' . $send_error);
        }

        respond(true, 'mfa_required', [
            'mfa'        => true,
            'sent'       => $sent,
            'hint'       => 'Check ' . $mfa_to . ' for your 6-digit code.',
            'send_error' => $sent ? '' : $send_error, // shown in browser if email fails
        ]);
    }

    // Regular login (students)
    session_regenerate_id(true);
    $_SESSION['user_id']    = $user['id'];
    $_SESSION['username']   = $user['username'];
    $_SESSION['email']      = $user['email'];
    $_SESSION['first_name'] = $user['first_name'];
    $_SESSION['last_name']  = $user['last_name'];
    $_SESSION['role']       = $user['role'];

    respond(true, 'Login successful.', ['role' => $user['role']]);

// ── VERIFY MFA ────────────────────────────────────────────────
case 'verify_mfa':
    $code = trim($_POST['code'] ?? '');

    if (empty($_SESSION['mfa_pending']))
        respond(false, 'No MFA session found. Please sign in again.');

    $pending = &$_SESSION['mfa_pending'];

    if (time() > $pending['expires']) {
        unset($_SESSION['mfa_pending']);
        respond(false, 'Verification code has expired. Please sign in again.');
    }

    $pending['attempts']++;
    if ($pending['attempts'] > 5) {
        unset($_SESSION['mfa_pending']);
        respond(false, 'Too many failed attempts. Please sign in again.');
    }

    if ($code !== $pending['code']) {
        $left = 5 - $pending['attempts'];
        respond(false, 'Incorrect code. ' . $left . ' attempt' . ($left !== 1 ? 's' : '') . ' remaining.');
    }

    // Code correct — complete login
    // Regenerate AFTER the page redirect, not during AJAX, so the new
    // session ID cookie reaches the browser on the next full page load.
    $_SESSION['user_id']       = $pending['user_id'];
    $_SESSION['username']      = $pending['username'];
    $_SESSION['email']         = $pending['email'];
    $_SESSION['first_name']    = $pending['first_name'];
    $_SESSION['last_name']     = $pending['last_name'];
    $_SESSION['role']          = $pending['role'];
    $_SESSION['last_activity'] = time();
    $_SESSION['regen_on_load'] = true; // flag: regenerate ID on next full page load
    unset($_SESSION['mfa_pending']);

    respond(true, 'Verified.', ['role' => $_SESSION['role']]);

// ── RESEND MFA ────────────────────────────────────────────────
case 'resend_mfa':
    if (empty($_SESSION['mfa_pending']))
        respond(false, 'No MFA session. Please sign in again.');

    $pending  = &$_SESSION['mfa_pending'];
    $new_code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $pending['code']     = $new_code;
    $pending['expires']  = time() + 300;
    $pending['attempts'] = 0;

    require_once __DIR__ . '/mailer.php';
    $mfa_to   = 'enrollment.systembcp@gmail.com';
    $mfa_body = email_template(
        'BCP Staff Login Verification (Resent)',
        "<p style='color:#333;font-size:14px;line-height:1.7;'>
           Your new verification code for account <strong>" . htmlspecialchars($pending['username']) . "</strong> is:
         </p>
         <div style='text-align:center;margin:24px 0;'>
           <span style='font-size:36px;font-weight:900;letter-spacing:.18em;color:#1a3a8c;
                        background:#eff6ff;padding:14px 32px;border-radius:12px;
                        display:inline-block;'>{$new_code}</span>
         </div>
         <p style='color:#888;font-size:12px;text-align:center;'>Expires in <strong>5 minutes</strong>.</p>"
    );
    try {
        send_email($mfa_to, 'BCP Portal Login Code: ' . $new_code, $mfa_body);
        respond(true, 'A new code has been sent.');
    } catch (Throwable $e) {
        respond(false, 'Failed to resend code. Please try again.');
    }

// ── CHECK ACTIVITY (inactivity timeout ping) ──────────────────
case 'check_activity':
    if (empty($_SESSION['user_id']))
        respond(false, 'Session expired.');
    $_SESSION['last_activity'] = time();
    respond(true, 'Active.');

// ── UPDATE PROFILE ────────────────────────────────────────────
case 'update_profile':
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
    $stmt   = $conn->prepare(
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

// ── CHANGE PASSWORD ───────────────────────────────────────────
case 'change_password':
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
    $stmt   = $conn->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !password_verify($current, $row['password_hash']))
        respond(false, 'Current password is incorrect.');

    $newHash = password_hash($new, PASSWORD_DEFAULT);
    $stmt    = $conn->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $stmt->bind_param('si', $newHash, $userId);
    if ($stmt->execute()) respond(true, 'Password changed successfully.');
    respond(false, 'Failed to update password.');

// ── LOGOUT ────────────────────────────────────────────────────
case 'logout':
    session_unset();
    session_destroy();
    respond(true, 'Logged out.');

// ── DELETE ACCOUNT ────────────────────────────────────────────
case 'delete_account':
    requireSession();
    $userId = $_SESSION['user_id'];
    $stmt   = $conn->prepare('DELETE FROM users WHERE id = ?');
    $stmt->bind_param('i', $userId);
    if ($stmt->execute()) {
        session_unset();
        session_destroy();
        respond(true, 'Account deleted.');
    }
    respond(false, 'Failed to delete account.');

default:
    respond(false, 'Unknown action.');
}

$conn->close();
