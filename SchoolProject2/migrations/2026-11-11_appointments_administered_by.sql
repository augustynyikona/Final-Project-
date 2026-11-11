-- VaxiCare migration: add administered_by to appointments
--
-- nurse/view_patient.php sets administered_by when a vaccination is recorded
-- (the "Record Vaccination" action), and the patient history reads it back.
-- The column was never created, so every record attempt failed with
-- "Failed to record vaccination." and the history column rendered empty.
--
-- Apply on any environment that predates this change:
--   mysql -u <user> -p <database> < 2026-11-11_appointments_administered_by.sql
--
-- Safe to re-run: guarded by information_schema checks.

SET @has_col := (
  SELECT COUNT(*) FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name   = 'appointments'
    AND column_name  = 'administered_by'
);
SET @sql := IF(@has_col = 0,
  'ALTER TABLE appointments ADD COLUMN administered_by INT NULL DEFAULT NULL AFTER notes',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_fk := (
  SELECT COUNT(*) FROM information_schema.table_constraints
  WHERE table_schema     = DATABASE()
    AND table_name       = 'appointments'
    AND constraint_name  = 'fk_appt_administered_by'
);
SET @sql := IF(@has_fk = 0,
  'ALTER TABLE appointments ADD CONSTRAINT fk_appt_administered_by FOREIGN KEY (administered_by) REFERENCES users(id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Verify:
--   SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
--   FROM information_schema.columns
--   WHERE table_schema = DATABASE() AND table_name = 'appointments'
--     AND column_name = 'administered_by';
