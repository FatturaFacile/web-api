# MES — Industry 4.0 connectivity, IoT & line monitoring (Issue #184)

.NET 9 / C# 13 side of the Manufacturing module. Two deployables:

| Deployable | Project | Role |
| :-- | :-- | :-- |
| **Edge.Gateway** (Worker, one per line/cell) | `src/Edge.Gateway` | Protocol I/O (OPC UA, MQTT + Sparkplug B, **read-only** Modbus TCP), versioned tag mapping, SQLite-WAL store-and-forward, clock-offset estimation, TLS/mTLS publishing. **No OEE, no state machine.** |
| **Mes.Core** (ASP.NET) | `src/Mes.Presentation` | Partitioned ingestion, state machine, OEE, 1-min buckets, corrections, outbox → Laravel, reference sync ← Laravel, Redis live view, SignalR. |

```
Mes.Domain  ←  Mes.Application  ←  Mes.Infrastructure.{Connectors, Persistence, LaravelSync}  ←  Mes.Presentation
                       ↑                         ↑
                       └──── Edge.Gateway ───────┘   (Connectors + ports only; NetArchTest forbids Oee/State/Counters/Energy/Commands)
```

Build / test (Docker or `MES_TEST_PG`/`MES_TEST_REDIS` needed for the integration tests; see Testing):

```powershell
dotnet build services/mes/Mes.slnx
dotnet test  services/mes/Mes.slnx
```

## Decisions that matter

* **OEE math (ADR-05)** — `OeeAccumulator` holds only additive sums (`PlannedMs, RunMs, SetupMs, AlarmMs, DownMs, UnknownMs, IdealWorkMs, IdealGoodMs, TotalCount, GoodCount, HasQualityData`). Ratios are computed once at the end:
  `A = Run/Planned`, `P = IdealWork/Run`, `Q = IdealGood/IdealWork`, `OEE = A·min(P,1)·(Q ?? 1)`; with `P ≤ 1` and quality data this equals `IdealGood/Planned` (checked to 1e-9, property-tested). Percentages are never averaged across buckets/shifts/orders.
  Units with no quality information count as good *in the ideal-time weighting* so Q stays a pure ratio; `HasQualityData` records whether any real quality input exists. `P` null + `RunMs == 0` ⇒ OEE `0`; `P` null + `RunMs > 0` ⇒ OEE `null` (`NoProduction`).
  `SetupClassification` and `GapPolicy` are snapshotted per bucket via `policy_version`.
* **State machine** — debounced, back-dated to `firstSeenAt`, clamped to the open interval's start, micro-stops (< debounce) absorbed, zero-length intervals never emitted. Silence > `StaleAfter` ⇒ an `Unknown` interval, never "the old state continues".
* **Single writer per machine** — `TelemetryPipeline` hashes `MachineId` (FNV-1a, stable across processes) to a partition; one bounded `Channel<T>` + one worker per partition. Per-machine objects (state machine, counter trackers) are therefore lock-free. Back-pressure propagates to the edge, which keeps data in its WAL.
* **Exactly-once-ish ingest** — edge batches carry a `BatchId`; `ingest_batch(tenant, batch_id:machine)` is registered inside the same transaction as the writes. Sample inserts are `ON CONFLICT DO NOTHING`.
* **Atomic outbox** — interval/bucket/correction writes and `mes_outbox` rows share one transaction (`PgTransactionRunner` + ambient `PgSession`). `dedupe_key` is unique per tenant, so an identical `RecomputeWindow` re-enqueues nothing.
* **RecomputeWindow is idempotent** — it rewrites only the *time* columns from closed intervals and preserves production columns; the open interval is never included (it is projected when it closes → no double count).
* **Outbox relay** — *claim-then-release*: short transaction `SELECT … FOR UPDATE SKIP LOCKED` + lease, HTTP call with no DB lock held, second short transaction for the outcome. Dead-letter after 12 claims (11 retries). `207` statuses: `applied|duplicate|stale` ⇒ done, `rejected` ⇒ dead-letter immediately. The transactional-lock alternative is described in `PgOutboxStore` XML docs.
* **Tenant isolation** — every table has `FORCE ROW LEVEL SECURITY` on `app.tenant_id` (set transaction-locally); tenant always comes from the authenticated principal. SignalR groups are `{tenant}:machine:{id}` / `{tenant}:line:{line}`; Redis keys `oee:live:{tenant}:{machine}:{scope}`. The relay uses a separate `BYPASSRLS` role (`ConnectionStrings:MesSystem`).
* **Read-only Modbus** — `IReadOnlyModbusMaster` has no write method; `ModbusTcpMaster` only ever emits FC 0x03; `BannedSymbols.txt` (BannedApiAnalyzers, RS0030) bans NModbus/FluentModbus/EasyModbus write APIs; a reflection test guards the surface.
* **Modbus decode** — two flags (`ReverseWords`, `SwapBytes`) give `ABCD/CDAB/BADC/DCBA` for 16/32/64-bit values; for `Float64` the flags apply to the whole 4-register sequence. Reads are coalesced (`maxGap ≤ 8`, ≤ 125 registers/request).

## SDK / version caveats

* **Target is `net9.0`, built here with SDK 10.** NuGet's newest `Npgsql`/`Microsoft.Data.Sqlite` (11.x) are net10-only, so they are pinned to `9.*`; `Microsoft.Extensions.*` use `10.*` (multi-targeted, they run on net9) because OPC UA and StackExchange.Redis already pull 10.x abstractions transitively.
* **OPC UA is pinned to `OPCFoundation.NetStandard.Opc.Ua.Client 1.5.378.196`.** The 2.x line requires .NET 10 packages and renames several types (`Session`/`ISession` factories, telemetry context). `PkiOpcUaConfigProvider` uses an API marked obsolete in 1.5.378 (`#pragma warning disable CS0618`, revisit on upgrade). **Untrusted server certificates are rejected by default**; an operator must trust them in `pki/trusted`.
* **MQTTnet 5.x** (`MqttClientFactory`, not 4.x's `MqttFactory`); `ApplicationMessage.Payload` is a `ReadOnlySequence<byte>`.
* **Polly v8 (`Polly.Core`)** is used directly for the Modbus pipeline; `Microsoft.Extensions.Resilience`/`Http.Resilience` provide the HTTP side. Retries sit *outside* the HMAC signing handler so each attempt carries a fresh timestamp.
* **TimescaleDB is optional**: the schema script creates hypertables only when the extension exists. RLS on hypertables works but disables some compression features — decide before enabling compression.
* `Guid.CreateVersion7()` is .NET 9+.

## Concurrency rules

1. Protocol callbacks (OPC UA notifications, MQTT receive) run on library threads: they only call `Channel.TryWrite` (`ConnectorBase.TryEmit`). A full channel drops *and counts* the sample (`ConnectorHealth.DroppedSamples`); it never blocks keep-alives.
2. One `ConnectorBase.RunAsync` loop per connector owns reconnection (exponential backoff, full jitter).
3. `MachineProcessor`, `MachineStateMachine`, `CounterTracker` are **not** thread-safe by design: only the owning pipeline partition may touch them. `MachineProcessorRegistry.Evict` rebuilds a machine from the store after a failed write.
4. `PgSession` ambient transaction = `AsyncLocal`; one transaction serves one tenant (mixing throws).
5. `WalStore` serialises access with a lock; exactly one gateway process may own the file.

## Failure modes (and what happens)

| Failure | Behaviour |
| :-- | :-- |
| Mes.Core / network down | Edge keeps appending to the WAL; publisher retries with backoff; at 80 % of the 2 GB cap oldest batches are downsampled (change-points + 1 sample/tag/10 s), at the cap oldest batches are dropped and counted in `wal_meta`. |
| Duplicate batch delivery | De-duplicated by `ingest_batch` + sample PK. |
| DB error mid-ingest | Transaction rolls back, the in-memory processor is evicted, HTTP request fails, edge resends the batch. Debounce state that was pending is lost (acceptable; bounded by the debounce window). |
| Counter rollover / reset / glitch | Bridged (modulus), counted from zero (reset), or quarantined and re-baselined without counting. |
| PLC offline | `Unknown` interval; excluded from planned time or counted as downtime per `GapPolicy`, with `CoveragePct` reported. |
| Laravel push lost | Sequence gap ⇒ pull instead of applying the payload; periodic reconcile compares manifests (id + revision) and repairs missing/stale/orphaned rows. `410 Gone` ⇒ cursor reset + re-bootstrap (once; a second 410 fails loudly). |
| Laravel down | Outbox rows stay pending, rescheduled with backoff; dead-lettered after 12 attempts and left in `mes_outbox` (`status='dead'`) for manual replay. |
| Redis down | Live view stale/absent until the next ingest; bucket table remains the source of truth. |

## Contract status — read this

The Laravel side lives in `modules/30_Manufacturing` (HMAC middleware, `mes-sync` feeds, `mes-events` receiver, `ref-events` push) and the wire contract is frozen by the golden fixtures in `contracts/mes-sync/fixtures` (see `contracts/mes-sync/README.md`), read by both this solution's `ContractFixtureTests` and Laravel's `MesContractFixturesTest`. Summary:

* `GET /api/v1/manufacturing/mes-sync/{machines|work-orders|shift-calendars|reason-codes}?after_seq=&limit=` → `{ data: [{id, seq, revision, deleted, payload}], next_seq, has_more }`; `410` when the cursor is older than retained history.
* `GET …/{resource}/manifest` → `{ items: [{id, revision}] }` of the live entities. **Single page**: Laravel serves up to `manifest_max_limit` (5000) items and exposes `next_cursor`; the client does not page yet (a tenant with more rows would see the rest as orphans).
* `POST /api/v1/mes/ref-events` (Laravel → Mes.Core) body `{tenant_id, resource, id, seq, revision, deleted, payload}`; Laravel sends payload-less nudges (the MES Core pulls), `id` is the aggregate named by the change (order, work centre), not necessarily a feed entity.
* `POST /api/v1/manufacturing/mes-events` (Mes.Core → Laravel) body `{events:[{id,type,dedupe_key,occurred_at,payload}]}` → `207 {results:[{id,status,message}]}`. `OeeSnapshotRevised` without a window (scrap correction by run) and `ProductionCountCheckpoint` without `work_order_ref` cannot be attributed and are `rejected`.
* Signature: `X-Mes-Signature: t=<unix>,kid=<id>,v1=<hex>`, `v1 = HMAC-SHA256(secret[kid], "{t}.{raw body}")`, ±5 min tolerance, constant-time compare. GET requests are signed over an empty body (the path is not bound; binding method+path would be stronger).
* `ref_machine.payload` carries only machine identity today; the profile fields of `RefMachineProfileProvider` (tag roles, ideal cycle, counter policy) are not sent by Laravel yet.

## Edge Gateway → Laravel signed telemetry (`Publisher = Laravel`)

Besides the Mes.Core ingest (default), the gateway can forward its WAL straight to `modules/30_Manufacturing`'s `POST /api/v1/manufacturing/edge/telemetry`. Choose it at start-up with `Gateway:Publisher = "Laravel"` (the WAL, batching and tag mapping are unchanged; only the forwarder differs: `Publishing/LaravelTelemetry.cs`).

**Request.** Headers `X-Gateway-Id` (uuid from `POST /api/v1/manufacturing/edge/gateways`) and `X-Signature` = lowercase hex `HMAC-SHA256(secret, raw body)`. The body is serialised **once** to bytes, signed, and those same bytes are sent (so a retry by the resilience handler is byte-identical). Body: `{"events":[{"event_uuid","machine_id","state","occurred_at","payload"}]}`, 1–500 events per request (`Gateway:Laravel:MaxEventsPerRequest`, clamped). `occurred_at` is UTC `yyyy-MM-ddTHH:mm:ss.fffZ`; `payload` = `{tag, raw, connector}`.

**Configuration** (`gateway.json` + environment; the secret is shown once at registration and must come from the environment or a secret store — `Gateway__Laravel__Secret` — never from a committed file; it is never logged):

```json
{ "Gateway": {
    "Publisher": "Laravel",
    "Laravel": { "BaseUrl": "https://erp.example.com", "GatewayUuid": "…uuid…", "Secret": "" },
    "Connectors": [ {
      "Name": "cnc-01", "MachineId": "…", "BackendMachineId": "…mfg_machine_centers.id (optional, defaults to MachineId)…",
      "StateTag": "machine_state",
      "StateMap": [ {"Match":"1","State":"RUNNING"}, {"Match":"2","State":"SETUP"}, {"Match":"3","State":"IDLE"}, {"Match":"4","State":"FAULT"}, {"Match":"5","State":"MAINTENANCE"} ],
      "Protocol": "opcua", "Tags": [ … ] } ] } }
```

**State mapping.** Adapters emit tag values, not states. The connector's `StateTag` names the (mapped) tag that carries the machine state; `StateMap` converts its raw value (number or case-insensitive text) to `RUNNING|SETUP|IDLE|FAULT|MAINTENANCE`. Text that is not listed falls back to well-known names (run/running/auto → RUNNING, setup/changeover → SETUP, idle/stopped/standby → IDLE, alarm/error/fault/estop → FAULT, maintenance/service → MAINTENANCE). Anything else is **dropped and logged** (never guessed). This is protocol-agnostic: OPC UA enums, Modbus TCP status registers, MQTT/Sparkplug strings and CNC program/status words all go through the same table. **There is no NUM-specific adapter in this repo**: a NUM CNC is reached through its OPC UA or Modbus interface and mapped with a `StateMap`. Only transitions are sent (repeats of the last state are dropped within a batch and against the last delivered state); bad-quality samples are skipped.

**Idempotency.** `event_uuid` is derived from `(gateway uuid, machine, tag, event time ms, state)` (SHA-256 → version-5-style UUID). A WAL record therefore always produces the same ids, also after a restart or after the WAL downsampled the batch, so resending never creates a new event.

**Responses.**

| Backend answer | Gateway behaviour |
| :-- | :-- |
| 200 | `accepted` and `duplicates` ⇒ batch acked and deleted from the WAL. `rejected[]` (e.g. unknown machine) ⇒ ids stored in `wal_quarantine` (`WalStore.QuarantineDetails()`), batch removed, **never retried**, logged as an error. |
| 401 / 403 | Credentials/signature wrong. Not retried blindly: logged as an error, nothing acked, the forwarder waits 1 min between attempts; data keeps buffering. Also reported without any network call when `GatewayUuid`/`Secret` are not configured. |
| 422 / other 4xx | The body itself is refused: the whole batch is moved to `wal_quarantine` (so the queue cannot stall), logged as an error. |
| 5xx, 408, 429, timeout, network, unreadable 200 | Transient: exponential backoff with jitter (`Backoff`), WAL keeps buffering and resends in order when the backend is back. |

## Counter checkpoints (restart attribution)

`counter_checkpoint(tenant, machine, key 'total'|'good', value, at)` holds the last accepted value of each cumulative counter, behind `ICounterCheckpointStore` (`PgCounterCheckpointStore`). It is written **inside the ingest transaction**, next to the buckets built from the same deltas, so a rollback can never leave a checkpoint ahead of recorded production. On restart `MachineProcessorRegistry` loads it and `CounterTracker.Restore` seeds the baseline:

* production that happened while Mes.Core was down is attributed to the first sample after restart (event time of that sample);
* that first sample uses `MaxDeltaAfterRestart` (default 100 000) instead of the strict per-sample ceiling; later samples are strict again;
* rollover is only assumed when the wrapped delta is ≤ half the modulus, so a reset to zero while down is not mis-read as a rollover (`prev=5000 → 12` is a reset: +12, not +60 548);
* a failed commit evicts the processor; the retry rebuilds from the committed checkpoint and counts exactly once.

Quarantine state (a pending anomalous level) is deliberately not persisted.

## Shift calendar port

`IShiftCalendarProvider.PlannedWindowsAsync(tenant, machine, window)` is the only thing OEE knows about calendars. Implementations: `AlwaysPlannedCalendarProvider` (default in `AddMesApplication`) and `RefShiftCalendarProvider` (registered by `AddMesPersistence`; `ref_machine.payload.shift_calendar_id` → `ref_shift_calendar.payload`, parsed by `ShiftSchedule`; no calendar ⇒ always planned, logged; invalid calendar ⇒ always planned, logged as an **error**). `ShiftSchedule` is DST-safe (local wall clock per date), handles night shifts and closures. The JSON it parses is **tentative** (`{"timezone","shifts":[{"days":[ISO weekdays],"start":"HH:mm","end":"HH:mm"}],"closures":[{"from","to"}]}`) until the Laravel payload is final; only `ShiftSchedule.Parse` needs to change.

## Gateway → Core authentication (JWT today, mTLS ready)

`Auth:Mode` in `appsettings.json` selects what `POST /api/v1/mes/ingest/batches` accepts (policy `Gateway`): `Jwt` (default, local/dev), `Certificate` (production mTLS), `Both` (migration). Other endpoints stay on JWT (UI users).

For mTLS:
1. Kestrel must *request* the client certificate (`Kestrel:Endpoints:Https:ClientCertificateMode = AllowCertificate`) — see `src/Mes.Presentation/appsettings.mtls.example.json`.
2. The certificate chain is validated by ASP.NET (`RevocationMode`, default `Online`).
3. The thumbprint must be enrolled in `Auth:Certificate:Gateways` (`Thumbprint`, `TenantId`, `GatewayId`); the **tenant claim comes from that allow-list only**. A valid CA-signed but unenrolled certificate is rejected, and startup fails if mTLS is enabled with no gateways.
4. Edge side: `Gateway:ClientCertPath` / `ClientCertPassword` (PFX) in `gateway.json`; server certificate validation stays on, TLS 1.2+.

## Testing

```powershell
$env:DOTNET_ROLL_FORWARD = "Major"   # only if this host lacks the ASP.NET Core 9 runtime
dotnet test services/mes/Mes.slnx
```

* **Unit/property**: OEE, state machine, counters (incl. restart), energy, buckets, Modbus, shift schedule, HMAC, WAL, clock, tag mapper.
* **Protocol harnesses (in-process, no Docker)**: `MqttHarnessTests` (MQTTnet.Server broker on loopback: lifecycle, JSON + Sparkplug topics with BIRTH aliases, DLQ, retained online/LWT, broker failover, backoff while no broker, back-pressure drops) and `OpcUaHarnessTests` (OPCFoundation in-process server, security None: URI node resolution, subscription + deadband, server restart recovery, backoff while down, back-pressure). Waits are condition polls with deadlines, never fixed sleeps.
* **Integration**: 7 PostgreSQL tests (RLS isolation + `WITH CHECK`, revision-guarded upserts, additive buckets + atomic rollback of bucket/outbox/checkpoint, one-open-interval index, replay idempotency, `SKIP LOCKED` claiming, counter checkpoints) and 2 Redis tests. They use `MES_TEST_PG` / `MES_TEST_REDIS` when set (CI service containers) and otherwise start Testcontainers; they are skipped only when neither is available.
* **CI**: `.github/workflows/mes-ci.yml` — PostgreSQL 16 + Redis 7 service containers, Release build with `-warnaserror`, full test run, and a step that **fails if any test was skipped** (so a mis-wired service container cannot go green).

## SDK notes added

* **OPC UA needs ICU**: the SDK (client *and* server) creates `CultureInfo("en-US")`, which throws under `InvariantGlobalization=true`. The solution therefore sets it to `false`; Linux container images need `libicu`.
* All OPC UA certificate/security setup lives in the internal adapter `OpcUaCertificateSetup`; the obsolete-API pragma is confined there (plus the test server).
* `OpcUaConnector` bounds SDK reconnection (`ReconnectTimeout`, default 60 s): a server that never returns falls through to a clean full reconnect with backoff instead of hanging in the SDK's retry loop.

## Not done / next

* Laravel side: see Contract status. Still open: breaks and planned downtime in the shift-calendar payload (`non_production` is empty), `ShiftSchedule.Parse` reading the dated UTC instances Laravel serves, the machine profile in `ref_machine.payload`, manifest paging. No Angular/Flutter/desktop UI.
* The OPC UA harness runs with security policy `None`; signed/encrypted sessions and certificate trust flows are not covered by tests.
* The shift-calendar JSON and the `ref_machine` profile payload are tentative until Laravel defines them.
* Pending debounce and quarantine state are in memory (bounded by the debounce window / a few samples).
