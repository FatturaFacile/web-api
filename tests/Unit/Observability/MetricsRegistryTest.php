<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Modules\Observability\Metrics\MetricCatalog;
use Modules\Observability\Metrics\MetricsRegistry;
use Modules\Observability\Metrics\MetricType;
use Modules\Observability\Metrics\PrometheusRenderer;

function obsRegistry(int $maxTenants = 500): MetricsRegistry
{
    Cache::store('array')->flush();

    return new MetricsRegistry(Cache::store('array'), $maxTenants);
}

it('counts and exposes counters, accumulating fractions', function (): void {
    $registry = obsRegistry();
    $registry->increment(MetricCatalog::HTTP_REQUESTS, ['method' => 'GET', 'route' => '/x', 'status' => '200']);
    $registry->increment(MetricCatalog::HTTP_REQUESTS, ['method' => 'GET', 'route' => '/x', 'status' => '200']);
    $registry->increment(MetricCatalog::HTTP_REQUESTS, ['method' => 'GET', 'route' => '/x', 'status' => '500']);
    $registry->increment(MetricCatalog::TENANT_CONSUMPTION, ['tenant_id' => 't1', 'resource' => 'db_seconds'], 0.25);
    $registry->increment(MetricCatalog::TENANT_CONSUMPTION, ['tenant_id' => 't1', 'resource' => 'db_seconds'], 0.5);

    $text = (new PrometheusRenderer)->render($registry->collect());

    expect($text)->toContain('# TYPE fatturafacile_http_requests_total counter')
        ->and($text)->toContain('fatturafacile_http_requests_total{method="GET",route="/x",status="200"} 2')
        ->and($text)->toContain('fatturafacile_http_requests_total{method="GET",route="/x",status="500"} 1')
        ->and($text)->toContain('fatturafacile_tenant_resource_consumption_total{tenant_id="t1",resource="db_seconds"} 0.75');
});

it('records histograms with cumulative buckets, sum and count', function (): void {
    $registry = obsRegistry();
    $registry->observe(MetricCatalog::HTTP_DURATION, 0.3, ['method' => 'GET', 'route' => '/x', 'status_class' => '2xx']);
    $registry->observe(MetricCatalog::HTTP_DURATION, 0.02, ['method' => 'GET', 'route' => '/x', 'status_class' => '2xx']);

    $text = (new PrometheusRenderer)->render($registry->collect());

    expect($text)->toContain('# TYPE fatturafacile_http_request_duration_seconds histogram')
        ->and($text)->toContain('fatturafacile_http_request_duration_seconds_bucket{method="GET",route="/x",status_class="2xx",le="0.025"} 1')
        ->and($text)->toContain('fatturafacile_http_request_duration_seconds_bucket{method="GET",route="/x",status_class="2xx",le="0.5"} 2')
        ->and($text)->toContain('le="+Inf"} 2')
        ->and($text)->toContain('fatturafacile_http_request_duration_seconds_sum{method="GET",route="/x",status_class="2xx"} 0.32')
        ->and($text)->toContain('fatturafacile_http_request_duration_seconds_count{method="GET",route="/x",status_class="2xx"} 2');
});

it('sets gauges and keeps the last value', function (): void {
    $registry = obsRegistry();
    $registry->setGauge(MetricCatalog::QUEUE_DEPTH, 7, ['connection' => 'redis', 'queue' => 'default']);
    $registry->setGauge(MetricCatalog::QUEUE_DEPTH, 3, ['connection' => 'redis', 'queue' => 'default']);

    expect((new PrometheusRenderer)->render($registry->collect()))->toContain('fatturafacile_queue_depth{connection="redis",queue="default"} 3');
});

it('ignores labels outside the catalog so the cardinality cannot grow', function (): void {
    $registry = obsRegistry();
    $registry->increment(MetricCatalog::FISCAL_TRANSMISSION, ['channel' => 'SDI', 'status' => 'success', 'user_id' => 'u-1']);
    $registry->increment(MetricCatalog::FISCAL_TRANSMISSION, ['channel' => 'SDI', 'status' => 'success', 'user_id' => 'u-2']);

    $series = $registry->collect()[MetricCatalog::FISCAL_TRANSMISSION]['series'];

    expect($series)->toHaveCount(1)
        ->and($series[0]['labels'])->toBe(['channel' => 'SDI', 'status' => 'success'])
        ->and($series[0]['value'])->toBe(2.0);
});

it('folds the tenants beyond the cap into "other"', function (): void {
    $registry = obsRegistry(2);

    expect($registry->tenantLabel('t1'))->toBe('t1')
        ->and($registry->tenantLabel('t2'))->toBe('t2')
        ->and($registry->tenantLabel('t3'))->toBe('other')
        ->and($registry->tenantLabel('t1'))->toBe('t1')
        ->and($registry->tenantLabel(null))->toBe('none');

    $registry->incrementForTenant(MetricCatalog::TENANT_CONSUMPTION, 't3', ['resource' => 'api_calls']);

    expect((new PrometheusRenderer)->render($registry->collect()))->toContain('tenant_id="other",resource="api_calls"} 1');
});

it('escapes label values and exposes the type of every metric of the catalog', function (): void {
    $registry = obsRegistry();
    $registry->increment(MetricCatalog::HTTP_REQUESTS, ['method' => 'GET', 'route' => "/a\"b\\c\nd", 'status' => '200']);

    expect((new PrometheusRenderer)->render($registry->collect()))->toContain('route="/a\\"b\\\\c\\nd"')
        ->and(MetricCatalog::definition(MetricCatalog::QR_BILL_DURATION)['type'])->toBe(MetricType::Histogram)
        ->and(MetricCatalog::definition(MetricCatalog::MRP_DURATION)['type'])->toBe(MetricType::Histogram)
        ->and(MetricCatalog::definition(MetricCatalog::FISCAL_TRANSMISSION)['labels'])->toBe(['channel', 'status'])
        ->and(MetricCatalog::FISCAL_CHANNELS)->toBe(['SDI', 'PEPPOL', 'RT', 'EBILL', 'ELM']);
});
