<?php

declare(strict_types=1);

namespace Modules\Observability\Metrics;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;

/**
 * Prometheus counters, gauges and histograms on a shared cache store (Redis, database, memcached, APCu, array in tests), so that every PHP-FPM or
 * Octane worker adds to the same series. Counters and histogram sums are kept as integers (micro-units), the only thing every store can add atomically.
 */
final class MetricsRegistry
{
    public const int SCALE = 1_000_000;

    private const string PREFIX = 'obs:m:';

    private const string INDEX = 'obs:m:index';

    private const string TENANTS = 'obs:m:tenants';

    public function __construct(private readonly Repository $store, private readonly int $maxTenantSeries = 500) {}

    /**
     * @param  array<string, string|int>  $labels
     */
    public function increment(string $name, array $labels = [], float $by = 1.0): void
    {
        $series = $this->series($name, MetricType::Counter, $labels);
        $this->bump($series['key'], (int) round($by * self::SCALE));
    }

    /**
     * @param  array<string, string|int>  $labels
     */
    public function setGauge(string $name, float $value, array $labels = []): void
    {
        $series = $this->series($name, MetricType::Gauge, $labels);
        $this->store->forever($series['key'], $value);
    }

    /**
     * @param  array<string, string|int>  $labels
     */
    public function observe(string $name, float $value, array $labels = []): void
    {
        $definition = MetricCatalog::definition($name);
        $series = $this->series($name, MetricType::Histogram, $labels);

        foreach ($definition['buckets'] ?? [] as $bound) {
            if ($value <= $bound) {
                $this->bump($series['key'].':b:'.$bound, 1);
            }
        }

        $this->bump($series['key'].':c', 1);
        $this->bump($series['key'].':s', (int) round($value * self::SCALE));
    }

    /**
     * Adds to a tenant labelled counter; beyond the cardinality cap the tenant is folded into "other".
     *
     * @param  array<string, string|int>  $labels
     */
    public function incrementForTenant(string $name, ?string $tenantId, array $labels = [], float $by = 1.0): void
    {
        $this->increment($name, ['tenant_id' => $this->tenantLabel($tenantId)] + $labels, $by);
    }

    public function tenantLabel(?string $tenantId): string
    {
        if ($tenantId === null || $tenantId === '') {
            return 'none';
        }

        /** @var array<string, bool> $known */
        $known = $this->store->get(self::TENANTS, []);

        if (isset($known[$tenantId])) {
            return $tenantId;
        }

        if (count($known) >= $this->maxTenantSeries) {
            return 'other';
        }

        $known[$tenantId] = true;
        $this->store->forever(self::TENANTS, $known);

        return $tenantId;
    }

    /**
     * Every series with its current value, grouped by metric name, ready for the renderer.
     *
     * @return array<string, array{type: MetricType, help: string, series: list<array{labels: array<string, string>, value: float, buckets?: array<string, int>, sum?: float, count?: int}>}>
     */
    public function collect(): array
    {
        /** @var array<string, array{name: string, type: string, labels: array<string, string>}> $index */
        $index = $this->store->get(self::INDEX, []);
        $metrics = [];

        foreach ($index as $key => $entry) {
            $type = MetricType::from($entry['type']);
            $definition = MetricCatalog::definition($entry['name']);
            $metrics[$entry['name']] ??= ['type' => $type, 'help' => $definition['help'], 'series' => []];
            $base = self::PREFIX.$key;

            if ($type === MetricType::Histogram) {
                $buckets = [];

                foreach ($definition['buckets'] ?? [] as $bound) {
                    $buckets[(string) $bound] = (int) $this->store->get($base.':b:'.$bound, 0);
                }

                $metrics[$entry['name']]['series'][] = [
                    'labels' => $entry['labels'],
                    'value' => 0.0,
                    'buckets' => $buckets,
                    'sum' => (int) $this->store->get($base.':s', 0) / self::SCALE,
                    'count' => (int) $this->store->get($base.':c', 0),
                ];

                continue;
            }

            $raw = $this->store->get($base, 0);
            $metrics[$entry['name']]['series'][] = [
                'labels' => $entry['labels'],
                'value' => $type === MetricType::Counter ? ((int) $raw) / self::SCALE : (float) $raw,
            ];
        }

        ksort($metrics);

        return $metrics;
    }

    public function flush(): void
    {
        /** @var array<string, mixed> $index */
        $index = $this->store->get(self::INDEX, []);

        foreach (array_keys($index) as $key) {
            $this->store->forget(self::PREFIX.$key);
            $this->store->forget(self::PREFIX.'seen:'.$key);
        }

        $this->store->forget(self::INDEX);
        $this->store->forget(self::TENANTS);
    }

    /**
     * @param  array<string, string|int>  $labels
     * @return array{key: string}
     */
    private function series(string $name, MetricType $type, array $labels): array
    {
        $clean = [];

        foreach (MetricCatalog::definition($name)['labels'] as $label) {
            $clean[$label] = (string) ($labels[$label] ?? '');
        }

        $key = $name.':'.substr(sha1(json_encode($clean, JSON_THROW_ON_ERROR)), 0, 16);

        // The first writer of a series registers it in the index; add() is atomic, so only one does.
        if ($this->store->add(self::PREFIX.'seen:'.$key, 1)) {
            $this->register($key, ['name' => $name, 'type' => $type->value, 'labels' => $clean]);
        }

        return ['key' => self::PREFIX.$key];
    }

    /**
     * Atomic add; stores such as the database one do not create a missing key on increment.
     */
    private function bump(string $key, int $by): void
    {
        if ($this->store->increment($key, $by) === false) {
            $this->store->add($key, 0);
            $this->store->increment($key, $by);
        }
    }

    /**
     * @param  array{name: string, type: string, labels: array<string, string>}  $entry
     */
    private function register(string $key, array $entry): void
    {
        $write = function () use ($key, $entry): void {
            $index = $this->store->get(self::INDEX, []);
            $index[$key] = $entry;
            $this->store->forever(self::INDEX, $index);
        };

        $store = $this->store->getStore();

        if ($store instanceof LockProvider) {
            $store->lock(self::INDEX.':lock', 5)->block(3, $write);

            return;
        }

        $write();
    }
}
