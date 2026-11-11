-- VaxiCare migration: add 'resolved' to triage_referrals.status
--
-- Why: doctor/critical_alerts.php resolves a critical alert by setting its
-- status to 'resolved' (line ~45), but the column's ENUM was only
-- ('pending','reviewed') and never included 'resolved'. MySQL truncated the
-- value and every "Resolve" click failed with SQLSTATE[01000] 1265 Data
-- truncated for column 'status'. Same bug class as
-- 2026-11-11_appointments_status_rescheduled.sql.
--
-- Apply to any environment that has not yet been migrated:
--   mysql -u <user> -p <database> < 2026-11-11_triage_referrals_status_resolved.sql

ALTER TABLE triage_referrals
  MODIFY COLUMN status ENUM('pending','reviewed','resolved')
  NOT NULL DEFAULT 'pending';
