-- ============================================================
--  BCP SMS — Initial Database Schema
--  Auto-runs when MySQL container first starts.
-- ============================================================

CREATE DATABASE IF NOT EXISTS sms_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sms_db;

-- users
CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(60)  NOT NULL UNIQUE,
  email         VARCHAR(150) NOT NULL UNIQUE,
  first_name    VARCHAR(100) NOT NULL,
  last_name     VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','student') NOT NULL DEFAULT 'student',
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default admin (password: Admin@1234)
INSERT IGNORE INTO users (username,email,first_name,last_name,password_hash,role)
VALUES ('admin','admin@bcp.edu.ph','Admin','User',
        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.','admin');

-- students
CREATE TABLE IF NOT EXISTS students (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sections
CREATE TABLE IF NOT EXISTS sections (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  section_code  VARCHAR(20)  NOT NULL UNIQUE,
  course        VARCHAR(150) NOT NULL,
  year_level    VARCHAR(50)  NOT NULL,
  adviser_name  VARCHAR(150) DEFAULT NULL,
  max_capacity  INT UNSIGNED NOT NULL DEFAULT 50,
  current_count INT UNSIGNED NOT NULL DEFAULT 0,
  semester      VARCHAR(20)  NOT NULL DEFAULT '1st',
  school_year   VARCHAR(20)  NOT NULL DEFAULT '2025-2026',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- subjects
CREATE TABLE IF NOT EXISTS subjects (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code       VARCHAR(20)  NOT NULL UNIQUE,
  name       VARCHAR(200) NOT NULL,
  units      TINYINT      NOT NULL DEFAULT 3,
  year_level VARCHAR(50)  NOT NULL DEFAULT 'All',
  type       ENUM('Lec','Lab','Lec/Lab') NOT NULL DEFAULT 'Lec',
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- pre_registrations
CREATE TABLE IF NOT EXISTS pre_registrations (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NULL DEFAULT NULL,
  ref_number   VARCHAR(50)  DEFAULT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- enrollment_documents
CREATE TABLE IF NOT EXISTS enrollment_documents (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- enrollments
CREATE TABLE IF NOT EXISTS enrollments (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- waiting_list
CREATE TABLE IF NOT EXISTS waiting_list (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pre_reg_id     INT UNSIGNED NOT NULL,
  course         VARCHAR(150) NOT NULL,
  year_level     VARCHAR(50)  NOT NULL,
  queue_position INT UNSIGNED NOT NULL DEFAULT 0,
  reason         VARCHAR(255) DEFAULT 'Section full',
  status         ENUM('Waiting','Promoted','Cancelled') NOT NULL DEFAULT 'Waiting',
  queued_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (pre_reg_id) REFERENCES pre_registrations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- login_tokens
CREATE TABLE IF NOT EXISTS login_tokens (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  token      VARCHAR(64)  NOT NULL UNIQUE,
  used       TINYINT(1)   NOT NULL DEFAULT 0,
  expires_at TIMESTAMP    NOT NULL,
  created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- grades
CREATE TABLE IF NOT EXISTS grades (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- attendance
CREATE TABLE IF NOT EXISTS attendance (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- announcements
CREATE TABLE IF NOT EXISTS announcements (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title     VARCHAR(255) NOT NULL,
  content   TEXT         NOT NULL,
  type      ENUM('info','success','warning','event') NOT NULL DEFAULT 'info',
  posted_by INT UNSIGNED NULL DEFAULT NULL,
  is_active TINYINT(1)   NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (posted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
