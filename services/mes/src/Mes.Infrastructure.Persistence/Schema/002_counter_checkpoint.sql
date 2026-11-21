-- Durable baseline of cumulative PLC counters, so a restart resumes from the last accepted value.
-- Written in the same transaction as the buckets that consumed the corresponding deltas.
CREATE TABLE IF NOT EXISTS counter_checkpoint (
  tenant_id   uuid        NOT NULL,
  machine_id  uuid        NOT NULL,
  key         text        NOT NULL,            -- 'total' | 'good'
  value       numeric     NOT NULL,
  at          timestamptz NOT NULL,
  PRIMARY KEY (tenant_id, machine_id, key)
);

ALTER TABLE counter_checkpoint ENABLE ROW LEVEL SECURITY;
ALTER TABLE counter_checkpoint FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS tenant_isolation ON counter_checkpoint;
CREATE POLICY tenant_isolation ON counter_checkpoint
  USING (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid)
  WITH CHECK (tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid);
