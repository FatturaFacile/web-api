# Enterprise observability (Mod 202, Issue #448)

Module `modules/88_Observability` (`Modules\Observability`), config `config/observability.php`, stack in `deploy/monitoring/`.

## 1. Distributed tracing (OpenTelemetry)

- **W3C Trace Context**: the global `ObservabilityMiddleware` continues the `traceparent` / `tracestate` sent by Angular, WPF, Flutter or .NET, or starts a
  new trace, and answers with `X-Trace-Id` and `traceresponse` (both exposed by CORS, so the SPA can show the id of a failing call).
- **Propagation**: queued jobs (and queued listeners / domain events, which are jobs) carry `traceparent`, `tracestate`, the enqueue time and the
  tenant in their payload; the worker continues the trace in a consumer span. Outgoing `Http::` calls send `traceparent` and get a client span
  (`OBSERVABILITY_PROPAGATE_OUTGOING`, `tracing.propagate_hosts` limits it to a list of hosts; a call made with `->withOptions(['observability_skip' => true])` is not traced).
  Artisan commands get a span (long-running ones are skipped, see `tracing.skip_commands`).
- **Export**: OTLP over HTTP with JSON encoding to `OTEL_EXPORTER_OTLP_ENDPOINT` (or `OTEL_EXPORTER_OTLP_TRACES_ENDPOINT`), `OTEL_EXPORTER_OTLP_HEADERS`,
  `OTEL_SERVICE_NAME`, `OTEL_TRACES_SAMPLER_ARG` (root sampling ratio, a sampled parent is always followed), `OTEL_SDK_DISABLED`.
  Spans are exported after the response is sent. **gRPC and protobuf**: the PHP side implements the JSON encoding natively (no extension needed);
  put the OpenTelemetry Collector in front (`deploy/monitoring/otel-collector/config.yml` receives OTLP gRPC :4317 and OTLP/HTTP :4318 and converts to the backend protocol).
  The exporter sits behind the `SpanExporter` interface, so an `open-telemetry/sdk` based exporter can replace it without touching the rest.
- **Structured logs**: channel `observability` (`LOG_STACK=observability`) writes one JSON object per line with `trace_id`, `span_id`, `tenant_id`.
  The channels `single` and `daily` get the same enrichment and scrubbing through `EnrichLogTap` (format unchanged).

## 2. PII scrubbing (GDPR / nDSG)

`Privacy\PiiScrubber` masks, in log messages and context, span names, attributes, events and status messages: IBAN, AHV/AVS numbers, Codice Fiscale, UID (CHE), VAT numbers
(IT and EU), e-mails, phone numbers, bearer tokens and JWTs in free text, and the **value of every sensitive key** (names, addresses, e-mail, phone, IBAN, tokens, passwords...).
`Privacy\SqlSanitizer` removes every literal from SQL (strings, numbers, `$n`, `IN` lists, multi-row `VALUES`) and from the filters of an execution plan.
**The bindings of a query are never stored.**

## 3. Prometheus metrics

`GET /metrics` (text exposition 0.0.4), guarded by `OBSERVABILITY_METRICS_TOKEN` (bearer, or `X-Metrics-Token`) and/or `OBSERVABILITY_METRICS_ALLOWED_IPS`
(addresses and CIDR); with neither configured the endpoint answers 404. The edge is HTTPS-only: scrape the public https URL, or add `metrics` to
`SECURITY_HTTP_ALLOWED_PATHS` to scrape the container over the private network (always together with the IP allow-list).

Counters live in a shared cache store (`OBSERVABILITY_METRICS_STORE`: redis, database or memcached in a multi-worker PHP-FPM / Octane setup).

| Metric | Type | Labels |
| --- | --- | --- |
| `fatturafacile_http_request_duration_seconds` / `_requests_total` | histogram / counter | method, route (template), status_class / status |
| `fatturafacile_db_query_duration_seconds`, `fatturafacile_db_slow_queries_total` | histogram / counter | connection |
| `fatturafacile_queue_jobs_total`, `_queue_job_duration_seconds`, `_queue_wait_seconds`, `_queue_depth` | counter / histogram / histogram / gauge | queue, job, status |
| `fatturafacile_pdf_qrbill_generation_duration_seconds` | histogram | |
| `fatturafacile_fiscal_transmission_total` | counter | channel (`SDI`, `PEPPOL`, `RT`, `EBILL`, `ELM`), status (`success`, `failure`) |
| `fatturafacile_mrp_execution_duration_seconds` | histogram | |
| `fatturafacile_tenant_resource_consumption_total` | counter | tenant_id, resource (`api_calls`, `db_seconds`, `queue_jobs`) |
| `fatturafacile_connector_up`, `_connector_latency_seconds`, `_connector_status` | gauge | connector |
| `fatturafacile_pg_connections`, `_pg_cache_hit_ratio`, `_pg_database_size_bytes`, `_pg_max_connections`, `_pg_deadlocks` | gauge (read at scrape time, PostgreSQL only) | state |

Cardinality: labels are fixed by `MetricCatalog` (an unknown label is dropped); `tenant_id` is the only high-cardinality label, limited to
`OBSERVABILITY_METRICS_MAX_TENANTS` (default 500), the others are folded into `tenant_id="other"`.

Instrumented today: HTTP, queue, DB, tenant consumption, QR-bill PDF (`SwissQrBillGenerator`), MRP (`MrpService::executeMrpRun`), SDI (`TenantSdiProvider`) and eBill
(`EbillDeliveryService`) transmissions. PEPPOL, RT and ELM are in the catalog and exposed as soon as their senders call
`DomainMetrics::fiscalTransmission('PEPPOL', 'success'|'failure')` (or wrap the call in `DomainMetrics::transmission('PEPPOL', fn () => ...)`).

### Grafana

`deploy/monitoring/grafana/` (provisioning + 4 dashboards: Infrastructure & PostgreSQL, Multi-Tenant Performance & Quotas, CH/IT Fiscal Connectors Health,
Queue Workers & Scheduled Jobs), `deploy/monitoring/prometheus/` (scrape config and the predictive alert rules: latency trend, noisy neighbour, connection exhaustion,
queue backlog, connector outage, failing transmissions), `docker-compose.monitoring.yml` (Prometheus, Grafana, Tempo, OpenTelemetry Collector).
Put the scrape token in `deploy/monitoring/prometheus/secrets/metrics-token` and the Grafana admin password in `deploy/monitoring/grafana/secrets/admin-password` (git-ignored).

## 4. SQL profiler (platform administrator)

A `DB::listen` hook measures every query. A query slower than the threshold (default **250 ms**, runtime setting) and every N+1 pattern (the same SELECT fingerprint at least
`n_plus_one_threshold` times in one request / job / command) is stored in `observability_slow_queries` after the response is sent, with the sanitized SQL, duration, `tenant_id`,
`trace_id` and the route / job / command of origin.

- **EXPLAIN** (PostgreSQL only, setting `explain_enabled`): `EXPLAIN (ANALYZE false, FORMAT JSON)` (+ `BUFFERS` on PostgreSQL 18+), only for a single SELECT without write or
  side-effect functions, inside a transaction that is `READ ONLY` with `SET LOCAL statement_timeout`, always rolled back; never inside a caller's transaction.
  `OBSERVABILITY_EXPLAIN_ANALYZE=true` switches to `EXPLAIN (ANALYZE, BUFFERS)` under the same guards, so a mutation can never be re-executed. SQLite reports the plan as `unsupported`.
- **Missing Index Advisor** (`IndexAdvisor`): expensive sequential scans with filters, join keys of scanned tables and sort keys of a scan become `CREATE INDEX CONCURRENTLY IF NOT EXISTS ...`
  suggestions, with `tenant_id` leading on tenant-owned tables. Suggestions only: nothing is executed.
- **pg_stat_statements**: `GET .../profiler/statements`; when the extension is missing or unreadable the answer is `available: false` with a `reason` and a localized `hint`.
- **Retention**: `observability:prune` (daily 03:30) deletes diagnostics older than `retention_days` (default 14). Capturing is off in the `testing` environment (`OBSERVABILITY_CAPTURE_ENABLED`).

Super-Admin API (`platform.admin`, base `/api/v1/admin/observability`): `GET profiler/slow-queries`, `GET profiler/slow-endpoints` (p50/p95/p99, queries per request, trace),
`GET profiler/statements`, `GET profiler/noisy-neighbors`, `GET|PUT profiler/settings`, `GET connectors`, `GET connectors/{key}/history`, `POST connectors/probe`,
`PUT connectors/{key}/maintenance`. Tenant-facing: `GET /api/v1/system/connector-status`.

## 5. Institutional connector probes

`observability:probe-connectors` (every 5 minutes, one queued `ProbeConnectorJob` per connector) checks reachability, HTTP status, latency and the TLS certificate
(verified handshake, days to expiry) of SDI / Agenzia delle Entrate, Sistema TS, Swissdec ELM, ZEFIX, UID-Register, VIES, SNB, ECB and Six Paynet / eBill. The URLs are in
`observability.connectors.catalog` (override with `OBSERVABILITY_CONNECTOR_<KEY>_URL`). Status: `maintenance` (set by the administrator) over `outage` (3 failed probes in a row),
`degraded` (last probe failed, slower than `alert_connector_latency_ms`, 24 h uptime below 95 %, certificate expiring within 7 days) and `operational`.
