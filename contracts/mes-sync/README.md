# MES ⇄ Laravel contract (golden fixtures)

Single source of truth for the wire contract between the .NET MES Core (`services/mes`) and the Laravel module
(`modules/30_Manufacturing`). Both services are tested against the files in `fixtures/`:

| Suite | File | Runs in |
| :-- | :-- | :-- |
| Pest | `tests/Feature/Manufacturing/MesContractFixturesTest.php` | `erp-ci.yml` (whole suite) |
| xUnit | `services/mes/tests/Mes.Tests/ContractFixtureTests.cs` | `mes-ci.yml` (also triggered by `contracts/mes-sync/**`) |

Regenerate **only** when the contract changes on purpose, and review the diff as a contract diff:

```powershell
php contracts/mes-sync/generate-fixtures.php
```

## Signing

`X-Mes-Signature: t=<unix>,kid=<key id>,v1=<hex>`, `v1 = HMAC-SHA256(secret[kid], "{t}.{raw body}")`, lower-case hex,
constant-time comparison, ±300 s replay window (`manufacturing_mes.signature_tolerance_seconds`), tenant in `X-Tenant-Id`
(refused with 403 unless the key lists it). GET requests are signed over the empty body (the path is not bound).
Signed envelopes carry the exact raw `body` string, `t` and the expected `signature`.

## Directions

**MES Core → Laravel** (`mes-to-laravel/*.json`): `POST /api/v1/manufacturing/mes-events` with
`{events:[{id, type, dedupe_key, occurred_at, payload}]}`, answered `207 {results:[{id, status, message}]}` where status is
`applied | duplicate | stale | rejected`. The bytes are the ones `OutboxRelay` produces (inside
strings `+` is written as backslash-u002B, except in `occurred_at`). Idempotency: the message `id` (inbox) plus the snapshot revision (derived from
`occurred_at` in Unix seconds).

**Laravel → MES Core** (`laravel-to-mes/*.json`):

- `ref-event.*.json`: `POST /api/v1/mes/ref-events`, a payload-less nudge `{tenant_id, resource, id, seq, revision, deleted, payload:null}`
  sent after the change commits; the MES Core answers by pulling the feed. `id` names the aggregate of the notification
  (an order, a work centre), not necessarily a feed entity.
- `feed.*.json`: `GET /api/v1/manufacturing/mes-sync/{machines|work-orders|shift-calendars|reason-codes}?after_seq=&limit=`
  → `{schema_version, resource, data:[{id, seq, revision, deleted, payload}], next_seq, has_more, watermark}`; `410` when
  `after_seq` is older than the pruned log.
- `manifest.*.json`: `GET …/{resource}/manifest` → `{items:[{id, revision}]}` of the live entities.

Laravel configuration (`config/manufacturing_mes.php`): `MES_SYNC_KEYS` (inbound keys, bound to tenants), and for the push
`MES_CORE_BASE_URL`, `MES_CORE_KEY_ID`, `MES_CORE_SECRET` (the MES Core's `LaravelSync:InboundKeys`).
