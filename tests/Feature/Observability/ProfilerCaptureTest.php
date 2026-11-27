<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Modules\Observability\Profiler\ProfilerSettings;
use Modules\Observability\Profiler\QueryProfiler;
use Modules\Observability\Profiler\RequestProfile;

require_once __DIR__.'/ObservabilitySupport.php';

beforeEach(function (): void {
    config(['observability.profiler.capture_enabled' => true, 'observability.enabled' => true]);
    $this->profile = app(RequestProfile::class);
    $this->profile->clearQueries();
    $this->profile->traceId = str_repeat('f', 32);
    $this->profile->originType = 'route';
    $this->profile->origin = 'GET /api/v1/invoices';
    $this->profiler = app(QueryProfiler::class);
});

function obsQuery(string $sql, float $ms, array $bindings = []): QueryExecuted
{
    return new QueryExecuted($sql, $bindings, $ms, DB::connection());
}

it('captures a slow query sanitized, with its origin and trace, and ignores the fast ones', function (): void {
    $this->profiler->handle(obsQuery("select * from invoices where email = 'mario@x.ch' and id = ?", 300.0, [7]));
    $this->profiler->handle(obsQuery('select 1', 5.0));

    $this->profiler->flush();

    $rows = DB::table('observability_slow_queries')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->kind)->toBe('slow')
        ->and($rows[0]->sql_text)->toBe('select * from invoices where email = ? and id = ?')
        ->and((float) $rows[0]->duration_ms)->toBe(300.0)
        ->and($rows[0]->trace_id)->toBe(str_repeat('f', 32))
        ->and($rows[0]->origin_type)->toBe('route')
        ->and($rows[0]->origin)->toBe('GET /api/v1/invoices')
        ->and($rows[0]->plan_status)->toBe('unsupported')
        ->and($rows[0]->plan)->toBeNull()
        ->and($rows[0]->fingerprint)->toHaveLength(40);
});

it('never stores the bindings or the literals of a statement', function (): void {
    $this->profiler->handle(obsQuery("select * from contacts where iban = 'CH9300762011623852957' and name = ?", 400.0, ['Mario Rossi']));
    $this->profiler->flush();

    $stored = json_encode(DB::table('observability_slow_queries')->get()->all());

    expect($stored)->not->toContain('CH9300762011623852957')->and($stored)->not->toContain('Mario Rossi');
});

it('flags the N+1 pattern with the number of repetitions', function (): void {
    foreach (range(1, 12) as $orderId) {
        $this->profiler->handle(obsQuery("select * from invoice_lines where invoice_id = {$orderId}", 1.0));
    }

    $this->profiler->flush();

    $row = DB::table('observability_slow_queries')->where('kind', 'n_plus_one')->first();

    expect($row)->not->toBeNull()
        ->and($row->sql_text)->toBe('select * from invoice_lines where invoice_id = ?')
        ->and((int) $row->execution_count)->toBe(12)
        ->and((float) $row->duration_ms)->toBe(12.0)
        ->and(DB::table('observability_slow_queries')->where('kind', 'slow')->count())->toBe(0);
});

it('does not flag a write that repeats or a select below the threshold', function (): void {
    foreach (range(1, 12) as $i) {
        $this->profiler->handle(obsQuery("insert into logs (a) values ({$i})", 1.0));
    }

    foreach (range(1, 5) as $i) {
        $this->profiler->handle(obsQuery("select * from t where id = {$i}", 1.0));
    }

    $this->profiler->flush();

    expect(DB::table('observability_slow_queries')->count())->toBe(0);
});

it('uses the thresholds an administrator saved', function (): void {
    app(ProfilerSettings::class)->update(['slow_query_threshold_ms' => 1000, 'n_plus_one_threshold' => 3, 'explain_enabled' => false]);

    $this->profiler->handle(obsQuery('select * from slow_one', 300.0));
    $this->profiler->handle(obsQuery('select * from slow_two', 1500.0));

    foreach (range(1, 3) as $i) {
        $this->profiler->handle(obsQuery("select * from child where parent_id = {$i}", 1.0));
    }

    $this->profiler->flush();

    $rows = DB::table('observability_slow_queries')->get()->keyBy('sql_text');

    expect($rows)->toHaveCount(2)
        ->and($rows['select * from slow_two']->plan_status)->toBe('skipped')
        ->and((int) $rows['select * from child where parent_id = ?']->execution_count)->toBe(3);
});

it('writes nothing when the capture is switched off, and starts clean afterwards', function (): void {
    config(['observability.profiler.capture_enabled' => false]);
    $this->profiler->handle(obsQuery('select * from slow_one', 900.0));
    $this->profiler->flush();

    expect(DB::table('observability_slow_queries')->count())->toBe(0)
        ->and($this->profile->queryCount)->toBe(0)
        ->and($this->profile->slowQueries())->toBe([]);
});

it('keeps the queries of its own tables out of the profile', function (): void {
    $this->profiler->handle(obsQuery('select * from observability_slow_queries', 900.0));

    expect($this->profile->queryCount)->toBe(0);
});

it('samples every request and attributes it to the trace and the tenant', function (): void {
    config(['observability.profiler.endpoint_sample_ratio' => 1.0]);
    obsMember($this);

    $response = $this->getJson('/api/v1/system/connector-status')->assertOk();
    $sample = DB::table('observability_endpoint_samples')->where('route', '/api/v1/system/connector-status')->first();

    expect($sample)->not->toBeNull()
        ->and($sample->method)->toBe('GET')
        ->and((int) $sample->status_code)->toBe(200)
        ->and($sample->tenant_id)->toBe($this->tenant->id)
        ->and($sample->trace_id)->toBe($response->headers->get('X-Trace-Id'))
        ->and((float) $sample->duration_ms)->toBeGreaterThan(0.0);
});

it('does not sample the requests when the ratio is zero and the request is fast', function (): void {
    config(['observability.profiler.endpoint_sample_ratio' => 0.0]);

    $this->getJson('/api/v1/system/connector-status');

    expect(DB::table('observability_endpoint_samples')->count())->toBe(0);
});

it('stays out of the way when the capture is off in the test environment default', function (): void {
    config(['observability.profiler.capture_enabled' => false, 'observability.profiler.endpoint_sample_ratio' => 1.0]);

    $this->getJson('/api/v1/system/connector-status');

    expect(DB::table('observability_endpoint_samples')->count())->toBe(0);
});
