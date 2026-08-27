<?php
mysqli_report(MYSQLI_REPORT_OFF); // disable exceptions, handle errors manually

$conn = new mysqli(
    getenv('DB_HOST') ?: 'localhost',
    getenv('DB_USER') ?: 'root',
    getenv('DB_PASS') ?: '',
    null,
    (int)(getenv('DB_PORT') ?: 3306)
);
if ($conn->connect_error) die("Cannot connect to MySQL: " . $conn->connect_error . "\n");

$db = getenv('DB_NAME') ?: 'sms_db';

$ok = 0; $fail = 0;

function q($conn, $sql, $label) {
    global $ok, $fail;
    $result = $conn->query($sql);
    if ($result !== false) {
        echo "  OK  $label\n"; $ok++;
    } else {
        $e = $conn->errno;
        // 1050=table exists, 1062=dup key, 1060=col exists, 1007=db exists
        if (in_array($e, [1050,1062,1060,1007,1022])) {
            echo " SKIP $label (already exists)\n"; $ok++;
        } else {
            echo " FAIL $label [{$e}]: {$conn->error}\n"; $fail++;
        }
    }
}

echo "\n=== BCP SMS — Database Setup ===\n\n";

q($conn,"CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci","Create database $db");
@$conn->select_db($db) or $conn->query("USE `$db`");

// ── users ────────────────────────────────────────────────────
q($conn,"CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(60)  NOT NULL UNIQUE,
  email         VARCHAR(150) NOT NULL UNIQUE,
  first_name    VARCHAR(100) NOT NULL,
  last_name     VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','student') NOT NULL DEFAULT 'student',
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci","Create table: users");

$hash = password_hash('Admin@1234', PASSWORD_DEFAULT);
q($conn,"INSERT IGNORE INTO users (username,email,first_name,last_name,password_hash,role)
  VALUES ('admin','admin@bcp.edu.ph','Admin','User','$hash','admin')","Seed admin account");

// ── students ─────────────────────────────────────────────────
q($conn,"CREATE TABLE IF NOT EXISTS students (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  first_name  VARCHAR(100) NOT NULL,
  last_name   VARCHAR(100) NOT NULL,
  birthday    DATE         NOT NULL,
  course      VARCHAR(150) NOT NULL,
  year_level  VARCHAR(50)  NOT NULL,
  section     VARCHAR(50)  NOT NULL,
  phone       VARCHAR(20)  NOT NULL,
  status      ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci","Create table: students");

$cnt = (int)$conn->query("SELECT COUNT(*) c FROM students")->fetch_assoc()['c'];
if ($cnt === 0) {
q($conn,"INSERT INTO students (first_name,last_name,birthday,course,year_level,section,phone,status) VALUES
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
  ('Kenneth','Bondoc','2001-05-17','Bachelor of Science in Information Technology','4th Year','41028','09555555520','Active')","Seed 25 students");
} else {
    echo " SKIP Seed students (already have $cnt rows)\n"; $ok++;
}

// ── sections ─────────────────────────────────────────────────
q($conn,"CREATE TABLE IF NOT EXISTS sections (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  section_code  VARCHAR(20)  NOT NULL UNIQUE,
  course        VARCHAR(150) NOT NULL,
  year_level    VARCHAR(50)  NOT NULL,
  adviser_name  VARCHAR(150) DEFAULT NULL,
  max_capacity  INT UNSIGNED NOT NULL DEFAULT 40,
  current_count INT UNSIGNED NOT NULL DEFAULT 0,
  semester      VARCHAR(20)  NOT NULL DEFAULT '1st',
  school_year   VARCHAR(20)  NOT NULL DEFAULT '2025-2026',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4","Create table: sections");

q($conn,"INSERT IGNORE INTO sections (section_code,course,year_level,adviser_name,max_capacity,current_count) VALUES
  ('BSIT-1A','Bachelor of Science in Information Technology','1st Year','Prof. Santos',40,28),
  ('BSIT-2A','Bachelor of Science in Information Technology','2nd Year','Prof. Reyes',40,35),
  ('BSIT-3A','Bachelor of Science in Information Technology','3rd Year','Prof. Cruz',40,40),
  ('BSIT-4A','Bachelor of Science in Information Technology','4th Year','Prof. Garcia',40,38),
  ('BSCS-1A','Bachelor of Science in Computer Science','1st Year','Prof. Navarro',40,22),
  ('BSCS-2A','Bachelor of Science in Computer Science','2nd Year','Prof. Fernandez',40,30),
  ('BSCS-3A','Bachelor of Science in Computer Science','3rd Year','Prof. Ramos',40,40),
  ('BSCS-4A','Bachelor of Science in Computer Science','4th Year','Prof. Torres',40,37),
  ('BSIS-2A','Bachelor of Science in Information Systems','2nd Year','Prof. Mendoza',40,25),
  ('BSIS-3A','Bachelor of Science in Information Systems','3rd Year','Prof. Pascual',40,18)","Seed 10 sections");

// ── pre_registrations ────────────────────────────────────────
q($conn,"CREATE TABLE IF NOT EXISTS pre_registrations (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NULL DEFAULT NULL,
  first_name   VARCHAR(100) NOT NULL,
  last_name    VARCHAR(100) NOT NULL,
  email        VARCHAR(150) NOT NULL,
  phone        VARCHAR(20)  NOT NULL,
  birthday     DATE         NOT NULL,
  course       VARCHAR(150) NOT NULL,
  year_level   VARCHAR(50)  NOT NULL,
  prev_school  VARCHAR(200) DEFAULT NULL,
  status       ENUM('Pending','Approved','Rejected','Enrolled') NOT NULL DEFAULT 'Pending',
  remarks      TEXT         DEFAULT NULL,
  submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4","Create table: pre_registrations");

// ── enrollment_documents ─────────────────────────────────────
q($conn,"CREATE TABLE IF NOT EXISTS enrollment_documents (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pre_reg_id      INT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  document_type   ENUM('Form137','BirthCertificate','GoodMoral','MedicalCert','IDPhoto','Other') NOT NULL,
  file_name       VARCHAR(255) NOT NULL,
  file_path       VARCHAR(500) NOT NULL,
  file_size       INT UNSIGNED NOT NULL DEFAULT 0,
  status          ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  ai_result       JSON         DEFAULT NULL,
  ai_inspected_at TIMESTAMP    NULL DEFAULT NULL,
  uploaded_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (pre_reg_id) REFERENCES pre_registrations(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id)    REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4","Create table: enrollment_documents");

// ── enrollments ──────────────────────────────────────────────
q($conn,"CREATE TABLE IF NOT EXISTS enrollments (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4","Create table: enrollments");

// ── waiting_list ─────────────────────────────────────────────
q($conn,"CREATE TABLE IF NOT EXISTS waiting_list (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pre_reg_id     INT UNSIGNED NOT NULL,
  course         VARCHAR(150) NOT NULL,
  year_level     VARCHAR(50)  NOT NULL,
  queue_position INT UNSIGNED NOT NULL DEFAULT 0,
  reason         VARCHAR(255) DEFAULT 'Section full',
  status         ENUM('Waiting','Promoted','Cancelled') NOT NULL DEFAULT 'Waiting',
  queued_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (pre_reg_id) REFERENCES pre_registrations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4","Create table: waiting_list");

// ── login_tokens (one-time email login links) ─────────────────
q($conn,"CREATE TABLE IF NOT EXISTS login_tokens (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  token      VARCHAR(64)  NOT NULL UNIQUE,
  used       TINYINT(1)   NOT NULL DEFAULT 0,
  expires_at TIMESTAMP    NOT NULL,
  created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4","Create table: login_tokens");

// ── subjects ─────────────────────────────────────────────────
q($conn,"CREATE TABLE IF NOT EXISTS subjects (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code       VARCHAR(20)  NOT NULL UNIQUE,
  name       VARCHAR(200) NOT NULL,
  units      TINYINT      NOT NULL DEFAULT 3,
  year_level VARCHAR(50)  NOT NULL DEFAULT 'All',
  type       ENUM('Lec','Lab','Lec/Lab') NOT NULL DEFAULT 'Lec',
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4","Create table: subjects");

$cnt_sub = (int)$conn->query("SELECT COUNT(*) c FROM subjects")->fetch_assoc()['c'];
if ($cnt_sub === 0) {
q($conn,"INSERT IGNORE INTO subjects (code,name,units,year_level,type) VALUES
  ('CS 101','Introduction to Computing',3,'1st Year','Lec'),
  ('CS 102','Computer Programming 1',3,'1st Year','Lab'),
  ('CS 201','Data Structures & Algorithms',3,'2nd Year','Lec'),
  ('IT 301','Web Systems & Technologies',3,'3rd Year','Lec/Lab'),
  ('IT 302','Database Management Systems',3,'3rd Year','Lec/Lab'),
  ('IT 401','Systems Integration & Architecture',3,'4th Year','Lec'),
  ('GE 001','Understanding the Self',3,'All','Lec'),
  ('PE 001','Physical Education 1',2,'1st Year','Lec'),
  ('NSTP 1','National Service Training Program',3,'1st Year','Lec')","Seed 9 subjects");
} else {
    echo " SKIP Seed subjects (already have $cnt_sub rows)\n"; $ok++;
}

// ── grades ───────────────────────────────────────────────────
q($conn,"CREATE TABLE IF NOT EXISTS grades (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4","Create table: grades");

// ── attendance ───────────────────────────────────────────────
q($conn,"CREATE TABLE IF NOT EXISTS attendance (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4","Create table: attendance");

// ── announcements ────────────────────────────────────────────
q($conn,"CREATE TABLE IF NOT EXISTS announcements (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title     VARCHAR(255) NOT NULL,
  content   TEXT         NOT NULL,
  type      ENUM('info','success','warning','event') NOT NULL DEFAULT 'info',
  posted_by INT UNSIGNED NULL DEFAULT NULL,
  is_active TINYINT(1)   NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (posted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4","Create table: announcements");

$cnt_ann = (int)$conn->query("SELECT COUNT(*) c FROM announcements")->fetch_assoc()['c'];
if ($cnt_ann === 0) {
q($conn,"INSERT INTO announcements (title,content,type) VALUES
  ('Enrollment for A.Y. 2025-2026 is now open','Online pre-registration is now available. Submit your application through the Enrollment module and upload the required documents.','info'),
  ('Orientation Day — August 5, 2025','All new and returning students are required to attend the school orientation. Venue: BCP Main Gymnasium, 8:00 AM.','event'),
  ('Deadline for document submission: July 31, 2025','All applicants must complete document uploads before the deadline to avoid delays in enrollment processing.','warning')","Seed 3 announcements");
} else {
    echo " SKIP Seed announcements (already have $cnt_ann rows)\n"; $ok++;
}

$conn->close();
echo "\n=== RESULT: $ok OK  |  $fail FAILED ===\n";
echo $fail === 0
    ? "Database ready! Login: admin / Admin@1234\n"
    : "Some steps failed — check errors above.\n";
