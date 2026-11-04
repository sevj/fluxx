-- Fluxx 1.16.0 upgrade (PostgreSQL).
--
-- Adds the column that guarantees a single active lock per key. If an older version
-- left several active locks on a key, only the most recent one is kept.

BEGIN;

ALTER TABLE fluxx_workflow_execution_lock ADD COLUMN active_lock_key VARCHAR(190) DEFAULT NULL;

UPDATE fluxx_workflow_execution_lock
SET released_at = LOCALTIMESTAMP(0), release_reason = 'duplicate_on_upgrade'
WHERE released_at IS NULL
  AND id NOT IN (
      SELECT MAX(id) FROM fluxx_workflow_execution_lock WHERE released_at IS NULL GROUP BY lock_key
  );

UPDATE fluxx_workflow_execution_lock SET active_lock_key = lock_key WHERE released_at IS NULL;

CREATE UNIQUE INDEX uniq_fluxx_execution_lock_active_lock_key ON fluxx_workflow_execution_lock (active_lock_key);

COMMIT;
