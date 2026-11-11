-- VaxiCare migration: add 'rescheduled' to appointments.status
--
-- Why: nurse/appointments.php reschedules by setting the previous appointment's
-- status to 'rescheduled' (lines ~94, ~103, ~106) and the UI offers a
-- "Rescheduled" filter tab (line ~612), badge (~696) and status option (~714).
-- The column's ENUM did not include the value, so MySQL truncated it and every
-- reschedule failed with SQLSTATE[01000] 1265 Data truncated for column 'status'.
--
-- Apply to any environment that has not yet been migrated:
--   mysql -u <user> -p <database> < 2026-11-11_appointments_status_rescheduled.sql
--
-- Already applied locally on 2026-11-11.

ALTER TABLE appointments
  MODIFY COLUMN status ENUM('scheduled','confirmed','cancelled','arrived','no-show','completed','rescheduled')
  NOT NULL DEFAULT 'scheduled';
