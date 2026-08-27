<?php
// ============================================================
//  FULL_SETUP.PHP  — BCP SMS
//  One-click setup. Safe to re-run — uses DROP+CREATE when
//  InnoDB corruption is detected (errno 1932 / "not exist in engine").
//  Visit: http://localhost/sms/app/shared/full_setup.php
// ============================================================

// Disable exceptions — we handle errors manually
mysqli_report(MYSQLI_REPORT_OFF);

// $host = 'localhost';
// $user = 'root';
// $pass = '';
// $db   = 'sms_db';

// $conn = new mysqli($host, $user, $pass);
// $errors = [];
// $done   = [];

$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$db   = getenv('DB_NAME') ?: 'sms_db';
$port = (int)(getenv('DB_PORT') ?: 3306);

$conn = new mysqli($host, $user, $pass, $db, $port);

$errors = [];
$done   = [];

if ($conn->connect_error) {
    die('<p style="color:red;font-family:sans-serif;padding:20px;">
         Cannot connect to MySQL: ' . $conn->connect_error . '<br>
         Make sure environment variables DB_HOST, DB_USER, DB_PASS, DB_NAME are set.</p>');
}

$conn->set_charset('utf8mb4');

// ── Helper ───────────────────────────────────────────────────
function run(mysqli $conn, string $sql, string $label, array &$done, array &$errors): bool {
    $result = $conn->query($sql);
    if ($result !== false) {
        $done[] = $label;
        return true;
    }
    $errors[] = "$label — [{$conn->errno}] {$conn->error}";
    return false;
}

// ── 1. Verify database connection is usable ──────────────────
// On hosted platforms the DB already exists — no CREATE DATABASE needed.
// On local XAMPP we try to create it but suppress the error if it exists.
@$conn->query("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$conn->select_db($db);
$done[] = 'Database <strong>' . htmlspecialchars($db) . '</strong> ready';

// ── 2. Repair InnoDB if corrupted ────────────────────────────
$corrupt = false;
$probe   = $conn->query("SELECT 1 FROM users LIMIT 1");
if ($probe === false && $conn->errno === 1932) {
    $corrupt = true;
}
if ($corrupt || isset($_GET['force_rebuild'])) {
    $done[] = '<strong style="color:#d97706">⚠ InnoDB corruption detected — dropping all tables for clean rebuild…</strong>';
    // Drop in reverse FK order
    $drops = [
        'login_tokens','grades','attendance','enrollment_documents',
        'enrollments','waiting_list','pre_registrations','announcements',
        'students','sections','subjects','users',
    ];
    $conn->query("SET FOREIGN_KEY_CHECKS=0");
    foreach ($drops as $tbl) {
        $conn->query("DROP TABLE IF EXISTS `$tbl`");
    }
    $conn->query("SET FOREIGN_KEY_CHECKS=1");
    $done[] = 'All tables dropped — rebuilding from scratch…';
}

// ── 3. users ─────────────────────────────────────────────────
run($conn, "CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(60)   NOT NULL UNIQUE,
    email         VARCHAR(150)  NOT NULL UNIQUE,
    first_name    VARCHAR(100)  NOT NULL,
    last_name     VARCHAR(100)  NOT NULL,
    password_hash VARCHAR(255)  NOT NULL,
    role          ENUM('admin','student') NOT NULL DEFAULT 'student',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
'Table <strong>users</strong>', $done, $errors);

// ── 4. students ───────────────────────────────────────────────
run($conn, "CREATE TABLE IF NOT EXISTS students (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name   VARCHAR(100)  NOT NULL,
    last_name    VARCHAR(100)  NOT NULL,
    birthday     DATE          NOT NULL,
    course       VARCHAR(150)  NOT NULL,
    year_level   VARCHAR(50)   NOT NULL,
    section      VARCHAR(50)   NOT NULL,
    phone        VARCHAR(20)   NOT NULL,
    status       ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
'Table <strong>students</strong>', $done, $errors);

// ── 5. sections ───────────────────────────────────────────────
run($conn, "CREATE TABLE IF NOT EXISTS sections (
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
'Table <strong>sections</strong>', $done, $errors);

// ── 6. subjects ───────────────────────────────────────────────
run($conn, "CREATE TABLE IF NOT EXISTS subjects (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code       VARCHAR(20)  NOT NULL UNIQUE,
    name       VARCHAR(200) NOT NULL,
    units      TINYINT      NOT NULL DEFAULT 3,
    year_level VARCHAR(50)  NOT NULL DEFAULT 'All',
    type       ENUM('Lec','Lab','Lec/Lab') NOT NULL DEFAULT 'Lec',
    is_active  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'Table <strong>subjects</strong>', $done, $errors);

// ── 7. pre_registrations ──────────────────────────────────────
run($conn, "CREATE TABLE IF NOT EXISTS pre_registrations (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NULL DEFAULT NULL,
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
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'Table <strong>pre_registrations</strong>', $done, $errors);

// ── 8. enrollment_documents ───────────────────────────────────
run($conn, "CREATE TABLE IF NOT EXISTS enrollment_documents (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pre_reg_id        INT UNSIGNED NOT NULL,
    user_id           INT UNSIGNED NOT NULL,
    document_type     ENUM('Form137','BirthCertificate','GoodMoral','MedicalCert','IDPhoto','Other') NOT NULL,
    file_name         VARCHAR(255) NOT NULL,
    file_path         VARCHAR(500) NOT NULL,
    file_size         INT UNSIGNED NOT NULL DEFAULT 0,
    status            ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
    ai_result         JSON         DEFAULT NULL,
    ai_inspected_at   TIMESTAMP    NULL DEFAULT NULL,
    uploaded_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pre_reg_id) REFERENCES pre_registrations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)    REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'Table <strong>enrollment_documents</strong>', $done, $errors);

// ── 9. enrollments ────────────────────────────────────────────
run($conn, "CREATE TABLE IF NOT EXISTS enrollments (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pre_reg_id    INT UNSIGNED NOT NULL,
    student_id    INT UNSIGNED DEFAULT NULL,
    id_number     VARCHAR(20)  NOT NULL UNIQUE,
    course        VARCHAR(150) NOT NULL,
    year_level    VARCHAR(50)  NOT NULL,
    section       VARCHAR(50)  DEFAULT NULL,
    semester      VARCHAR(20)  NOT NULL DEFAULT '1st',
    school_year   VARCHAR(20)  NOT NULL DEFAULT '2025-2026',
    is_cross      TINYINT(1)   NOT NULL DEFAULT 0,
    cross_from    VARCHAR(150) DEFAULT NULL,
    validated_by  INT UNSIGNED DEFAULT NULL,
    validated_at  TIMESTAMP    NULL DEFAULT NULL,
    enrolled_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pre_reg_id)   REFERENCES pre_registrations(id),
    FOREIGN KEY (student_id)   REFERENCES students(id) ON DELETE SET NULL,
    FOREIGN KEY (validated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'Table <strong>enrollments</strong>', $done, $errors);

// ── 10. waiting_list ──────────────────────────────────────────
run($conn, "CREATE TABLE IF NOT EXISTS waiting_list (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pre_reg_id     INT UNSIGNED NOT NULL,
    course         VARCHAR(150) NOT NULL,
    year_level     VARCHAR(50)  NOT NULL,
    queue_position INT UNSIGNED NOT NULL DEFAULT 0,
    reason         VARCHAR(255) DEFAULT 'Section full',
    status         ENUM('Waiting','Promoted','Cancelled') NOT NULL DEFAULT 'Waiting',
    queued_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pre_reg_id) REFERENCES pre_registrations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'Table <strong>waiting_list</strong>', $done, $errors);

// ── 11. login_tokens ──────────────────────────────────────────
run($conn, "CREATE TABLE IF NOT EXISTS login_tokens (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    token      VARCHAR(64)  NOT NULL UNIQUE,
    used       TINYINT(1)   NOT NULL DEFAULT 0,
    expires_at TIMESTAMP    NOT NULL,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'Table <strong>login_tokens</strong>', $done, $errors);

// ── 12. grades ────────────────────────────────────────────────
run($conn, "CREATE TABLE IF NOT EXISTS grades (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id  INT UNSIGNED NOT NULL,
    subject_id  INT UNSIGNED NOT NULL,
    midterm     DECIMAL(5,2) DEFAULT NULL,
    finals      DECIMAL(5,2) DEFAULT NULL,
    final_grade DECIMAL(5,2) DEFAULT NULL,
    remarks     ENUM('Passed','Failed','Inc.','Dropped') DEFAULT NULL,
    school_year VARCHAR(20)  NOT NULL DEFAULT '2025-2026',
    semester    VARCHAR(20)  NOT NULL DEFAULT '1st',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_student_subject (student_id, subject_id, school_year, semester),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'Table <strong>grades</strong>', $done, $errors);

// ── 13. attendance ────────────────────────────────────────────
run($conn, "CREATE TABLE IF NOT EXISTS attendance (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id  INT UNSIGNED NOT NULL,
    subject_id  INT UNSIGNED NULL DEFAULT NULL,
    date        DATE         NOT NULL,
    status      ENUM('Present','Absent','Late','Excused') NOT NULL DEFAULT 'Present',
    notes       VARCHAR(255) DEFAULT NULL,
    recorded_by INT UNSIGNED NULL DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attendance (student_id, subject_id, date),
    FOREIGN KEY (student_id)  REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'Table <strong>attendance</strong>', $done, $errors);

// ── 14. announcements ─────────────────────────────────────────
run($conn, "CREATE TABLE IF NOT EXISTS announcements (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title     VARCHAR(255) NOT NULL,
    content   TEXT         NOT NULL,
    type      ENUM('info','success','warning','event') NOT NULL DEFAULT 'info',
    posted_by INT UNSIGNED NULL DEFAULT NULL,
    is_active TINYINT(1)   NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (posted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'Table <strong>announcements</strong>', $done, $errors);

// ════════════════════════════════════════════════════════════
//  COLUMN ADDITIONS (idempotent — safe to re-run)
// ════════════════════════════════════════════════════════════
$alters = [
    "ALTER TABLE pre_registrations ADD COLUMN IF NOT EXISTS ref_number VARCHAR(50) DEFAULT NULL",
    "ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS ai_result JSON DEFAULT NULL",
    "ALTER TABLE enrollment_documents ADD COLUMN IF NOT EXISTS ai_inspected_at TIMESTAMP NULL DEFAULT NULL",
    "ALTER TABLE pre_registrations MODIFY COLUMN user_id INT UNSIGNED NULL DEFAULT NULL",
];
foreach ($alters as $sql) {
    @$conn->query($sql); // suppress — columns may already exist
}
$done[] = 'Schema columns verified';

// ════════════════════════════════════════════════════════════
//  SEED DATA
// ════════════════════════════════════════════════════════════

// ── Admin account ─────────────────────────────────────────────
$hash = password_hash('Admin@1234', PASSWORD_DEFAULT);
$stmt = $conn->prepare("INSERT IGNORE INTO users (username,email,first_name,last_name,password_hash,role)
    VALUES ('admin','admin@bcp.edu.ph','Admin','User',?,'admin')");
if ($stmt) {
    $stmt->bind_param('s', $hash);
    if ($stmt->execute()) $done[] = 'Admin account <code>admin / Admin@1234</code>';
    else $errors[] = 'Seed admin: ' . $stmt->error;
    $stmt->close();
} else {
    $errors[] = 'Prepare admin seed failed: ' . $conn->error;
}

// ── Sample students ───────────────────────────────────────────
$_r = $conn->query("SELECT COUNT(*) c FROM students");
$student_count = $_r ? (int)$_r->fetch_assoc()['c'] : 0;
if ($student_count === 0) {
    run($conn, "INSERT INTO students (first_name,last_name,birthday,course,year_level,section,phone,status) VALUES
('Juswa','Pudaders','2004-06-20','Bachelor of Science in Information Technology','4th Year','41018','09999999999','Active'),
('Maria','Santos','2003-03-15','Bachelor of Science in Computer Science','3rd Year','31011','09111111111','Inactive'),
('Jose','Reyes','2002-09-10','Bachelor of Science in Information Technology','4th Year','41019','09222222222','Active'),
('Ana','Cruz','2005-01-25','Bachelor of Science in Information Systems','2nd Year','21005','09333333333','Active'),
('Carlos','Garcia','2001-11-30','Bachelor of Science in Computer Science','4th Year','41020','09444444444','Inactive'),
('Liza','Dela Cruz','2003-07-14','Bachelor of Science in Information Technology','3rd Year','31012','09555555501','Active'),
('Ramon','Villanueva','2002-04-22','Bachelor of Science in Computer Science','4th Year','41021','09555555502','Active'),
('Patricia','Aquino','2004-11-05','Bachelor of Science in Information Systems','2nd Year','21006','09555555503','Inactive'),
('Mark','Bautista','2003-08-30','Bachelor of Science in Information Technology','3rd Year','31013','09555555504','Active'),
('Jenny','Navarro','2005-02-18','Bachelor of Science in Computer Science','1st Year','11001','09555555505','Active'),
('Rico','Fernandez','2001-12-09','Bachelor of Science in Information Technology','4th Year','41022','09555555506','Inactive'),
('Sheila','Ramos','2004-05-03','Bachelor of Science in Information Systems','2nd Year','21007','09555555507','Active'),
('Angelo','Torres','2002-10-27','Bachelor of Science in Computer Science','4th Year','41023','09555555508','Active'),
('Claire','Mendoza','2003-01-16','Bachelor of Science in Information Technology','3rd Year','31014','09555555509','Inactive'),
('Danilo','Pascual','2000-09-08','Bachelor of Science in Computer Science','4th Year','41024','09555555510','Active'),
('Rowena','Espinosa','2005-06-21','Bachelor of Science in Information Systems','1st Year','11002','09555555511','Active'),
('Freddie','Castillo','2002-03-13','Bachelor of Science in Information Technology','4th Year','41025','09555555512','Inactive'),
('Aileen','Morales','2004-09-29','Bachelor of Science in Computer Science','2nd Year','21008','09555555513','Active'),
('Ronnie','Aguilar','2001-07-04','Bachelor of Science in Information Technology','4th Year','41026','09555555514','Active'),
('Mylene','Domingo','2003-12-11','Bachelor of Science in Information Systems','3rd Year','31015','09555555515','Inactive'),
('Bryan','Lacson','2004-04-07','Bachelor of Science in Computer Science','2nd Year','21009','09555555516','Active'),
('Rosalie','Ilagan','2002-08-19','Bachelor of Science in Information Technology','4th Year','41027','09555555517','Active'),
('Eduardo','Pineda','2005-03-25','Bachelor of Science in Computer Science','1st Year','11003','09555555518','Inactive'),
('Vanessa','Ocampo','2003-10-02','Bachelor of Science in Information Systems','3rd Year','31016','09555555519','Active'),
('Kenneth','Bondoc','2001-05-17','Bachelor of Science in Information Technology','4th Year','41028','09555555520','Active')",
    'Seeded <strong>25 sample students</strong>', $done, $errors);
} else {
    $done[] = "Students table already has <strong>$student_count</strong> records — skipped.";
}

// ── Sample sections ───────────────────────────────────────────
run($conn, "INSERT IGNORE INTO sections (section_code,course,year_level,adviser_name,max_capacity,current_count) VALUES
('BSIT-1A','Bachelor of Science in Information Technology','1st Year','Prof. Santos',40,28),
('BSIT-2A','Bachelor of Science in Information Technology','2nd Year','Prof. Reyes',40,35),
('BSIT-3A','Bachelor of Science in Information Technology','3rd Year','Prof. Cruz',40,40),
('BSIT-4A','Bachelor of Science in Information Technology','4th Year','Prof. Garcia',40,38),
('BSCS-1A','Bachelor of Science in Computer Science','1st Year','Prof. Navarro',40,22),
('BSCS-2A','Bachelor of Science in Computer Science','2nd Year','Prof. Fernandez',40,30),
('BSCS-3A','Bachelor of Science in Computer Science','3rd Year','Prof. Ramos',40,40),
('BSCS-4A','Bachelor of Science in Computer Science','4th Year','Prof. Torres',40,37),
('BSIS-2A','Bachelor of Science in Information Systems','2nd Year','Prof. Mendoza',40,25),
('BSIS-3A','Bachelor of Science in Information Systems','3rd Year','Prof. Pascual',40,18)",
'Seeded <strong>10 sections</strong>', $done, $errors);

// ── Sample subjects ───────────────────────────────────────────
$_r = $conn->query("SELECT COUNT(*) c FROM subjects");
$subj_count = $_r ? (int)$_r->fetch_assoc()['c'] : 0;
if ($subj_count === 0) {
    run($conn, "INSERT IGNORE INTO subjects (code,name,units,year_level,type) VALUES
        ('CS 101','Introduction to Computing',3,'1st Year','Lec'),
        ('CS 102','Computer Programming 1',3,'1st Year','Lab'),
        ('CS 201','Data Structures & Algorithms',3,'2nd Year','Lec'),
        ('IT 301','Web Systems & Technologies',3,'3rd Year','Lec/Lab'),
        ('IT 302','Database Management Systems',3,'3rd Year','Lec/Lab'),
        ('IT 401','Systems Integration & Architecture',3,'4th Year','Lec'),
        ('GE 001','Understanding the Self',3,'All','Lec'),
        ('PE 001','Physical Education 1',2,'1st Year','Lec'),
        ('NSTP 1','National Service Training Program',3,'1st Year','Lec')",
        'Seeded <strong>9 sample subjects</strong>', $done, $errors);
} else {
    $done[] = "Subjects table already has <strong>$subj_count</strong> records — skipped.";
}

// ── Sample announcements ──────────────────────────────────────
$_r = $conn->query("SELECT COUNT(*) c FROM announcements");
$ann_count = $_r ? (int)$_r->fetch_assoc()['c'] : 0;
if ($ann_count === 0) {
    run($conn, "INSERT INTO announcements (title,content,type) VALUES
        ('Enrollment for A.Y. 2025-2026 is now open','Online pre-registration is now available. Submit your application through the Enrollment module and upload the required documents.','info'),
        ('Orientation Day — August 5, 2025','All new and returning students are required to attend the school orientation. Venue: BCP Main Gymnasium, 8:00 AM.','event'),
        ('Deadline for document submission: July 31, 2025','All applicants must complete document uploads before the deadline to avoid delays in enrollment processing.','warning')",
        'Seeded <strong>3 sample announcements</strong>', $done, $errors);
} else {
    $done[] = "Announcements table already has <strong>$ann_count</strong> records — skipped.";
}

// ── Uploads directories ───────────────────────────────────────
foreach ([
    __DIR__ . '/../enrollment_tab/uploads',
    __DIR__ . '/../requirements/uploads',
] as $uploadDir) {
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
        file_put_contents($uploadDir . '/.htaccess', "Options -Indexes\nDeny from all\n");
        $done[] = 'Created <strong>' . basename(dirname($uploadDir)) . '/uploads/</strong>';
    } else {
        $done[] = '<strong>' . basename(dirname($uploadDir)) . '/uploads/</strong> already exists';
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Full Setup – BCP SMS</title>
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Segoe UI',sans-serif;background:#f0f4f8;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
    .card{background:#fff;border-radius:14px;padding:32px 36px;max-width:600px;width:100%;box-shadow:0 4px 24px rgba(0,0,0,.1)}
    h2{font-size:1.2rem;color:#1a1a2e;margin-bottom:20px;display:flex;align-items:center;gap:10px}
    .ok {background:#dcfce7;color:#16a34a;border:1px solid #86efac;border-radius:8px;padding:14px 18px;margin-bottom:16px}
    .err{background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;border-radius:8px;padding:14px 18px;margin-bottom:16px}
    ul{margin:10px 0 0 20px;line-height:2.2;font-size:.85rem}
    .creds{background:#eff6ff;border-radius:8px;padding:14px 18px;margin-top:16px;font-size:.85rem;line-height:1.8;border:1px solid #bfdbfe}
    .creds strong{color:#1d4ed8}
    .btn{display:inline-flex;align-items:center;gap:8px;margin-top:18px;background:#2563eb;color:#fff;padding:11px 24px;border-radius:8px;text-decoration:none;font-weight:600;font-size:.9rem}
    .btn-warn{background:#d97706}
    .btn:hover{opacity:.9}
    .warn{font-size:.75rem;color:#888;margin-top:10px}
    code{background:#f3f4f6;padding:2px 6px;border-radius:4px;font-size:.82rem}

    .modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.55);display:none;align-items:center;justify-content:center;z-index:9999;padding:16px;}
    .modal-overlay.active{display:flex;}
    .modal{background:#fff;border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,.25);width:100%;max-width:440px;overflow:hidden;animation:modPop .18s ease-out;}
    @keyframes modPop{from{transform:scale(.95);opacity:0;}to{transform:scale(1);opacity:1;}}
    .modal-header{padding:14px 20px;border-bottom:1px solid #f0f2f5;display:flex;align-items:center;justify-content:space-between;background:#fafbfc;}
    .modal-header span{font-weight:700;color:#1a3a8c;font-size:.92rem;}
    .modal-close{background:none;border:none;font-size:1.5rem;color:#94a3b8;cursor:pointer;line-height:1;padding:0;width:28px;height:28px;display:flex;align-items:center;justify-content:center;border-radius:6px;}
    .modal-close:hover{background:#f1f5f9;color:#475569;}
    .modal-body{padding:20px;}
    .modal-footer{padding:14px 20px;border-top:1px solid #f0f2f5;display:flex;gap:10px;justify-content:flex-end;background:#fafbfc;}
    .modal-footer.modal-footer-split{justify-content:space-between;}
    .btn-modal-submit,.btn-modal-confirm{background:#2563eb;color:#fff;border:none;padding:9px 22px;border-radius:8px;font-weight:700;cursor:pointer;font-size:.82rem;}
    .btn-modal-submit:hover,.btn-modal-confirm:hover{background:#1d4ed8;}
    .btn-modal-cancel{background:#fff;color:#64748b;border:1.5px solid #e2e8f0;padding:9px 22px;border-radius:8px;font-weight:700;cursor:pointer;font-size:.82rem;}
    .btn-modal-cancel:hover{background:#f8fafc;border-color:#cbd5e1;color:#475569;}
  </style>
</head>
<body>
  <div class="card">
    <h2>&#9881; BCP SMS — Full Database Setup</h2>

    <?php if (empty($errors)): ?>
    <div class="ok">
      <strong>&#10003; Setup complete!</strong>
      <ul><?php foreach ($done as $d) echo "<li>$d</li>"; ?></ul>
    </div>
    <div class="creds">
      <strong>Default Login Credentials</strong><br>
      Username: <code>admin</code><br>
      Password: <code>Admin@1234</code><br>
      Database: <code>sms_db</code> @ <code>localhost</code>
    </div>
    <a class="btn" href="../auth/signin.php">&#8594; Go to Sign In</a>
    <p class="warn">&#9888; Delete or rename <code>full_setup.php</code> after logging in.</p>

    <?php else: ?>
    <div class="err">
      <strong>&#9888; Errors occurred:</strong>
      <ul><?php foreach ($errors as $e) echo "<li>$e</li>"; ?></ul>
    </div>
    <?php if ($done): ?>
    <div class="ok">
      <strong>Completed steps:</strong>
      <ul><?php foreach ($done as $d) echo "<li>$d</li>"; ?></ul>
    </div>
    <?php endif; ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:4px;">
      <a class="btn" href="full_setup.php">&#8635; Retry Setup</a>
      <a class="btn btn-warn btn-force-rebuild" href="#"
         data-url="full_setup.php?force_rebuild=1">
        &#9888; Force Rebuild (Drop All)
      </a>
    </div>
    <p class="warn" style="margin-top:12px">Use <strong>Force Rebuild</strong> only if you see InnoDB corruption errors.</p>
    <?php endif; ?>
  </div>

<script>
function openModal(id){var el=document.getElementById(id);if(el)el.classList.add('active');}
function closeModal(id){var el=document.getElementById(id);if(el)el.classList.remove('active');}
document.addEventListener('click',function(e){
  var cb=e.target.closest('[data-close]');if(cb){closeModal(cb.dataset.close);return;}
  if(e.target.classList.contains('modal-overlay'))e.target.classList.remove('active');
});
document.addEventListener('keydown',function(e){
  if(e.key==='Escape'){document.querySelectorAll('.modal-overlay.active').forEach(function(o){o.classList.remove('active');});}
});
document.addEventListener('click',function(e){if(e.target.closest('.modal'))e.stopPropagation();},true);
function escapeHtml(str){var d=document.createElement('div');d.appendChild(document.createTextNode(str));return d.innerHTML;}
var _fsuCb=null;
function ensureFsuModals(){
  if(!document.getElementById('fsuAlertOverlay')){
    var h=''
    +'<div class="modal-overlay" id="fsuAlertOverlay">'
    +'  <div class="modal">'
    +'    <div class="modal-header"><span id="fsuAlertTitle">Alert</span><button class="modal-close" data-close="fsuAlertOverlay">&times;</button></div>'
    +'    <div class="modal-body" id="fsuAlertBody" style="padding:24px 22px;"></div>'
    +'    <div class="modal-footer"><button class="btn-modal-submit" data-close="fsuAlertOverlay" style="flex:0 0 auto;padding:10px 48px;">OK</button></div>'
    +'  </div></div>'
    +'<div class="modal-overlay" id="fsuConfirmOverlay">'
    +'  <div class="modal">'
    +'    <div class="modal-header"><span id="fsuConfirmTitle">Confirm</span><button class="modal-close" data-close="fsuConfirmOverlay">&times;</button></div>'
    +'    <div class="modal-body" id="fsuConfirmBody" style="padding:24px 22px 12px;"></div>'
    +'    <div class="modal-footer modal-footer-split">'
    +'      <button class="btn-modal-cancel" id="fsuConfirmCancel">Cancel</button>'
    +'      <button class="btn-modal-confirm" id="fsuConfirmOk">Confirm</button>'
    +'    </div></div></div>';
    var d=document.createElement('div');d.innerHTML=h;
    while(d.firstChild)document.body.appendChild(d.firstChild);
  }
}
function showConfirmModal(msg,onConfirm,title){
  ensureFsuModals();
  document.getElementById('fsuConfirmTitle').textContent=title||'Confirm Action';
  document.getElementById('fsuConfirmBody').innerHTML='<div style="display:flex;gap:12px;align-items:flex-start;">'
    +'<div style="color:#d97706;font-size:1.6rem;flex-shrink:0;margin-top:2px;">⚠</div>'
    +'<div style="flex:1;font-size:.88rem;color:#333;line-height:1.55;">'+escapeHtml(msg)+'</div></div>';
  _fsuCb=onConfirm||null;
  var ok=document.getElementById('fsuConfirmOk'),cancel=document.getElementById('fsuConfirmCancel');
  if(!ok._hasH){ok.addEventListener('click',function(){
    closeModal('fsuConfirmOverlay');var cb=_fsuCb;_fsuCb=null;
    if(cb)setTimeout(function(){cb(true);},50);
  });ok._hasH=true;}
  if(!cancel._hasH){cancel.addEventListener('click',function(){
    closeModal('fsuConfirmOverlay');var cb=_fsuCb;_fsuCb=null;
    if(cb)setTimeout(function(){cb(false);},50);
  });cancel._hasH=true;}
  openModal('fsuConfirmOverlay');
}

document.querySelectorAll('.btn-force-rebuild').forEach(function(btn){
  btn.addEventListener('click',function(e){
    e.preventDefault();
    var url=btn.getAttribute('data-url');
    showConfirmModal('This will DROP ALL TABLES and reseed. Continue?',function(confirmed){
      if(confirmed)window.location.href=url;
    },'Force Rebuild Confirm');
  });
});
</script>
</body>
</html>
