# Audit ledger (Mod 005) — operations

The probative audit trail is the keyed hash chain in `enterprise_audit_ledger` (package `packages/audit-ledger`).
Mod 113 (technical logs) is a separate boundary. The legacy `audit_logs` table (`App\Models\AuditLog`) is deprecated
but still readable.

## Database grants (manual, not done by migrations)

Immutability is enforced in the model layer (`ProtectsRecords`), not with PostgreSQL triggers. Add defence in depth by
granting the application role INSERT and SELECT only:

```sql
REVOKE UPDATE, DELETE, TRUNCATE ON enterprise_audit_ledger, enterprise_audit_ledger_archive,
    audit_checkpoints, audit_integrity_runs, audit_subject_map, audit_exports, audit_key_versions FROM app_role;
GRANT INSERT, SELECT ON enterprise_audit_ledger, enterprise_audit_ledger_archive,
    audit_checkpoints, audit_integrity_runs, audit_exports, audit_key_versions TO app_role;
-- audit_subject_map needs DELETE (erasure of a data subject) and UPDATE is not needed.
GRANT INSERT, SELECT, DELETE ON audit_subject_map TO app_role;
-- Anchoring writes only the anchor columns of a checkpoint.
GRANT UPDATE (anchored_at, anchor_reference) ON audit_checkpoints TO app_role;
```

Run retention/purge jobs with a separate privileged role.

## Keys

- `AUDIT_LEDGER_MASTER_KEY`, `AUDIT_LEDGER_MASTER_KEY_V2`, `AUDIT_LEDGER_MASTER_KEY_V3`: 32+ random bytes (base64).
  The per-tenant key is derived with HKDF (salt = tenant id). In production the `APP_KEY` fallback is disabled.
- Rotation: add the new master key, then `php artisan audit-ledger:rotate-key --tenant=<id>`. Old versions must stay
  configured: entries keep the `key_version` they were signed with.

## WORM anchoring

Checkpoints (daily and at fiscal period close) are signed and written once to `AUDIT_LEDGER_ANCHOR_DISK`
(an S3 bucket with Object Lock in compliance mode, or an append-only volume). Files are never overwritten.

## Scheduling and retention

`CreateDailyCheckpointsJob` 00:10, `VerifyAuditChainsJob` 02:45 (needs a queue worker). A broken chain raises an
alert through the notification engine. Retention minimums live in `config/audit_ledger.php`; the ledger fails closed
when an audited write cannot be recorded (read-only actions are queued for retry).

## Backup and restore

A restore never overwrites the ledger: restored entries go to `enterprise_audit_ledger_archive` with a `restore_id`
and are verifiable via `GET /api/v1/settings/audit-logs/archive?restore_id=`. `audit_subject_map` is excluded from
backups so erased names are never resurrected.
