-- Upgrade for databases imported from the FIRST release. Run once in phpMyAdmin (SQL tab) on ccdi_fts.
-- Fresh installs should import schema.sql instead and skip this file.
USE ccdi_fts;

ALTER TABLE users MODIFY role ENUM('student','cashier','assistant','dean','proctor') NOT NULL;

-- Payments: new status names and columns
ALTER TABLE payments MODIFY status VARCHAR(20) NOT NULL DEFAULT 'Pending';
UPDATE payments SET status='Pending'  WHERE status='For verification';
UPDATE payments SET status='Approved' WHERE status='Receipt issued';
ALTER TABLE payments MODIFY status ENUM('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending';
ALTER TABLE payments
  DROP INDEX uq_ref,
  ADD KEY idx_ref (ref),
  ADD COLUMN assessment_id INT UNSIGNED NULL AFTER student_id,
  ADD COLUMN txn_date DATE NULL AFTER method,
  ADD COLUMN proof_path VARCHAR(80) NULL AFTER txn_date,
  ADD COLUMN flagged TINYINT(1) NOT NULL DEFAULT 0 AFTER proof_path,
  ADD COLUMN reject_reason VARCHAR(255) NULL AFTER status;
-- Then import only the new tables (assessments, exam_periods, permits, scan_logs) by running schema.sql;
-- existing tables are skipped because it uses CREATE TABLE IF NOT EXISTS.
