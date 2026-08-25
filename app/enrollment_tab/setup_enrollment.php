<?php
// ============================================================
//  SETUP_ENROLLMENT.PHP
//  Run ONCE to create all enrollment tables and seed sections.
//  Visit: http://localhost/sms/app/enrollment_tab/setup_enrollment.php
//  DELETE this file after running it.
// ============================================================
require_once __DIR__ . '/../shared/db.php';

$errors = [];
$done   = [];

$tables = [

'pre_registrations' => "CREATE TABLE IF NOT EXISTS pre_registrations (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    first_name    VARCHAR(100) NOT NULL,
    last_name     VARCHAR(100) NOT NULL,
    email         VARCHAR(150) NOT NULL,
    phone         VARCHAR(20)  NOT NULL,
    birthday      DATE         NOT NULL,
    course        VARCHAR(150) NOT NULL,
    year_level    VARCHAR(50)  NOT NULL,
    prev_school   VARCHAR(200) DEFAULT NULL,
    status        ENUM('Pending','Approved','Rejected','Enrolled') NOT NULL DEFAULT 'Pending',
    remarks       TEXT         DEFAULT NULL,
    submitted_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'enrollment_documents' => "CREATE TABLE IF NOT EXISTS enrollment_documents (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pre_reg_id      INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    document_type   ENUM('Form137','BirthCertificate','GoodMoral','MedicalCert','IDPhoto','Other') NOT NULL,
    file_name       VARCHAR(255) NOT NULL,
    file_path       VARCHAR(500) NOT NULL,
    file_size       INT UNSIGNED NOT NULL DEFAULT 0,
    status          ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    uploaded_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pre_reg_id) REFERENCES pre_registrations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)    REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'sections' => "CREATE TABLE IF NOT EXISTS sections (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    section_code    VARCHAR(20)  NOT NULL UNIQUE,
    course          VARCHAR(150) NOT NULL,
    year_level      VARCHAR(50)  NOT NULL,
    adviser_name    VARCHAR(150) DEFAULT NULL,
    max_capacity    INT UNSIGNED NOT NULL DEFAULT 40,
    current_count   INT UNSIGNED NOT NULL DEFAULT 0,
    semester        VARCHAR(20)  NOT NULL DEFAULT '1st',
    school_year     VARCHAR(20)  NOT NULL DEFAULT '2025-2026',
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'enrollments' => "CREATE TABLE IF NOT EXISTS enrollments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pre_reg_id      INT UNSIGNED NOT NULL,
    student_id      INT UNSIGNED DEFAULT NULL,
    id_number       VARCHAR(20)  NOT NULL UNIQUE,
    course          VARCHAR(150) NOT NULL,
    year_level      VARCHAR(50)  NOT NULL,
    section         VARCHAR(50)  DEFAULT NULL,
    semester        VARCHAR(20)  NOT NULL DEFAULT '1st',
    school_year     VARCHAR(20)  NOT NULL DEFAULT '2025-2026',
    is_cross        TINYINT(1)   NOT NULL DEFAULT 0,
    cross_from      VARCHAR(150) DEFAULT NULL,
    validated_by    INT UNSIGNED DEFAULT NULL,
    validated_at    TIMESTAMP    NULL DEFAULT NULL,
    enrolled_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pre_reg_id)   REFERENCES pre_registrations(id),
    FOREIGN KEY (student_id)   REFERENCES students(id) ON DELETE SET NULL,
    FOREIGN KEY (validated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

'waiting_list' => "CREATE TABLE IF NOT EXISTS waiting_list (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pre_reg_id      INT UNSIGNED NOT NULL,
    course          VARCHAR(150) NOT NULL,
    year_level      VARCHAR(50)  NOT NULL,
    queue_position  INT UNSIGNED NOT NULL DEFAULT 0,
    reason          VARCHAR(255) DEFAULT 'Section full',
    status          ENUM('Waiting','Promoted','Cancelled') NOT NULL DEFAULT 'Waiting',
    queued_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pre_reg_id) REFERENCES pre_registrations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

];

// Create tables in dependency order
foreach ($tables as $name => $sql) {
    if ($conn->query($sql)) {
        $done[] = "Created table: <strong>$name</strong>";
    } else {
        $errors[] = "Failed $name: " . $conn->error;
    }
}

// Seed sections
$seed = "INSERT IGNORE INTO sections (section_code, course, year_level, adviser_name, max_capacity, current_count) VALUES
('BSIT-1A','Bachelor of Science in Information Technology','1st Year','Prof. Santos',40,28),
('BSIT-2A','Bachelor of Science in Information Technology','2nd Year','Prof. Reyes',40,35),
('BSIT-3A','Bachelor of Science in Information Technology','3rd Year','Prof. Cruz',40,40),
('BSIT-4A','Bachelor of Science in Information Technology','4th Year','Prof. Garcia',40,38),
('BSCS-1A','Bachelor of Science in Computer Science','1st Year','Prof. Navarro',40,22),
('BSCS-2A','Bachelor of Science in Computer Science','2nd Year','Prof. Fernandez',40,30),
('BSCS-3A','Bachelor of Science in Computer Science','3rd Year','Prof. Ramos',40,40),
('BSCS-4A','Bachelor of Science in Computer Science','4th Year','Prof. Torres',40,37),
('BSIS-2A','Bachelor of Science in Information Systems','2nd Year','Prof. Mendoza',40,25),
('BSIS-3A','Bachelor of Science in Information Systems','3rd Year','Prof. Pascual',40,18)";

if ($conn->query($seed)) {
    $done[] = 'Seeded <strong>sections</strong> table with 10 sample sections.';
} else {
    $errors[] = 'Seed sections: ' . $conn->error;
}

// Create uploads directory
$uploadDir = __DIR__ . '/uploads';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
    file_put_contents($uploadDir . '/.htaccess', "Options -Indexes\nDeny from all\n");
    $done[] = 'Created <strong>uploads/</strong> directory with access protection.';
} else {
    $done[] = '<strong>uploads/</strong> directory already exists.';
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <title>Enrollment Setup</title>
  <style>
    body { font-family:'Segoe UI',sans-serif; max-width:580px; margin:60px auto; padding:0 20px; }
    .ok  { background:#dcfce7; color:#16a34a; border:1px solid #86efac; padding:16px 20px; border-radius:10px; margin-bottom:16px; }
    .err { background:#fee2e2; color:#dc2626; border:1px solid #fca5a5; padding:16px 20px; border-radius:10px; }
    ul   { margin:10px 0 0 18px; line-height:2; }
    a    { color:#2563eb; }
    h2   { font-size:1.2rem; margin-bottom:20px; color:#1a1a2e; }
  </style>
</head>
<body>
  <h2>Enrollment System — Database Setup</h2>

  <?php if (empty($errors)): ?>
  <div class="ok">
    <strong>Setup complete!</strong>
    <ul><?php foreach ($done as $d) echo "<li>$d</li>"; ?></ul>
    <br>
    <a href="enrollment_dashboard.php">Go to Enrollment Dashboard &rarr;</a><br><br>
    <em style="font-size:0.8rem;">Delete <code>setup_enrollment.php</code> after confirming everything works.</em>
  </div>
  <?php else: ?>
  <div class="err">
    <strong>Errors occurred:</strong>
    <ul><?php foreach ($errors as $e) echo "<li>$e</li>"; ?></ul>
  </div>
  <?php if ($done): ?>
  <div class="ok" style="margin-top:12px;">
    <strong>Completed steps:</strong>
    <ul><?php foreach ($done as $d) echo "<li>$d</li>"; ?></ul>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</body>
</html>
