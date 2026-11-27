<?php

declare(strict_types=1);

namespace Modules\Observability\Metrics;

/**
 * Every metric the platform exports, with its help text, label names and histogram buckets. The label set is fixed here, so a caller can never
 * widen the cardinality by passing an unexpected label.
 */
final class MetricCatalog
{
    public const string HTTP_DURATION = 'fatturafacile_http_request_duration_seconds';

    public const string HTTP_REQUESTS = 'fatturafacile_http_requests_total';

    public const string DB_QUERY_DURATION = 'fatturafacile_db_query_duration_seconds';

    public const string DB_SLOW_QUERIES = 'fatturafacile_db_slow_queries_total';

    public const string QUEUE_JOBS = 'fatturafacile_queue_jobs_total';

    public const string QUEUE_JOB_DURATION = 'fatturafacile_queue_job_duration_seconds';

    public const string QUEUE_WAIT = 'fatturafacile_queue_wait_seconds';

    public const string QUEUE_DEPTH = 'fatturafacile_queue_depth';

    public const string QR_BILL_DURATION = 'fatturafacile_pdf_qrbill_generation_duration_seconds';

    public const string FISCAL_TRANSMISSION = 'fatturafacile_fiscal_transmission_total';

    public const string MRP_DURATION = 'fatturafacile_mrp_execution_duration_seconds';

    public const string TENANT_CONSUMPTION = 'fatturafacile_tenant_resource_consumption_total';

    public const string CONNECTOR_UP = 'fatturafacile_connector_up';

    public const string CONNECTOR_LATENCY = 'fatturafacile_connector_latency_seconds';

    public const string CONNECTOR_STATUS = 'fatturafacile_connector_status';

    public const string PG_CONNECTIONS = 'fatturafacile_pg_connections';

    public const string PG_CACHE_HIT_RATIO = 'fatturafacile_pg_cache_hit_ratio';

    public const string PG_DATABASE_SIZE = 'fatturafacile_pg_database_size_bytes';

    public const string PG_MAX_CONNECTIONS = 'fatturafacile_pg_max_connections';

    public const string PG_DEADLOCKS = 'fatturafacile_pg_deadlocks';

    /** Channels of the fiscal transmission counter. */
    public const array FISCAL_CHANNELS = ['SDI', 'PEPPOL', 'RT', 'EBILL', 'ELM'];

    /**
     * @return array{type: MetricType, help: string, labels: list<string>, buckets?: list<float>}
     */
    public static function definition(string $name): array
    {
        $duration = (array) config('observability.metrics.duration_buckets', [0.1, 0.5, 1, 5]);
        $db = (array) config('observability.metrics.db_buckets', [0.001, 0.01, 0.1, 1]);

        /** @var list<float> $duration */
        $duration = array_map(floatval(...), $duration);
        /** @var list<float> $db */
        $db = array_map(floatval(...), $db);

        return match ($name) {
            self::HTTP_DURATION => ['type' => MetricType::Histogram, 'help' => 'HTTP request duration in seconds.', 'labels' => ['method', 'route', 'status_class'], 'buckets' => $duration],
            self::HTTP_REQUESTS => ['type' => MetricType::Counter, 'help' => 'HTTP requests served.', 'labels' => ['method', 'route', 'status']],
            self::DB_QUERY_DURATION => ['type' => MetricType::Histogram, 'help' => 'Database query duration in seconds.', 'labels' => ['connection'], 'buckets' => $db],
            self::DB_SLOW_QUERIES => ['type' => MetricType::Counter, 'help' => 'Queries slower than the configured threshold.', 'labels' => ['connection']],
            self::QUEUE_JOBS => ['type' => MetricType::Counter, 'help' => 'Queue jobs by outcome.', 'labels' => ['queue', 'job', 'status']],
            self::QUEUE_JOB_DURATION => ['type' => MetricType::Histogram, 'help' => 'Queue job processing time in seconds.', 'labels' => ['queue', 'job'], 'buckets' => $duration],
            self::QUEUE_WAIT => ['type' => MetricType::Histogram, 'help' => 'Time a queue job waited before it started, in seconds.', 'labels' => ['queue'], 'buckets' => $duration],
            self::QUEUE_DEPTH => ['type' => MetricType::Gauge, 'help' => 'Jobs waiting in a queue (read at scrape time).', 'labels' => ['connection', 'queue']],
            self::QR_BILL_DURATION => ['type' => MetricType::Histogram, 'help' => 'Swiss QR-bill PDF generation time in seconds.', 'labels' => [], 'buckets' => $duration],
            self::FISCAL_TRANSMISSION => ['type' => MetricType::Counter, 'help' => 'Fiscal document transmissions by channel and outcome.', 'labels' => ['channel', 'status']],
            self::MRP_DURATION => ['type' => MetricType::Histogram, 'help' => 'MRP run duration in seconds.', 'labels' => [], 'buckets' => [0.1, 0.5, 1.0, 2.5, 5.0, 10.0, 30.0, 60.0, 120.0, 300.0, 600.0]],
            self::TENANT_CONSUMPTION => ['type' => MetricType::Counter, 'help' => 'Resources consumed per tenant (api_calls, db_seconds, queue_jobs): noisy neighbour detection.', 'labels' => ['tenant_id', 'resource']],
            self::CONNECTOR_UP => ['type' => MetricType::Gauge, 'help' => '1 when the last probe of the institutional connector succeeded.', 'labels' => ['connector']],
            self::CONNECTOR_LATENCY => ['type' => MetricType::Gauge, 'help' => 'Latency of the last probe in seconds.', 'labels' => ['connector']],
            self::CONNECTOR_STATUS => ['type' => MetricType::Gauge, 'help' => 'Connector status: 0 outage, 1 degraded, 2 operational, 3 maintenance.', 'labels' => ['connector']],
            self::PG_CONNECTIONS => ['type' => MetricType::Gauge, 'help' => 'PostgreSQL connections by state (read at scrape time).', 'labels' => ['state']],
            self::PG_CACHE_HIT_RATIO => ['type' => MetricType::Gauge, 'help' => 'PostgreSQL buffer cache hit ratio (0..1).', 'labels' => []],
            self::PG_DATABASE_SIZE => ['type' => MetricType::Gauge, 'help' => 'Size of the database in bytes.', 'labels' => []],
            self::PG_MAX_CONNECTIONS => ['type' => MetricType::Gauge, 'help' => 'PostgreSQL max_connections.', 'labels' => []],
            self::PG_DEADLOCKS => ['type' => MetricType::Gauge, 'help' => 'Deadlocks detected since the statistics reset.', 'labels' => []],
            default => ['type' => MetricType::Counter, 'help' => $name, 'labels' => []],
        };
    }
}
