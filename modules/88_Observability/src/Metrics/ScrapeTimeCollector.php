<?php

declare(strict_types=1);

namespace Modules\Observability\Metrics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Gauges that are read when Prometheus scrapes: the depth of the queues and, on PostgreSQL, connections, cache hit ratio, size and deadlocks.
 */
final class ScrapeTimeCollector
{
    public function __construct(private readonly MetricsRegistry $registry) {}

    public function collect(): void
    {
        $this->queues();
        $this->postgres();
    }

    private function queues(): void
    {
        foreach ((array) config('observability.metrics.queues', []) as $entry) {
            [$connection, $queue] = array_pad(explode(':', (string) $entry, 2), 2, 'default');

            try {
                $this->registry->setGauge(MetricCatalog::QUEUE_DEPTH, (float) Queue::connection($connection)->size($queue), ['connection' => $connection, 'queue' => $queue]);
            } catch (Throwable) {
                // A queue that cannot be sized is simply absent from this scrape.
            }
        }
    }

    private function postgres(): void
    {
        try {
            $connection = DB::connection();

            if ($connection->getDriverName() !== 'pgsql') {
                return;
            }

            foreach ($connection->select('SELECT COALESCE(state, \'unknown\') AS state, COUNT(*) AS total FROM pg_stat_activity WHERE datname = current_database() GROUP BY state') as $row) {
                $this->registry->setGauge(MetricCatalog::PG_CONNECTIONS, (float) $row->total, ['state' => (string) $row->state]);
            }

            $stats = $connection->selectOne('SELECT blks_hit, blks_read, deadlocks, pg_database_size(current_database()) AS size FROM pg_stat_database WHERE datname = current_database()');

            if ($stats !== null) {
                $total = (float) $stats->blks_hit + (float) $stats->blks_read;
                $this->registry->setGauge(MetricCatalog::PG_CACHE_HIT_RATIO, $total > 0 ? (float) $stats->blks_hit / $total : 1.0);
                $this->registry->setGauge(MetricCatalog::PG_DATABASE_SIZE, (float) $stats->size);
                $this->registry->setGauge(MetricCatalog::PG_DEADLOCKS, (float) $stats->deadlocks);
            }

            $max = $connection->selectOne('SHOW max_connections');

            if ($max !== null) {
                $this->registry->setGauge(MetricCatalog::PG_MAX_CONNECTIONS, (float) ((array) $max)['max_connections']);
            }
        } catch (Throwable) {
            // The database statistics are optional.
        }
    }
}
