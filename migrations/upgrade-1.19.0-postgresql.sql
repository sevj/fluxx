-- Fluxx 1.19.0 upgrade (PostgreSQL).
--
-- Adds the warning counter of a run: it keeps the "completed with warnings" information once
-- pruning payloads has replaced the run status. The new status value needs no change, since
-- status columns are plain VARCHAR. Safe to run more than once.

BEGIN;

ALTER TABLE fluxx_workflow_run ADD COLUMN IF NOT EXISTS warning_count INT DEFAULT 0 NOT NULL;

COMMIT;
