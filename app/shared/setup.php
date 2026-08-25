<?php
// ============================================================
//  SETUP.PHP  (shared/)  — run ONCE, then delete.
//  Visit: http://localhost/sms/app/shared/setup.php
// ============================================================
require_once __DIR__ . '/db.php';

$errors = [];

$sql = "CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(60)   NOT NULL UNIQUE,
    email         VARCHAR(150)  NOT NULL UNIQUE,
    first_name    VARCHAR(100)  NOT NULL,
    last_name     VARCHAR(100)  NOT NULL,
    password_hash VARCHAR(255)  NOT NULL,
    role          ENUM('admin','student') NOT NULL DEFAULT 'student',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if (!$conn->query($sql)) $errors[] = 'Create table failed: ' . $conn->error;

$hash = password_hash('Admin@1234', PASSWORD_DEFAULT);
$stmt = $conn->prepare(
    "INSERT IGNORE INTO users (username,email,first_name,last_name,password_hash,role)
     VALUES ('admin','admin@bcp.edu.ph','Admin','User',?,'admin')"
);
$stmt->bind_param('s', $hash);
if (!$stmt->execute()) $errors[] = 'Seed admin failed: ' . $stmt->error;
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"/><title>Setup</title>
<style>body{font-family:sans-serif;max-width:520px;margin:60px auto;padding:0 20px}
.ok{background:#dcfce7;color:#16a34a;border:1px solid #86efac;padding:14px 18px;border-radius:8px}
.err{background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;padding:14px 18px;border-radius:8px}
ul{margin:8px 0 0 18px}a{color:#2563eb}</style>
</head>
<body>
<?php if (empty($errors)): ?>
  <div class="ok"><strong>Setup complete!</strong><br><br>
    Default credentials:<br>
    Username: <code>admin</code> &nbsp; Password: <code>Admin@1234</code><br><br>
    <a href="../auth/signin.php">Go to Sign In &rarr;</a><br><br>
    <em>Delete <code>setup.php</code> after logging in.</em>
  </div>
<?php else: ?>
  <div class="err"><strong>Errors:</strong><ul><?php foreach($errors as $e) echo "<li>$e</li>"; ?></ul></div>
<?php endif; ?>
</body></html>
