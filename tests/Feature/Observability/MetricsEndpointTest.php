<?php

declare(strict_types=1);

use Modules\Observability\Metrics\MetricCatalog;
use Modules\Observability\Metrics\MetricsRegistry;
use Modules\Observability\Metrics\PrometheusRenderer;

require_once __DIR__.'/ObservabilitySupport.php';

it('hides the scrape endpoint when nothing is configured', function (): void {
    config(['observability.metrics.token' => null, 'observability.metrics.allowed_ips' => []]);

    $this->get('/metrics')->assertNotFound();
});

it('hides the scrape endpoint when metrics are disabled', function (): void {
    config(['observability.metrics.token' => 'scrape-secret', 'observability.metrics.enabled' => false]);

    $this->get('/metrics', ['Authorization' => 'Bearer scrape-secret'])->assertNotFound();
});

it('refuses a missing or wrong token', function (): void {
    config(['observability.metrics.token' => 'scrape-secret', 'observability.metrics.allowed_ips' => []]);

    $this->get('/metrics')->assertForbidden();
    $this->get('/metrics', ['Authorization' => 'Bearer nope'])->assertForbidden();
    $this->get('/metrics', ['X-Metrics-Token' => 'nope'])->assertForbidden();
});

it('serves the Prometheus text format to the scraper token', function (): void {
    config(['observability.metrics.token' => 'scrape-secret', 'observability.metrics.allowed_ips' => []]);
    app(MetricsRegistry::class)->increment(MetricCatalog::FISCAL_TRANSMISSION, ['channel' => 'SDI', 'status' => 'success']);

    $response = $this->get('/metrics', ['Authorization' => 'Bearer scrape-secret'])->assertOk();

    expect($response->headers->get('Content-Type'))->toBe(PrometheusRenderer::CONTENT_TYPE)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->getContent())->toContain('# HELP fatturafacile_fiscal_transmission_total')
        ->and($response->getContent())->toContain('# TYPE fatturafacile_fiscal_transmission_total counter')
        ->and($response->getContent())->toContain('fatturafacile_fiscal_transmission_total{channel="SDI",status="success"} 1');

    $this->get('/metrics', ['X-Metrics-Token' => 'scrape-secret'])->assertOk();
});

it('accepts a scraper from an allowed address without a token and refuses the others', function (): void {
    config(['observability.metrics.token' => null, 'observability.metrics.allowed_ips' => ['203.0.113.0/24']]);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/metrics')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->get('/metrics')->assertForbidden();
});

it('exports the gauges that are read at scrape time without failing on an unknown queue', function (): void {
    config([
        'observability.metrics.token' => 'scrape-secret',
        'observability.metrics.queues' => ['sync:default', 'missing:default'],
    ]);

    $body = $this->get('/metrics', ['Authorization' => 'Bearer scrape-secret'])->assertOk()->getContent();

    expect($body)->toContain('fatturafacile_queue_depth{connection="sync",queue="default"} 0');
});

it('does not count the scrape itself as a traced request', function (): void {
    config(['observability.metrics.token' => 'scrape-secret']);

    $this->get('/metrics', ['Authorization' => 'Bearer scrape-secret'])->assertOk()->assertHeaderMissing('X-Trace-Id');
});
