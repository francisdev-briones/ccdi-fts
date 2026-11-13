-- CCDI Legazpi Student Financial Transaction System
-- Import in phpMyAdmin (Import tab). Safe to run on a fresh server.
-- To start over completely, first run:  DROP DATABASE ccdi_fts;

CREATE DATABASE IF NOT EXISTS ccdi_fts CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ccdi_fts;

CREATE TABLE IF NOT EXISTS students (
  id         VARCHAR(12)  NOT NULL PRIMARY KEY,
  name       VARCHAR(100) NOT NULL,
  category   ENUM('ordinary','scholar','promissory') NOT NULL DEFAULT 'ordinary',
  tuition    DECIMAL(10,2) NOT NULL DEFAULT 18000.00,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_tuition CHECK (tuition >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(30)  NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  full_name     VARCHAR(100) NOT NULL,
  role          ENUM('student','cashier','assistant','dean','proctor') NOT NULL,
  student_id    VARCHAR(12) NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_username (username),
  UNIQUE KEY uq_student (student_id),
  CONSTRAINT fk_user_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS requests (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  student_id VARCHAR(12) NOT NULL,
  type       ENUM('scholarship','promissory') NOT NULL,
  amount     DECIMAL(10,2) NULL,
  reason     VARCHAR(255) NULL,
  docs       TINYINT(1) NOT NULL DEFAULT 0,
  status     ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  coverage   TINYINT UNSIGNED NULL,
  deadline   DATE NULL,
  decided_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_req_student (student_id, type, status),
  CONSTRAINT fk_req_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS assessments (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  student_id     VARCHAR(12) NOT NULL,
  school_year    VARCHAR(9)  NOT NULL,
  term           VARCHAR(10) NOT NULL,
  course         VARCHAR(80) NOT NULL,
  units          TINYINT UNSIGNED NOT NULL,
  tuition_fee    DECIMAL(10,2) NOT NULL DEFAULT 0,
  lab_fee        DECIMAL(10,2) NOT NULL DEFAULT 0,
  misc_fee       DECIMAL(10,2) NOT NULL DEFAULT 0,
  other_fee      DECIMAL(10,2) NOT NULL DEFAULT 0,
  discount       DECIMAL(10,2) NOT NULL DEFAULT 0,
  discount_label VARCHAR(60) NOT NULL DEFAULT '',
  total_payable  DECIMAL(10,2) NOT NULL,
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_assess (student_id, school_year, term),
  CONSTRAINT fk_assess_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ref           VARCHAR(40) NOT NULL,
  student_id    VARCHAR(12) NOT NULL,
  assessment_id INT UNSIGNED NULL,
  amount        DECIMAL(10,2) NOT NULL,
  method        VARCHAR(30) NOT NULL,
  txn_date      DATE NULL,
  proof_path    VARCHAR(80) NULL,
  flagged       TINYINT(1) NOT NULL DEFAULT 0,
  status        ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  reject_reason VARCHAR(255) NULL,
  receipt_no    VARCHAR(30) NULL,
  verified_by   INT UNSIGNED NULL,
  verified_at   DATETIME NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ref (ref),
  UNIQUE KEY uq_receipt (receipt_no),
  KEY idx_pay_student (student_id, status),
  CONSTRAINT chk_amount CHECK (amount > 0),
  CONSTRAINT fk_pay_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_pay_assess FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS exam_periods (
  id            TINYINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(30) NOT NULL,
  sort          TINYINT UNSIGNED NOT NULL,
  threshold_pct TINYINT UNSIGNED NOT NULL,
  UNIQUE KEY uq_period (name),
  CONSTRAINT chk_pct CHECK (threshold_pct BETWEEN 1 AND 100)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS permits (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  student_id    VARCHAR(12) NOT NULL,
  assessment_id INT UNSIGNED NOT NULL,
  period_id     TINYINT UNSIGNED NOT NULL,
  token         CHAR(12) NOT NULL,
  status        ENUM('Active','Revoked') NOT NULL DEFAULT 'Active',
  issued_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_token (token),
  UNIQUE KEY uq_permit (assessment_id, period_id),
  CONSTRAINT fk_permit_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_permit_assess FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE,
  CONSTRAINT fk_permit_period FOREIGN KEY (period_id) REFERENCES exam_periods(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS scan_logs (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  permit_id  INT UNSIGNED NULL,
  token      VARCHAR(40) NOT NULL,
  proctor_id INT UNSIGNED NULL,
  period_id  TINYINT UNSIGNED NULL,
  result     VARCHAR(20) NOT NULL,
  detail     VARCHAR(160) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_scan_permit (permit_id, result),
  KEY idx_scan_time (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS appointments (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  student_id VARCHAR(12) NOT NULL,
  service    VARCHAR(30) NOT NULL,
  purpose    VARCHAR(20) NOT NULL,
  appt_date  DATE NOT NULL,
  slot       VARCHAR(30) NOT NULL,
  status     ENUM('Pending','Confirmed','Completed','No-show','Cancelled') NOT NULL DEFAULT 'Pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_appt_slot (service, appt_date, slot, status),
  KEY idx_appt_student (student_id, appt_date),
  CONSTRAINT fk_appt_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NULL,
  actor      VARCHAR(120) NOT NULL,
  action     VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_user (user_id, id)
) ENGINE=InnoDB;

-- Sample students (their login accounts are created by setup.php)
INSERT IGNORE INTO students (id, name, category, tuition) VALUES
  ('2026-0001', 'Maria Santos',   'ordinary',   18000.00),
  ('2026-0002', 'Juan Dela Cruz', 'scholar',    18000.00),
  ('2026-0003', 'Ana Reyes',      'promissory', 18000.00);

-- Exam permit requirements: share of the amount payable that approved payments must reach.
-- The Dean's Secretary can change these in the system.
INSERT IGNORE INTO exam_periods (id, name, sort, threshold_pct) VALUES
  (1, 'Prelim', 1, 30), (2, 'Midterm', 2, 55), (3, 'Pre-Final', 3, 80), (4, 'Final', 4, 100);
