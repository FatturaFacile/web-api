<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Observability\Jobs\ProbeConnectorJob;
use Modules\Observability\Metrics\DomainMetrics;
use Modules\Observability\Metrics\MetricCatalog;
use Modules\Observability\Metrics\MetricsRegistry;
use Modules\Observability\Tracing\InMemorySpanExporter;
use Modules\Observability\Tracing\Span;
use Modules\Observability\Tracing\SpanExporter;
use Modules\Observability\Tracing\Tracer;

require_once __DIR__.'/ObservabilitySupport.php';

beforeEach(function (): void {
    $this->exporter = new InMemorySpanExporter;
    app()->instance(SpanExporter::class, $this->exporter);
    config(['observability.enabled' => true]);
});

it('continues the trace of the caller and answers with the trace id', function (): void {
    $trace = '4bf92f3577b34da6a3ce929d0e0e4736';

    $response = $this->withHeaders(['traceparent' => "00-{$trace}-00f067aa0ba902b7-01", 'tracestate' => 'vendor=1'])
        ->getJson('/api/v1/system/connector-status');

    $response->assertUnauthorized()
        ->assertHeader('X-Trace-Id', $trace);
    expect($response->headers->get('traceresponse'))->toStartWith("00-{$trace}-");

    $server = collect($this->exporter->spans)->first(fn (Span $span): bool => $span->name === 'GET /api/v1/system/connector-status');

    expect($server)->not->toBeNull()
        ->and($server->traceId)->toBe($trace)
        ->and($server->parentSpanId)->toBe('00f067aa0ba902b7')
        ->and($server->traceState)->toBe('vendor=1')
        ->and($server->attributes()['http.response.status_code'])->toBe(401)
        ->and($server->attributes()['http.route'])->toBe('/api/v1/system/connector-status');
});

it('starts a new trace for a caller without a traceparent and ignores a broken one', function (): void {
    $first = $this->getJson('/api/v1/system/connector-status');
    $second = $this->withHeaders(['traceparent' => 'garbage'])->getJson('/api/v1/system/connector-status');

    expect($first->headers->get('X-Trace-Id'))->toMatch('/^[0-9a-f]{32}$/')
        ->and($second->headers->get('X-Trace-Id'))->toMatch('/^[0-9a-f]{32}$/')
        ->and($first->headers->get('X-Trace-Id'))->not->toBe($second->headers->get('X-Trace-Id'));
});

it('does not trace the skipped paths', function (): void {
    $this->get('/up')->assertHeaderMissing('X-Trace-Id');
});

it('does nothing when observability is disabled', function (): void {
    config(['observability.enabled' => false]);

    $this->getJson('/api/v1/system/connector-status')->assertHeaderMissing('X-Trace-Id');
});

it('counts the requests and their duration per route template', function (): void {
    $this->getJson('/api/v1/system/connector-status');

    $metrics = app(MetricsRegistry::class)->collect();
    $requests = collect($metrics[MetricCatalog::HTTP_REQUESTS]['series'])->first(fn (array $series): bool => $series['labels']['route'] === '/api/v1/system/connector-status');

    expect($requests['labels'])->toBe(['method' => 'GET', 'route' => '/api/v1/system/connector-status', 'status' => '401'])
        ->and($requests['value'])->toBe(1.0)
        ->and($metrics)->toHaveKey(MetricCatalog::HTTP_DURATION);
});

it('counts a tenant request in the noisy neighbour counter', function (): void {
    obsMember($this);

    $this->getJson('/api/v1/system/connector-status')->assertOk();

    $series = collect(app(MetricsRegistry::class)->collect()[MetricCatalog::TENANT_CONSUMPTION]['series'])
        ->first(fn (array $series): bool => $series['labels']['resource'] === 'api_calls');

    expect($series['labels']['tenant_id'])->toBe($this->tenant->id);
});

it('propagates the trace into a queued job and counts it', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    obsFakeTls();
    $tracer = app(Tracer::class);
    $root = $tracer->startSpan('root');

    ProbeConnectorJob::dispatch('vies');

    $tracer->endSpan($root);
    $tracer->flush();

    $consumer = collect($this->exporter->spans)->first(fn (Span $span): bool => str_starts_with($span->name, 'queue.process'));

    expect($consumer)->not->toBeNull()
        ->and($consumer->name)->toBe('queue.process ProbeConnectorJob')
        ->and($consumer->traceId)->toBe($root->traceId)
        ->and($consumer->parentSpanId)->toBe($root->spanId)
        ->and($consumer->status)->toBe(Span::STATUS_OK);

    $jobs = collect(app(MetricsRegistry::class)->collect()[MetricCatalog::QUEUE_JOBS]['series'])->first(fn (array $series): bool => $series['labels']['job'] === 'ProbeConnectorJob');

    expect($jobs['labels']['status'])->toBe('processed');
});

it('marks a failing job as an error span and a failed job metric', function (): void {
    $tracer = app(Tracer::class);
    $root = $tracer->startSpan('root');

    try {
        dispatch(new ObsFailingJob);
    } catch (RuntimeException) {
        // the sync queue rethrows
    }

    $tracer->endSpan($root);
    $tracer->flush();

    $consumer = collect($this->exporter->spans)->first(fn (Span $span): bool => $span->name === 'queue.process ObsFailingJob');
    $jobs = collect(app(MetricsRegistry::class)->collect()[MetricCatalog::QUEUE_JOBS]['series'])->first(fn (array $series): bool => $series['labels']['job'] === 'ObsFailingJob');

    expect($consumer->status)->toBe(Span::STATUS_ERROR)
        ->and($jobs['labels']['status'])->toBe('failed')
        ->and($jobs['value'])->toBe(1.0);
});

it('adds the payload fields that carry the trace context', function (): void {
    $root = app(Tracer::class)->startSpan('root');

    $payload = null;
    Queue::before(function (JobProcessing $event) use (&$payload): void {
        $payload = $event->job->payload();
    });

    dispatch(new ObsQuietJob);

    expect($payload['traceparent'])->toBe($root->context()->toTraceparent())
        ->and($payload['obs_enqueued_at'])->toBeNumeric();
});

it('sends the traceparent to the other service and records a client span', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    $tracer = app(Tracer::class);
    $root = $tracer->startSpan('root');

    Http::get('https://gateway.test/send?token=secret');

    $tracer->endSpan($root);
    $tracer->flush();

    Http::assertSent(fn (HttpRequest $request): bool => str_starts_with($request->header('traceparent')[0] ?? '', '00-'.$root->traceId.'-'));

    $client = collect($this->exporter->spans)->first(fn (Span $span): bool => $span->name === 'HTTP GET');

    expect($client->parentSpanId)->toBe($root->spanId)
        ->and($client->attributes()['http.response.status_code'])->toBe(200)
        ->and($client->attributes()['url.full'])->toBe('https://gateway.test/send')
        ->and($client->attributes()['server.address'])->toBe('gateway.test');
});

it('does not trace the calls marked observability_skip', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    app(Tracer::class)->startSpan('root');

    Http::withOptions(['observability_skip' => true])->get('https://collector.test/v1/traces');

    Http::assertSent(fn (HttpRequest $request): bool => ! $request->hasHeader('traceparent'));
});

it('limits the propagation to the configured hosts', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    config(['observability.tracing.propagate_hosts' => ['gateway.test']]);
    app(Tracer::class)->startSpan('root');

    Http::get('https://other.test/x');
    Http::get('https://gateway.test/x');

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://gateway.test/x' && $request->hasHeader('traceparent'));
    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://other.test/x' && ! $request->hasHeader('traceparent'));
});

it('exposes the business metrics through the facade without ever throwing', function (): void {
    DomainMetrics::fiscalTransmission('SDI', 'success');
    DomainMetrics::fiscalTransmission('PEPPOL', 'failure');
    DomainMetrics::time(MetricCatalog::QR_BILL_DURATION, fn (): int => 1);
    DomainMetrics::time(MetricCatalog::MRP_DURATION, fn (): int => 1);

    try {
        DomainMetrics::transmission('RT', fn () => throw new RuntimeException('down'));
    } catch (RuntimeException) {
        // rethrown after counting
    }

    $metrics = app(MetricsRegistry::class)->collect();
    $channels = collect($metrics[MetricCatalog::FISCAL_TRANSMISSION]['series'])->mapWithKeys(fn (array $series): array => [$series['labels']['channel'].'.'.$series['labels']['status'] => $series['value']]);

    expect($channels->all())->toBe(['SDI.success' => 1.0, 'PEPPOL.failure' => 1.0, 'RT.failure' => 1.0])
        ->and($metrics[MetricCatalog::QR_BILL_DURATION]['series'][0]['count'])->toBe(1)
        ->and($metrics[MetricCatalog::MRP_DURATION]['series'][0]['count'])->toBe(1);
});

class ObsFailingJob implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 1;

    public function handle(): void
    {
        throw new RuntimeException('boom');
    }
}

class ObsQuietJob implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(): void {}
}
