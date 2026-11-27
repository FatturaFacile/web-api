<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Observability\Connectors\ConnectorCatalog;
use Modules\Observability\Jobs\ProbeConnectorJob;
use Modules\Observability\Metrics\MetricCatalog;
use Modules\Observability\Metrics\MetricsRegistry;

require_once __DIR__.'/ObservabilitySupport.php';

const OBS_CONNECTORS = ['sdi', 'sts', 'swissdec_elm', 'zefix', 'uid_register', 'vies', 'snb', 'ecb', 'ebill'];

beforeEach(function (): void {
    obsFakeTls(90);
});

it('knows the CH/IT institutional services', function (): void {
    $catalog = app(ConnectorCatalog::class);

    expect($catalog->keys())->toBe(OBS_CONNECTORS)
        ->and($catalog->find('sdi'))->toMatchArray(['country' => 'IT', 'group' => 'fiscal', 'name_code' => 'OBSERVABILITY.CONNECTOR.SDI'])
        ->and($catalog->find('zefix')['country'])->toBe('CH')
        ->and($catalog->find('vies')['country'])->toBe('EU')
        ->and($catalog->find('nope'))->toBeNull()
        ->and($catalog->has('ebill'))->toBeTrue();
});

it('probes every connector on demand and reports them operational', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    obsPlatformAdmin();

    $rows = $this->postJson(OBS_ADMIN_API.'/connectors/probe')->assertOk()->json('data');

    expect(array_column($rows, 'key'))->toBe(OBS_CONNECTORS)
        ->and(array_unique(array_column($rows, 'status')))->toBe(['operational'])
        ->and($rows[0]['uptime_24h'])->toBe(100.0)
        ->and($rows[0]['tls_days_remaining'])->toBe(90)
        ->and($rows[0]['last_checked_at'])->not->toBeNull()
        ->and($rows[0]['last_latency_ms'])->toBeInt()
        ->and($rows[0]['last_error'])->toBeNull()
        ->and($rows[0]['history'])->toHaveCount(24)
        ->and(end($rows[0]['history'])['status'])->toBe('operational')
        ->and(DB::table('observability_connector_checks')->count())->toBe(9)
        ->and(DB::table('observability_connector_statuses')->count())->toBe(9);
});

it('sends no credential and no tenant data to the institutions', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    obsPlatformAdmin();

    $this->postJson(OBS_ADMIN_API.'/connectors/probe', ['key' => 'vies'])->assertOk();

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => $request->url() === config('observability.connectors.catalog.vies.url')
        && ! $request->hasHeader('Authorization')
        && ! $request->hasHeader('traceparent')
        && ! $request->hasHeader('X-Tenant-Id')
        && $request->header('User-Agent')[0] === 'FatturaFacile-StatusProbe/1.0');
});

it('degrades after the first failure, falls into outage after three and recovers into degraded while the uptime is low', function (): void {
    obsPlatformAdmin();
    Http::fake([config('observability.connectors.catalog.zefix.url') => Http::response('down', 503), '*' => Http::response('ok', 200)]);

    $status = fn () => $this->postJson(OBS_ADMIN_API.'/connectors/probe', ['key' => 'zefix'])->assertOk()->json('data.0');

    expect($status()['status'])->toBe('degraded');

    $status();
    $third = $status();

    expect($third['status'])->toBe('outage')
        ->and($third['last_error'])->toBe('HTTP 503')
        ->and($third['uptime_24h'])->toBe(0.0);

    Http::fake(['*' => Http::response('ok', 200)]);
    $recovered = $status();

    expect($recovered['status'])->toBe('degraded')
        ->and($recovered['uptime_24h'])->toBe(25.0)
        ->and($recovered['uptime_7d'])->toBe(25.0)
        ->and($recovered['last_error'])->toBeNull();
});

it('treats an unexpected status code as a failure and an expected redirect as success', function (): void {
    obsPlatformAdmin();
    Http::fake([
        config('observability.connectors.catalog.snb.url') => Http::response('', 302),
        config('observability.connectors.catalog.ecb.url') => Http::response('', 404),
        '*' => Http::response('ok', 200),
    ]);

    $rows = collect($this->postJson(OBS_ADMIN_API.'/connectors/probe')->assertOk()->json('data'))->keyBy('key');

    expect($rows['snb']['status'])->toBe('operational')
        ->and($rows['ecb']['status'])->toBe('degraded')
        ->and($rows['ecb']['last_error'])->toBe('HTTP 404');
});

it('records an unreachable service with a scrubbed error and a TLS failure', function (): void {
    obsPlatformAdmin();
    Http::fake(['*' => fn () => throw new ConnectionException('cURL error 60: SSL certificate problem: certificate has expired for ops@example.com')]);

    $row = $this->postJson(OBS_ADMIN_API.'/connectors/probe', ['key' => 'sts'])->assertOk()->json('data.0');
    $check = DB::table('observability_connector_checks')->where('connector_key', 'sts')->first();

    expect($row['status'])->toBe('degraded')
        ->and($row['last_error'])->toContain('SSL certificate problem')
        ->and($row['last_error'])->not->toContain('ops@example.com')
        ->and((bool) $check->ok)->toBeFalse()
        ->and($check->http_status)->toBeNull()
        ->and((bool) $check->tls_valid)->toBeFalse();
});

it('warns when the certificate is about to expire', function (): void {
    obsPlatformAdmin();
    obsFakeTls(3);
    Http::fake(['*' => Http::response('ok', 200)]);

    $row = $this->postJson(OBS_ADMIN_API.'/connectors/probe', ['key' => 'sdi'])->assertOk()->json('data.0');

    expect($row['status'])->toBe('degraded')->and($row['tls_days_remaining'])->toBe(3);
});

it('puts a connector in maintenance and tells every tenant', function (): void {
    obsPlatformAdmin();

    $this->putJson(OBS_ADMIN_API.'/connectors/ecb/maintenance', ['maintenance' => true, 'message' => 'Planned work'])
        ->assertOk()
        ->assertJsonPath('data.status', 'maintenance')
        ->assertJsonPath('data.maintenance_message', 'Planned work');

    obsMember($this);
    $summary = $this->getJson('/api/v1/system/connector-status')->assertOk()->json('data');
    $ecb = collect($summary['connectors'])->firstWhere('key', 'ecb');

    expect($summary['overall'])->toBe('maintenance')
        ->and($ecb['status'])->toBe('maintenance')
        ->and($ecb['maintenance_message'])->toBe('Planned work');

    obsPlatformAdmin();
    $this->putJson(OBS_ADMIN_API.'/connectors/ecb/maintenance', ['maintenance' => false])->assertOk()
        ->assertJsonPath('data.status', 'operational')
        ->assertJsonPath('data.maintenance_message', null);
});

it('keeps maintenance over a successful probe', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    obsPlatformAdmin();
    $this->putJson(OBS_ADMIN_API.'/connectors/vies/maintenance', ['maintenance' => true])->assertOk();

    $this->postJson(OBS_ADMIN_API.'/connectors/probe', ['key' => 'vies'])->assertOk()->assertJsonPath('data.0.status', 'maintenance');
});

it('validates the maintenance request and the connector keys', function (): void {
    obsPlatformAdmin();

    $this->putJson(OBS_ADMIN_API.'/connectors/ecb/maintenance', [])->assertUnprocessable()->assertJsonValidationErrors('maintenance');
    $this->putJson(OBS_ADMIN_API.'/connectors/ecb/maintenance', ['maintenance' => true, 'message' => str_repeat('x', 300)])->assertUnprocessable()->assertJsonValidationErrors('message');
    $this->putJson(OBS_ADMIN_API.'/connectors/unknown/maintenance', ['maintenance' => true])->assertNotFound();
    $this->getJson(OBS_ADMIN_API.'/connectors/unknown/history')->assertNotFound();
    $this->postJson(OBS_ADMIN_API.'/connectors/probe', ['key' => 'unknown'])->assertUnprocessable()->assertJsonValidationErrors('key');
});

it('serves the latency history of a connector to the administrator', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);
    obsPlatformAdmin();
    $this->postJson(OBS_ADMIN_API.'/connectors/probe', ['key' => 'snb'])->assertOk();
    $this->postJson(OBS_ADMIN_API.'/connectors/probe', ['key' => 'snb'])->assertOk();
    DB::table('observability_connector_checks')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => null, 'connector_key' => 'snb', 'ok' => false, 'http_status' => 500, 'latency_ms' => 80, 'error' => 'HTTP 500', 'checked_at' => now()->subHours(30)]);

    $data = $this->getJson(OBS_ADMIN_API.'/connectors/snb/history?hours=24')->assertOk()->json('data');

    expect($data['connector']['key'])->toBe('snb')
        ->and($data['checks'])->toHaveCount(2)
        ->and($data['checks'][0])->toHaveKeys(['checked_at', 'status', 'latency_ms', 'http_status', 'error'])
        ->and($data['checks'][0]['status'])->toBe('operational')
        ->and($data['checks'][0]['http_status'])->toBe(200)
        ->and($this->getJson(OBS_ADMIN_API.'/connectors/snb/history?hours=48')->json('data.checks'))->toHaveCount(3);
});

it('shows the tenants the status without the technical detail', function (): void {
    Http::fake([config('observability.connectors.catalog.vies.url') => Http::response('down', 500), '*' => Http::response('ok', 200)]);
    obsPlatformAdmin();
    $this->postJson(OBS_ADMIN_API.'/connectors/probe')->assertOk();

    obsMember($this);
    $summary = $this->getJson('/api/v1/system/connector-status')->assertOk()->json('data');
    $vies = collect($summary['connectors'])->firstWhere('key', 'vies');

    expect($summary['overall'])->toBe('degraded')
        ->and($summary['checked_at'])->not->toBeNull()
        ->and($summary['connectors'])->toHaveCount(9)
        ->and($vies['status'])->toBe('degraded')
        ->and($vies['name_code'])->toBe('OBSERVABILITY.CONNECTOR.VIES')
        ->and($vies['last_error'])->toBeNull()
        ->and($vies['tls_days_remaining'])->toBeNull()
        ->and($vies['history'])->toHaveCount(24)
        ->and(end($vies['history'])['status'])->toBe('outage');
});

it('reports operational with no probe history yet', function (): void {
    obsMember($this);

    $summary = $this->getJson('/api/v1/system/connector-status')->assertOk()->json('data');

    expect($summary['overall'])->toBe('operational')
        ->and($summary['checked_at'])->toBeNull()
        ->and($summary['connectors'][0]['last_checked_at'])->toBeNull()
        ->and(array_unique(array_column($summary['connectors'][0]['history'], 'status')))->toBe(['unknown']);
});

it('requires an authenticated member for the tenant-facing summary', function (): void {
    $this->getJson('/api/v1/system/connector-status')->assertUnauthorized();
});

it('publishes the probe results as Prometheus gauges', function (): void {
    Http::fake([config('observability.connectors.catalog.zefix.url') => Http::response('down', 500), '*' => Http::response('ok', 200)]);
    obsPlatformAdmin();
    $this->postJson(OBS_ADMIN_API.'/connectors/probe')->assertOk();

    $metrics = app(MetricsRegistry::class)->collect();
    $up = collect($metrics[MetricCatalog::CONNECTOR_UP]['series'])->mapWithKeys(fn (array $series): array => [$series['labels']['connector'] => $series['value']]);
    $status = collect($metrics[MetricCatalog::CONNECTOR_STATUS]['series'])->mapWithKeys(fn (array $series): array => [$series['labels']['connector'] => $series['value']]);

    expect($up['zefix'])->toBe(0.0)->and($up['sdi'])->toBe(1.0)
        ->and($status['zefix'])->toBe(1.0)->and($status['sdi'])->toBe(2.0)
        ->and($metrics)->toHaveKey(MetricCatalog::CONNECTOR_LATENCY);
});

it('queues one probe job per connector from the scheduler command', function (): void {
    Queue::fake();

    $this->artisan('observability:probe-connectors')->assertSuccessful();

    Queue::assertPushed(ProbeConnectorJob::class, 9);

    $this->artisan('observability:probe-connectors', ['--key' => ['vies']])->assertSuccessful();

    Queue::assertPushed(ProbeConnectorJob::class, 10);
});

it('runs the probes inline with --sync', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    $this->artisan('observability:probe-connectors', ['--sync' => true, '--key' => ['vies', 'ecb']])->assertSuccessful();

    expect(DB::table('observability_connector_statuses')->pluck('connector_key')->sort()->values()->all())->toBe(['ecb', 'vies']);
});

it('deletes the diagnostics older than the retention and keeps the rest', function (): void {
    obsSlowQuery(['captured_at' => now()->subDays(20)]);
    obsSlowQuery(['captured_at' => now()->subDay()]);
    obsEndpointSample(['captured_at' => now()->subDays(20)]);
    obsEndpointSample(['captured_at' => now()->subDay()]);
    DB::table('observability_connector_checks')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => null, 'connector_key' => 'vies', 'ok' => true, 'checked_at' => now()->subDays(20)]);
    DB::table('observability_connector_checks')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => null, 'connector_key' => 'vies', 'ok' => true, 'checked_at' => now()->subDay()]);

    $this->artisan('observability:prune')->assertSuccessful();

    expect(DB::table('observability_slow_queries')->count())->toBe(1)
        ->and(DB::table('observability_endpoint_samples')->count())->toBe(1)
        ->and(DB::table('observability_connector_checks')->count())->toBe(1);

    obsSlowQuery(['captured_at' => now()->subDays(3)]);

    $this->artisan('observability:prune', ['--days' => 2])->assertSuccessful();

    expect(DB::table('observability_slow_queries')->count())->toBe(1);
});
