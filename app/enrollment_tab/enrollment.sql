-- ── Enrollment System Tables ─────────────────────────────────
-- Run in phpMyAdmin after the base students.sql

USE sms_db;

-- 1. Pre-Registration applications
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

-- 2. Document uploads
CREATE TABLE IF NOT EXISTS enrollment_documents (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Enrollment records (validated & confirmed)
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

-- 4. Waiting list
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

-- 5. Sections with capacity
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

-- Seed sample sections
INSERT IGNORE INTO sections (section_code, course, year_level, adviser_name, max_capacity, current_count) VALUES
('BSIT-1A', 'Bachelor of Science in Information Technology', '1st Year', 'Prof. Santos',    40, 28),
('BSIT-2A', 'Bachelor of Science in Information Technology', '2nd Year', 'Prof. Reyes',     40, 35),
('BSIT-3A', 'Bachelor of Science in Information Technology', '3rd Year', 'Prof. Cruz',      40, 40),
('BSIT-4A', 'Bachelor of Science in Information Technology', '4th Year', 'Prof. Garcia',    40, 38),
('BSCS-1A', 'Bachelor of Science in Computer Science',       '1st Year', 'Prof. Navarro',   40, 22),
('BSCS-2A', 'Bachelor of Science in Computer Science',       '2nd Year', 'Prof. Fernandez', 40, 30),
('BSCS-3A', 'Bachelor of Science in Computer Science',       '3rd Year', 'Prof. Ramos',     40, 40),
('BSCS-4A', 'Bachelor of Science in Computer Science',       '4th Year', 'Prof. Torres',    40, 37),
('BSIS-2A', 'Bachelor of Science in Information Systems',    '2nd Year', 'Prof. Mendoza',   40, 25),
('BSIS-3A', 'Bachelor of Science in Information Systems',    '3rd Year', 'Prof. Pascual',   40, 18);
