-- Mes.Core schema. PostgreSQL 15+; TimescaleDB is used when the extension is available (plain PG otherwise).
-- Tenancy: every table carries tenant_id and has FORCE ROW LEVEL SECURITY keyed on current_setting('app.tenant_id').
-- The application sets it with set_config('app.tenant_id', <uuid>, true) (transaction-local) on every transaction.
-- Cross-tenant background jobs (outbox relay, reference reconcile) use a dedicated role with BYPASSRLS.

DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_available_extensions WHERE name = 'timescaledb') THEN
    CREATE EXTENSION IF NOT EXISTS timescaledb;
  END IF;
END $$;

-- ───────────── time series ─────────────
CREATE TABLE IF NOT EXISTS telemetry_sample (
  tenant_id       uuid        NOT NULL,
  machine_id      uuid        NOT NULL,
  tag_id          text        NOT NULL,
  event_time      timestamptz NOT NULL,
  gateway_rx_time timestamptz NOT NULL,
  ingest_time     timestamptz NOT NULL,
  num_value       double precision,
  text_value      text,
  quality         smallint    NOT NULL DEFAULT 0,
  mapping_version integer     NOT NULL,
  -- replays from the edge WAL hit this key and are ignored (INSERT ... ON CONFLICT DO NOTHING)
  PRIMARY KEY (tenant_id, machine_id, tag_id, event_time)
);

CREATE TABLE IF NOT EXISTS machine_state_interval (
  tenant_id   uuid        NOT NULL,
  machine_id  uuid        NOT NULL,
  start_time  timestamptz NOT NULL,
  end_time    timestamptz,                 -- NULL = open interval (at most one per machine)
  state       smallint    NOT NULL,
  reason      text,
  PRIMARY KEY (tenant_id, machine_id, start_time)
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_msi_one_open ON machine_state_interval (tenant_id, machine_id) WHERE end_time IS NULL;
CREATE INDEX IF NOT EXISTS ix_msi_range ON machine_state_interval (tenant_id, machine_id, end_time);

-- Additive 1-minute OEE projection. Every measure is a SUM so buckets can be re-aggregated to shift/day/order
-- by addition and the ratios computed once (never averaged).
CREATE TABLE IF NOT EXISTS oee_bucket_1m (
  tenant_id        uuid        NOT NULL,
  machine_id       uuid        NOT NULL,
  bucket_start     timestamptz NOT NULL,
  policy_version   integer     NOT NULL,
  planned_ms       bigint      NOT NULL DEFAULT 0,
  run_ms           bigint      NOT NULL DEFAULT 0,
  setup_ms         bigint      NOT NULL DEFAULT 0,
  alarm_ms         bigint      NOT NULL DEFAULT 0,
  down_ms          bigint      NOT NULL DEFAULT 0,
  unknown_ms       bigint      NOT NULL DEFAULT 0,
  ideal_work_ms    double precision NOT NULL DEFAULT 0,
  ideal_good_ms    double precision NOT NULL DEFAULT 0,
  total_count      bigint      NOT NULL DEFAULT 0,
  good_count       bigint      NOT NULL DEFAULT 0,
  has_quality_data boolean     NOT NULL DEFAULT false,
  PRIMARY KEY (tenant_id, machine_id, bucket_start)
);

DO $$
BEGIN
  IF EXISTS (SELECT 1 FROM pg_extension WHERE extname = 'timescaledb') THEN
    PERFORM create_hypertable('telemetry_sample', 'event_time', chunk_time_interval => INTERVAL '1 day', if_not_exists => TRUE);
    PERFORM create_hypertable('oee_bucket_1m', 'bucket_start', chunk_time_interval => INTERVAL '7 days', if_not_exists => TRUE);
  END IF;
END $$;

-- ───────────── relational MES store ─────────────
CREATE TABLE IF NOT EXISTS production_run (
  tenant_id        uuid        NOT NULL,
  id               uuid        NOT NULL,
  machine_id       uuid        NOT NULL,
  work_order_ref   text        NOT NULL,
  ideal_cycle_ms   double precision NOT NULL CHECK (ideal_cycle_ms > 0),
  started_at       timestamptz NOT NULL,
  ended_at         timestamptz,
  total_count      bigint      NOT NULL DEFAULT 0,
  scrap_count      bigint      NOT NULL DEFAULT 0 CHECK (scrap_count <= total_count),
  PRIMARY KEY (tenant_id, id)
);

CREATE TABLE IF NOT EXISTS mes_correction (
  tenant_id    uuid        NOT NULL,
  id           uuid        NOT NULL,
  machine_id   uuid        NOT NULL,
  kind         text        NOT NULL,
  window_start timestamptz,
  window_end   timestamptz,
  payload      jsonb       NOT NULL,
  actor        text,
  created_at   timestamptz NOT NULL,
  PRIMARY KEY (tenant_id, id)
);

CREATE TABLE IF NOT EXISTS ingest_batch (
  tenant_id   uuid        NOT NULL,
  batch_key   text        NOT NULL,
  received_at timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (tenant_id, batch_key)
);

-- Transactional outbox: rows are inserted in the SAME transaction as the bucket/correction mutation.
CREATE TABLE IF NOT EXISTS mes_outbox (
  id              uuid        PRIMARY KEY,
  tenant_id       uuid        NOT NULL,
  type            text        NOT NULL,
  dedupe_key      text        NOT NULL,
  payload         jsonb       NOT NULL,
  created_at      timestamptz NOT NULL,
  status          text        NOT NULL DEFAULT 'pending',   -- pending | sent | dead
  attempts        integer     NOT NULL DEFAULT 0,
  next_attempt_at timestamptz NOT NULL DEFAULT now(),
  locked_until    timestamptz,
  last_error      text,
  UNIQUE (tenant_id, dedupe_key)
);
CREATE INDEX IF NOT EXISTS ix_outbox_due ON mes_outbox (next_attempt_at) WHERE status = 'pending';

-- ───────────── read-only mirrors of Laravel master data (written only by the sync client) ─────────────
CREATE TABLE IF NOT EXISTS ref_machine        (tenant_id uuid NOT NULL, id uuid NOT NULL, seq bigint NOT NULL, revision bigint NOT NULL, deleted boolean NOT NULL DEFAULT false, payload jsonb NOT NULL, updated_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY (tenant_id, id));
CREATE TABLE IF NOT EXISTS ref_work_order     (tenant_id uuid NOT NULL, id uuid NOT NULL, seq bigint NOT NULL, revision bigint NOT NULL, deleted boolean NOT NULL DEFAULT false, payload jsonb NOT NULL, updated_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY (tenant_id, id));
CREATE TABLE IF NOT EXISTS ref_shift_calendar (tenant_id uuid NOT NULL, id uuid NOT NULL, seq bigint NOT NULL, revision bigint NOT NULL, deleted boolean NOT NULL DEFAULT false, payload jsonb NOT NULL, updated_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY (tenant_id, id));
CREATE TABLE IF NOT EXISTS ref_reason_code    (tenant_id uuid NOT NULL, id uuid NOT NULL, seq bigint NOT NULL, revision bigint NOT NULL, deleted boolean NOT NULL DEFAULT false, payload jsonb NOT NULL, updated_at timestamptz NOT NULL DEFAULT now(), PRIMARY KEY (tenant_id, id));

CREATE TABLE IF NOT EXISTS ref_cursor (
  tenant_id uuid   NOT NULL,
  resource  text   NOT NULL,
  seq       bigint NOT NULL DEFAULT 0,
  PRIMARY KEY (tenant_id, resource)
);

-- ───────────── row level security ─────────────
DO $$
DECLARE t text;
BEGIN
  FOREACH t IN ARRAY ARRAY[
    'telemetry_sample','machine_state_interval','oee_bucket_1m','production_run','mes_correction','ingest_batch',
    'mes_outbox','ref_machine','ref_work_order','ref_shift_calendar','ref_reason_code','ref_cursor']
  LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format('DROP POLICY IF EXISTS tenant_isolation ON %I', t);
    EXECUTE format(
      'CREATE POLICY tenant_isolation ON %I USING (tenant_id = NULLIF(current_setting(''app.tenant_id'', true), '''')::uuid) WITH CHECK (tenant_id = NULLIF(current_setting(''app.tenant_id'', true), '''')::uuid)', t);
  END LOOP;
END $$;
