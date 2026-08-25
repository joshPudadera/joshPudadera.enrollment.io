-- ============================================================
--  MASTER SQL — BCP Student Management System
--  Run this entire file in phpMyAdmin or MySQL CLI.
--  Alternatively, visit: http://localhost/sms/app/shared/full_setup.php
-- ============================================================

CREATE DATABASE IF NOT EXISTS sms_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE sms_db;

-- ── 1. users ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(60)   NOT NULL UNIQUE,
    email         VARCHAR(150)  NOT NULL UNIQUE,
    first_name    VARCHAR(100)  NOT NULL,
    last_name     VARCHAR(100)  NOT NULL,
    password_hash VARCHAR(255)  NOT NULL,
    role          ENUM('admin','student') NOT NULL DEFAULT 'student',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default admin (password: Admin@1234)
INSERT IGNORE INTO users (username, email, first_name, last_name, password_hash, role)
VALUES (
    'admin', 'admin@bcp.edu.ph', 'Admin', 'User',
    '$2y$10$cEBNBPIuPPeaaZ6nCZ2o5OM2Ibmw3geQco75dY4qcic5lVIxZil/u',
    'admin'
);

-- ── 2. students ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS students (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO students (first_name,last_name,birthday,course,year_level,section,phone,status) VALUES
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
('Kenneth','Bondoc','2001-05-17','Bachelor of Science in Information Technology','4th Year','41028','09555555520','Active');

-- ── 3. sections ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS sections (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO sections (section_code,course,year_level,adviser_name,max_capacity,current_count) VALUES
('BSIT-1A','Bachelor of Science in Information Technology','1st Year','Prof. Santos',40,28),
('BSIT-2A','Bachelor of Science in Information Technology','2nd Year','Prof. Reyes',40,35),
('BSIT-3A','Bachelor of Science in Information Technology','3rd Year','Prof. Cruz',40,40),
('BSIT-4A','Bachelor of Science in Information Technology','4th Year','Prof. Garcia',40,38),
('BSCS-1A','Bachelor of Science in Computer Science','1st Year','Prof. Navarro',40,22),
('BSCS-2A','Bachelor of Science in Computer Science','2nd Year','Prof. Fernandez',40,30),
('BSCS-3A','Bachelor of Science in Computer Science','3rd Year','Prof. Ramos',40,40),
('BSCS-4A','Bachelor of Science in Computer Science','4th Year','Prof. Torres',40,37),
('BSIS-2A','Bachelor of Science in Information Systems','2nd Year','Prof. Mendoza',40,25),
('BSIS-3A','Bachelor of Science in Information Systems','3rd Year','Prof. Pascual',40,18);

-- ── 4. pre_registrations ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS pre_registrations (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 5. enrollment_documents ──────────────────────────────────
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

-- ── 6. enrollments ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS enrollments (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── 7. waiting_list ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS waiting_list (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pre_reg_id      INT UNSIGNED NOT NULL,
    course          VARCHAR(150) NOT NULL,
    year_level      VARCHAR(50)  NOT NULL,
    queue_position  INT UNSIGNED NOT NULL DEFAULT 0,
    reason          VARCHAR(255) DEFAULT 'Section full',
    status          ENUM('Waiting','Promoted','Cancelled') NOT NULL DEFAULT 'Waiting',
    queued_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pre_reg_id) REFERENCES pre_registrations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
