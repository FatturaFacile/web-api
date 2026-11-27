<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/ObservabilitySupport.php';

it('is reserved to platform administrators', function (): void {
    $this->getJson(OBS_ADMIN_API.'/profiler/slow-queries')->assertUnauthorized();

    obsMember($this);

    foreach (['/profiler/slow-queries', '/profiler/slow-endpoints', '/profiler/statements', '/profiler/noisy-neighbors', '/profiler/settings', '/connectors'] as $path) {
        $this->getJson(OBS_ADMIN_API.$path)->assertForbidden();
    }

    $this->putJson(OBS_ADMIN_API.'/profiler/settings', ['retention_days' => 3])->assertForbidden();
    $this->postJson(OBS_ADMIN_API.'/connectors/probe')->assertForbidden();
    $this->putJson(OBS_ADMIN_API.'/connectors/vies/maintenance', ['maintenance' => true])->assertForbidden();
});

it('ranks the slowest statements by fingerprint with plan, N+1 flag and suggested indexes', function (): void {
    obsPlatformAdmin();
    $tenant = (string) Str::uuid();
    $index = ['table' => 'invoices', 'columns' => ['tenant_id', 'status'], 'sql' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_invoices_tenant_id_status ON invoices (tenant_id, status);', 'reason' => 'seq_scan_filter'];

    obsSlowQuery(['fingerprint' => str_repeat('a', 40), 'duration_ms' => 100]);
    obsSlowQuery(['fingerprint' => str_repeat('a', 40), 'duration_ms' => 400, 'tenant_id' => $tenant, 'plan_status' => 'available', 'plan' => json_encode(['Plan' => ['Node Type' => 'Seq Scan']]), 'suggested_indexes' => json_encode([$index])]);
    obsSlowQuery(['fingerprint' => str_repeat('b', 40), 'kind' => 'n_plus_one', 'duration_ms' => 50, 'execution_count' => 25, 'sql_text' => 'select * from invoice_lines where invoice_id = ?']);
    obsSlowQuery(['fingerprint' => str_repeat('c', 40), 'duration_ms' => 999, 'captured_at' => now()->subDays(3)]);

    $rows = $this->getJson(OBS_ADMIN_API.'/profiler/slow-queries?hours=24&limit=20')->assertOk()->assertJsonPath('success', true)->json('data');

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['fingerprint'])->toBe(str_repeat('a', 40))
        ->and($rows[0]['executions'])->toBe(2)
        ->and($rows[0]['avg_ms'])->toBe(250.0)
        ->and($rows[0]['max_ms'])->toBe(400.0)
        ->and($rows[0]['tenant_id'])->toBe($tenant)
        ->and($rows[0]['plan_status'])->toBe('available')
        ->and($rows[0]['plan']['Plan']['Node Type'])->toBe('Seq Scan')
        ->and($rows[0]['suggested_indexes'][0]['sql'])->toStartWith('CREATE INDEX CONCURRENTLY')
        ->and($rows[0]['n_plus_one'])->toBeFalse()
        ->and($rows[0]['trace_id'])->toBe(str_repeat('b', 32))
        ->and($rows[1]['kind'])->toBe('n_plus_one')
        ->and($rows[1]['n_plus_one'])->toBeTrue()
        ->and($rows[1]['n_plus_one_count'])->toBe(25)
        ->and($rows[1]['executions'])->toBe(25)
        ->and($rows[1]['suggested_indexes'])->toBe([]);
});

it('flags a statement that also ran as an N+1 pattern', function (): void {
    obsPlatformAdmin();
    obsSlowQuery(['fingerprint' => str_repeat('d', 40), 'duration_ms' => 300]);
    obsSlowQuery(['fingerprint' => str_repeat('d', 40), 'kind' => 'n_plus_one', 'duration_ms' => 10, 'execution_count' => 14]);

    $rows = $this->getJson(OBS_ADMIN_API.'/profiler/slow-queries')->assertOk()->json('data');
    $slow = collect($rows)->firstWhere('kind', 'slow');

    expect($slow['n_plus_one'])->toBeTrue()->and($slow['n_plus_one_count'])->toBe(14);
});

it('honours the limit and validates the window', function (): void {
    obsPlatformAdmin();

    foreach (range(1, 4) as $i) {
        obsSlowQuery(['fingerprint' => str_repeat((string) $i, 40), 'duration_ms' => $i * 100]);
    }

    $this->getJson(OBS_ADMIN_API.'/profiler/slow-queries?limit=3')->assertOk()->assertJsonCount(3, 'data');
    $this->getJson(OBS_ADMIN_API.'/profiler/slow-queries?hours=0')->assertUnprocessable()->assertJsonValidationErrors('hours');
    $this->getJson(OBS_ADMIN_API.'/profiler/slow-endpoints?limit=500')->assertUnprocessable()->assertJsonValidationErrors('limit');
});

it('ranks the slowest endpoints by p95 with their percentiles, queries and trace', function (): void {
    obsPlatformAdmin();

    foreach (range(1, 10) as $i) {
        obsEndpointSample(['route' => '/api/v1/invoices', 'duration_ms' => $i * 100, 'db_query_count' => $i, 'db_time_ms' => $i * 10, 'status_code' => $i === 10 ? 500 : 200, 'trace_id' => $i === 10 ? str_repeat('e', 32) : null]);
        obsEndpointSample(['route' => '/api/v1/ping', 'duration_ms' => 10, 'db_query_count' => 0, 'db_time_ms' => 0]);
    }

    obsEndpointSample(['route' => '/api/v1/old', 'duration_ms' => 9000, 'captured_at' => now()->subDays(2)]);

    $rows = $this->getJson(OBS_ADMIN_API.'/profiler/slow-endpoints')->assertOk()->json('data');

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['route'])->toBe('/api/v1/invoices')
        ->and($rows[0]['method'])->toBe('GET')
        ->and($rows[0]['requests'])->toBe(10)
        ->and($rows[0]['avg_ms'])->toBe(550.0)
        ->and($rows[0]['p50_ms'])->toBe(500.0)
        ->and($rows[0]['p95_ms'])->toBe(1000.0)
        ->and($rows[0]['p99_ms'])->toBe(1000.0)
        ->and($rows[0]['avg_db_queries'])->toBe(5.5)
        ->and($rows[0]['avg_db_ms'])->toBe(55.0)
        ->and($rows[0]['error_rate'])->toBe(10.0)
        ->and($rows[0]['last_trace_id'])->toBe(str_repeat('e', 32))
        ->and($rows[1]['route'])->toBe('/api/v1/ping')
        ->and($rows[1]['error_rate'])->toBe(0.0);
});

it('flags the tenant that consumes most of the database time', function (): void {
    obsPlatformAdmin();
    $noisy = (string) Str::uuid();
    $quiet = (string) Str::uuid();

    foreach (range(1, 60) as $i) {
        obsEndpointSample(['tenant_id' => $noisy, 'db_time_ms' => 15]);
        obsEndpointSample(['tenant_id' => $quiet, 'db_time_ms' => 1.67]);
    }

    obsEndpointSample(['tenant_id' => null, 'db_time_ms' => 5000]);

    $rows = $this->getJson(OBS_ADMIN_API.'/profiler/noisy-neighbors?hours=1')->assertOk()->json('data');

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['tenant_id'])->toBe($noisy)
        ->and($rows[0]['requests'])->toBe(60)
        ->and($rows[0]['db_ms'])->toBe(900.0)
        ->and($rows[0]['share_pct'])->toBeGreaterThan(89.0)
        ->and($rows[0]['flagged'])->toBeTrue()
        ->and($rows[1]['tenant_id'])->toBe($quiet)
        ->and($rows[1]['flagged'])->toBeFalse();
});

it('does not flag a tenant on a quiet platform', function (): void {
    obsPlatformAdmin();
    obsEndpointSample(['tenant_id' => (string) Str::uuid(), 'db_time_ms' => 500]);

    expect($this->getJson(OBS_ADMIN_API.'/profiler/noisy-neighbors')->assertOk()->json('data.0.flagged'))->toBeFalse();
});

it('reads and changes the thresholds', function (): void {
    obsPlatformAdmin();

    $this->getJson(OBS_ADMIN_API.'/profiler/settings')->assertOk()
        ->assertJsonPath('data.slow_query_threshold_ms', 250)
        ->assertJsonPath('data.n_plus_one_threshold', 10)
        ->assertJsonPath('data.explain_enabled', true)
        ->assertJsonPath('data.retention_days', 14);

    $this->putJson(OBS_ADMIN_API.'/profiler/settings', ['slow_query_threshold_ms' => 500, 'explain_enabled' => false, 'alert_tenant_share_pct' => 55.5])
        ->assertOk()
        ->assertJsonPath('data.slow_query_threshold_ms', 500)
        ->assertJsonPath('data.explain_enabled', false)
        ->assertJsonPath('data.alert_tenant_share_pct', 55.5)
        ->assertJsonPath('data.n_plus_one_threshold', 10);

    $this->putJson(OBS_ADMIN_API.'/profiler/settings', ['retention_days' => 30])->assertOk()
        ->assertJsonPath('data.retention_days', 30)
        ->assertJsonPath('data.slow_query_threshold_ms', 500);

    expect(DB::table('observability_settings')->where('key', 'profiler')->count())->toBe(1);
    $this->getJson(OBS_ADMIN_API.'/profiler/settings')->assertJsonPath('data.slow_query_threshold_ms', 500);
});

it('rejects invalid thresholds', function (): void {
    obsPlatformAdmin();

    $this->putJson(OBS_ADMIN_API.'/profiler/settings', ['slow_query_threshold_ms' => 0, 'n_plus_one_threshold' => 1, 'alert_tenant_share_pct' => 101, 'retention_days' => 'x', 'explain_enabled' => 'maybe'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['slow_query_threshold_ms', 'n_plus_one_threshold', 'alert_tenant_share_pct', 'retention_days', 'explain_enabled']);
});

it('explains why pg_stat_statements is not available instead of failing', function (): void {
    obsPlatformAdmin();

    $this->getJson(OBS_ADMIN_API.'/profiler/statements')->assertOk()
        ->assertJsonPath('data.available', false)
        ->assertJsonPath('data.reason', 'not_postgres')
        ->assertJsonPath('data.statements', [])
        ->assertJsonPath('data.hint', __('observability.hints.not_postgres'));
});

it('answers the hint in the language of the request', function (): void {
    obsPlatformAdmin();

    $this->withHeader('Accept-Language', 'it')->getJson(OBS_ADMIN_API.'/profiler/statements')->assertOk();

    foreach (['it', 'de', 'fr', 'en'] as $locale) {
        foreach (['not_postgres', 'extension_missing', 'permission_denied'] as $reason) {
            expect(__('observability.hints.'.$reason, [], $locale))->not->toBe('observability.hints.'.$reason);
        }
    }
});
