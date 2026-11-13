<?php
// ── Database connection ──────────────────────────────────────
// Reads from environment variables (Docker) or falls back to XAMPP defaults
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'sms_db');

// Try connecting — on hosted platforms the DB already exists,
// on local XAMPP we create it if missing.
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS);

if ($conn->connect_error) {
    $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) ||
               (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));
    if ($is_ajax) {
        header('Content-Type: application/json');
        die(json_encode(['success' => false, 'message' => 'Database server unreachable. Is MySQL running?']));
    }
    die('
    <!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"/>
    <title>DB Error</title>
    <style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#fee2e2;margin:0;}
    .box{background:#fff;border-radius:12px;padding:32px 40px;max-width:480px;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.1);}
    h2{color:#dc2626;margin-bottom:8px;}p{color:#555;font-size:.9rem;line-height:1.6;}
    a{display:inline-block;margin-top:16px;background:#2563eb;color:#fff;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:600;}
    </style></head><body>
    <div class="box">
      <h2>&#9888; Cannot connect to MySQL</h2>
      <p>MySQL is not running or the credentials are wrong.<br>
         Make sure <strong>XAMPP MySQL</strong> is started, then run the setup.</p>
      <a href="../shared/full_setup.php">Run Setup</a>
    </div></body></html>');
}

// Try to create the database (works on local XAMPP with root).
// On hosted platforms the DB already exists and the user lacks CREATE privilege —
// suppress the error and just select the existing DB.
@$conn->query("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$conn->select_db(DB_NAME);

if ($conn->errno) {
    die('<p style="color:red;font-family:sans-serif;padding:20px;">
         Could not select database <strong>' . DB_NAME . '</strong>.<br>
         On hosted platforms, set DB_NAME to the database name provided by your host (e.g. hf_db_xxxxx).<br>
         <a href="../shared/full_setup.php">Click here to run the database setup.</a></p>');
}

$conn->set_charset('utf8mb4');

// Disable strict exception mode globally so idempotent ALTER TABLE
// statements across all pages don't throw exceptions on hosted servers
// that have MYSQLI_REPORT_STRICT enabled by default.
mysqli_report(MYSQLI_REPORT_OFF);

// ── Session regeneration after MFA verify ────────────────────
// Doing session_regenerate_id inside an AJAX call doesn't reliably
// deliver the new cookie on hosted platforms. Instead we flag it and
// regenerate here on the first full page load after MFA verification.
if (isset($_SESSION['regen_on_load']) && !empty($_SESSION['user_id'])) {
    unset($_SESSION['regen_on_load']);
    session_regenerate_id(true);
}

// Ensure ref_number column exists — suppress silently, it's added during setup
@$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS ref_number VARCHAR(50) DEFAULT NULL");

// Ensure students.pre_reg_id column exists (links a student row back to its application)
@$conn->query("ALTER TABLE students ADD COLUMN IF NOT EXISTS pre_reg_id INT UNSIGNED DEFAULT NULL");

// Ensure must_change_password column exists — set to 1 for new student accounts
// so they are forced to change their temp password on first login.
@$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0");

// ── Activity log table ────────────────────────────────────────
@$conn->query("CREATE TABLE IF NOT EXISTS activity_logs (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id    INT UNSIGNED NOT NULL,
    actor_name  VARCHAR(150) NOT NULL,
    actor_role  VARCHAR(30)  NOT NULL DEFAULT 'admin',
    action      VARCHAR(100) NOT NULL,
    target_type VARCHAR(60)  DEFAULT NULL,
    target_id   INT UNSIGNED DEFAULT NULL,
    target_name VARCHAR(200) DEFAULT NULL,
    details     TEXT         DEFAULT NULL,
    ip_address  VARCHAR(45)  DEFAULT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created_at (created_at),
    INDEX idx_actor_id   (actor_id),
    INDEX idx_action     (action)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── Helper: log an action ─────────────────────────────────────
function log_action(mysqli $conn, string $action, string $details = '',
                    string $target_type = '', int $target_id = 0, string $target_name = ''): void {
    $actor_id   = (int)($_SESSION['user_id']   ?? 0);
    $first      = $_SESSION['first_name'] ?? '';
    $last       = $_SESSION['last_name']  ?? '';
    $actor_name = trim("$first $last") ?: 'System';
    $actor_role = $_SESSION['role'] ?? 'admin';
    $ip         = $_SERVER['REMOTE_ADDR'] ?? '';

    $stmt = $conn->prepare(
        'INSERT INTO activity_logs (actor_id, actor_name, actor_role, action, target_type, target_id, target_name, details, ip_address)
         VALUES (?,?,?,?,?,?,?,?,?)'
    );
    if (!$stmt) return;
    $stmt->bind_param('issssisss',
        $actor_id, $actor_name, $actor_role,
        $action,
        $target_type, $target_id, $target_name,
        $details, $ip
    );
    $stmt->execute();
    $stmt->close();
}

// ── Helper: check if enrollment tables exist ─────────────────
function enrollment_tables_exist(mysqli $conn): bool {
    return $conn->query("SHOW TABLES LIKE 'pre_registrations'")->num_rows > 0;
}

// ── Helper: is the current user admin OR staff ────────────────
function is_admin_or_staff(): bool {
    $role = $_SESSION['role'] ?? '';
    return $role === 'admin' || $role === 'staff';
}

// ── Helper: show setup prompt and exit if tables missing ─────
function require_enrollment_tables(mysqli $conn): void {
    if (!enrollment_tables_exist($conn)) {
        $setup = str_contains($_SERVER['PHP_SELF'] ?? '', 'enrollment_tab')
            ? '../shared/full_setup.php'
            : 'shared/full_setup.php';
        die('
        <div style="font-family:\'Segoe UI\',sans-serif;padding:40px;max-width:480px;
                    margin:60px auto;background:#fff7ed;border:1px solid #fcd34d;
                    border-radius:12px;text-align:center;">
          <h2 style="color:#d97706;margin-bottom:10px;">&#9888; Database Not Set Up</h2>
          <p style="color:#555;font-size:.9rem;line-height:1.6;margin-bottom:20px;">
            Enrollment tables are missing.<br>Run the one-click database setup first.
          </p>
          <a href="' . $setup . '"
             style="background:#2563eb;color:#fff;padding:10px 24px;border-radius:8px;
                    text-decoration:none;font-weight:600;font-size:.9rem;">
            &#9881; Run Full Setup
          </a>
        </div>');
    }
}
?>
