<?php

declare(strict_types=1);

/**
 * Enterprise observability (Mod 202, Issue #448): W3C trace context + OTLP export, Prometheus metrics, the SQL profiler and the
 * synthetic probes of the institutional connectors. Every value can be overridden from the environment; the standard OTEL_* names are honoured.
 */
return [
    'enabled' => (bool) env('OBSERVABILITY_ENABLED', true),

    'service' => [
        'name' => env('OTEL_SERVICE_NAME', 'fatturafacile-api'),
        'version' => env('OTEL_SERVICE_VERSION', env('APP_VERSION', '1.0.0')),
        'environment' => env('OTEL_DEPLOYMENT_ENVIRONMENT', env('APP_ENV', 'production')),
    ],

    'tracing' => [
        'enabled' => ! (bool) env('OTEL_SDK_DISABLED', false),
        // Ratio of the new root traces that are exported (0..1). A parent that is already sampled is always followed.
        'sampler_ratio' => (float) env('OTEL_TRACES_SAMPLER_ARG', 1.0),
        // "otlp" sends the finished spans to the collector, "none" only keeps the trace ids (logs, headers) without exporting.
        'exporter' => env('OTEL_TRACES_EXPORTER', 'otlp'),
        'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT'),
        'traces_endpoint' => env('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT'),
        // OTLP/HTTP with JSON encoding. gRPC and protobuf are served by the OpenTelemetry Collector placed in front (see docs/observability.md).
        'headers' => env('OTEL_EXPORTER_OTLP_HEADERS'),
        'timeout_seconds' => (float) env('OTEL_EXPORTER_OTLP_TIMEOUT', 2),
        'max_batch_size' => (int) env('OTEL_BSP_MAX_EXPORT_BATCH_SIZE', 200),
        // Outgoing HTTP calls carry the traceparent header. A list of hosts limits it, null means every host.
        'propagate_outgoing' => (bool) env('OBSERVABILITY_PROPAGATE_OUTGOING', true),
        'propagate_hosts' => null,
        'skip_paths' => ['up', 'metrics', 'api/v1/health/ready', 'api/v1/broadcasting/auth'],
        'skip_commands' => ['queue:work', 'queue:listen', 'schedule:work', 'reverb:start', 'octane:start', 'serve', 'tinker', 'pail'],
    ],

    'metrics' => [
        'enabled' => (bool) env('OBSERVABILITY_METRICS_ENABLED', true),
        // Cache store that holds the counters. Use a shared store (redis, database, memcached) when several PHP-FPM or Octane workers run.
        'store' => env('OBSERVABILITY_METRICS_STORE'),
        // The scrape endpoint answers only to this bearer token and/or to these addresses or CIDR ranges.
        'token' => env('OBSERVABILITY_METRICS_TOKEN'),
        'allowed_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('OBSERVABILITY_METRICS_ALLOWED_IPS', ''))))),
        // Upper bound of distinct tenant_id label values: the others are folded into tenant_id="other" (cardinality guard).
        'max_tenant_series' => (int) env('OBSERVABILITY_METRICS_MAX_TENANTS', 500),
        // Queues whose depth is exported at scrape time as "connection:queue".
        'queues' => array_values(array_filter(array_map('trim', explode(',', (string) env('OBSERVABILITY_METRICS_QUEUES', 'default:default'))))),
        'duration_buckets' => [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0, 30.0],
        'db_buckets' => [0.0005, 0.001, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0],
    ],

    'profiler' => [
        // Defaults of the settings that a platform administrator can change at runtime (stored in observability_settings).
        'slow_query_threshold_ms' => (int) env('OBSERVABILITY_SLOW_QUERY_MS', 250),
        'n_plus_one_threshold' => (int) env('OBSERVABILITY_N_PLUS_ONE_THRESHOLD', 10),
        'explain_enabled' => (bool) env('OBSERVABILITY_EXPLAIN_ENABLED', true),
        'alert_endpoint_p95_ms' => (int) env('OBSERVABILITY_ALERT_ENDPOINT_P95_MS', 1500),
        'alert_tenant_share_pct' => (float) env('OBSERVABILITY_ALERT_TENANT_SHARE_PCT', 40),
        'alert_connector_latency_ms' => (int) env('OBSERVABILITY_ALERT_CONNECTOR_LATENCY_MS', 3000),
        'retention_days' => (int) env('OBSERVABILITY_RETENTION_DAYS', 14),
        // Persisting captures and endpoint samples adds writes after each request: off in the test environment (tests turn it on explicitly).
        'capture_enabled' => (bool) env('OBSERVABILITY_CAPTURE_ENABLED', env('APP_ENV') !== 'testing'),
        // Not runtime-editable.
        'explain_analyze' => (bool) env('OBSERVABILITY_EXPLAIN_ANALYZE', false),
        'explain_timeout_ms' => (int) env('OBSERVABILITY_EXPLAIN_TIMEOUT_MS', 2000),
        'max_explains_per_request' => 3,
        'max_captures_per_request' => 20,
        'endpoint_sample_ratio' => (float) env('OBSERVABILITY_ENDPOINT_SAMPLE_RATIO', 0.25),
        // Total plan cost from which a sequential scan is worth an index suggestion.
        'advisor_min_total_cost' => (float) env('OBSERVABILITY_ADVISOR_MIN_COST', 1000),
        'noisy_neighbor_min_requests' => 50,
    ],

    'connectors' => [
        'probe_interval_minutes' => (int) env('OBSERVABILITY_PROBE_INTERVAL_MINUTES', 5),
        'timeout_seconds' => (float) env('OBSERVABILITY_PROBE_TIMEOUT', 8),
        'tls_warning_days' => 7,
        'outage_after_failures' => 3,
        'degraded_uptime_pct' => 95.0,
        // Institutional services probed from the server. The URL can be overridden per connector with OBSERVABILITY_CONNECTOR_<KEY>_URL.
        'catalog' => [
            'sdi' => ['country' => 'IT', 'group' => 'fiscal', 'url' => env('OBSERVABILITY_CONNECTOR_SDI_URL', 'https://ivaservizi.agenziaentrate.gov.it/portale/'), 'expected' => [200, 301, 302, 401, 403]],
            'sts' => ['country' => 'IT', 'group' => 'fiscal', 'url' => env('OBSERVABILITY_CONNECTOR_STS_URL', 'https://sistemats1.sanita.finanze.it/'), 'expected' => [200, 301, 302, 401, 403]],
            'swissdec_elm' => ['country' => 'CH', 'group' => 'fiscal', 'url' => env('OBSERVABILITY_CONNECTOR_SWISSDEC_ELM_URL', 'https://www.swissdec.ch/'), 'expected' => [200, 301, 302]],
            'zefix' => ['country' => 'CH', 'group' => 'registry', 'url' => env('OBSERVABILITY_CONNECTOR_ZEFIX_URL', 'https://www.zefix.admin.ch/'), 'expected' => [200, 301, 302]],
            'uid_register' => ['country' => 'CH', 'group' => 'registry', 'url' => env('OBSERVABILITY_CONNECTOR_UID_REGISTER_URL', 'https://www.uid.admin.ch/'), 'expected' => [200, 301, 302]],
            'vies' => ['country' => 'EU', 'group' => 'registry', 'url' => env('OBSERVABILITY_CONNECTOR_VIES_URL', 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-status'), 'expected' => [200]],
            'snb' => ['country' => 'CH', 'group' => 'rates', 'url' => env('OBSERVABILITY_CONNECTOR_SNB_URL', 'https://data.snb.ch/'), 'expected' => [200, 301, 302]],
            'ecb' => ['country' => 'EU', 'group' => 'rates', 'url' => env('OBSERVABILITY_CONNECTOR_ECB_URL', 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml'), 'expected' => [200]],
            'ebill' => ['country' => 'CH', 'group' => 'payments', 'url' => env('OBSERVABILITY_CONNECTOR_EBILL_URL', 'https://www.six-group.com/en/products-services/banking-services/paynet.html'), 'expected' => [200, 301, 302]],
        ],
    ],
];
