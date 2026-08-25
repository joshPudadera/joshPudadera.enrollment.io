<?php
// ── Database connection ──────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'root');       // default XAMPP user
define('DB_PASS', '');           // default XAMPP password (empty)
define('DB_NAME', 'sms_db');

// Try connecting without selecting a DB first, so we can create it if missing
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

// Create database if it doesn't exist
$conn->query("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$conn->select_db(DB_NAME);

if ($conn->errno) {
    die('<p style="color:red;font-family:sans-serif;padding:20px;">
         Could not select database <strong>' . DB_NAME . '</strong>.<br>
         <a href="../shared/full_setup.php">Click here to run the database setup.</a></p>');
}

$conn->set_charset('utf8mb4');

// Ensure ref_number column exists on pre_registrations
$conn->query("ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS ref_number VARCHAR(50) DEFAULT NULL");

// ── Helper: check if enrollment tables exist ─────────────────
function enrollment_tables_exist(mysqli $conn): bool {
    return $conn->query("SHOW TABLES LIKE 'pre_registrations'")->num_rows > 0;
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
