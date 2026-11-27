<?php

declare(strict_types=1);

use Modules\Observability\Application\Queries\TopSlowEndpointsHandler;
use Modules\Observability\Domain\Enums\PlanStatus;
use Modules\Observability\Privacy\SqlSanitizer;
use Modules\Observability\Profiler\ExplainRunner;
use Modules\Observability\Profiler\RequestProfile;

it('explains only a single read-only select', function (string $sql, bool $explainable): void {
    expect((new ExplainRunner(new SqlSanitizer))->isExplainable($sql))->toBe($explainable);
})->with([
    'select' => ['select * from invoices where id = ?', true],
    'trailing semicolon' => ['select 1;', true],
    'cte' => ['with x as (select 1 as a) select * from x', true],
    'a literal that spells a keyword' => ["select * from t where kind = 'update'", true],
    'delete' => ['delete from invoices', false],
    'update' => ['update invoices set status = ?', false],
    'insert' => ['insert into t values (1)', false],
    'two statements' => ['select 1; drop table invoices', false],
    'locking read' => ['select * from invoices for update', false],
    'sequence side effect' => ["select nextval('invoice_seq')", false],
    'select into' => ['select * into new_t from t', false],
    'data modifying cte' => ['with x as (delete from t returning *) select * from x', false],
    'advisory lock' => ['select pg_advisory_lock(1)', false],
    'empty' => ['', false],
]);

it('reports the plan as unsupported outside PostgreSQL', function (): void {
    $result = (new ExplainRunner(new SqlSanitizer))->explain(config('database.default'), 'select 1', [], false, 1000);

    expect($result['status'])->toBe(PlanStatus::Unsupported)->and($result['plan'])->toBeNull();
});

it('detects the N+1 signature of repeated select fingerprints only', function (): void {
    $profile = new RequestProfile;

    for ($i = 0; $i < 12; $i++) {
        $profile->recordQuery('fp-select', 'select * from lines where order_id = ?', 2.0, true);
        $profile->recordQuery('fp-insert', 'insert into logs values (?)', 1.0, false);
    }

    $profile->recordQuery('fp-once', 'select 1', 1.0, true);

    expect($profile->queryCount)->toBe(25)
        ->and($profile->dbMs)->toBe(37.0)
        ->and(array_keys($profile->repeated(10)))->toBe(['fp-select'])
        ->and($profile->repeated(10)['fp-select']['count'])->toBe(12)
        ->and($profile->repeated(10)['fp-select']['total_ms'])->toBe(24.0)
        ->and($profile->repeated(13))->toBe([]);

    $profile->clearQueries();

    expect($profile->queryCount)->toBe(0)->and($profile->fingerprints())->toBe([]);
});

it('caps the slow queries kept per request', function (): void {
    $profile = new RequestProfile;

    for ($i = 0; $i < 5; $i++) {
        $profile->addSlow('fp'.$i, 'select ?', 'select 1', [], 300.0, 'sqlite', 3);
    }

    expect($profile->slowQueries())->toHaveCount(3);
});

it('computes nearest-rank percentiles', function (): void {
    $values = [100.0, 200.0, 300.0, 400.0, 500.0, 600.0, 700.0, 800.0, 900.0, 1000.0];

    expect(TopSlowEndpointsHandler::percentile($values, 50))->toBe(500.0)
        ->and(TopSlowEndpointsHandler::percentile($values, 95))->toBe(1000.0)
        ->and(TopSlowEndpointsHandler::percentile($values, 99))->toBe(1000.0)
        ->and(TopSlowEndpointsHandler::percentile([42.0], 99))->toBe(42.0)
        ->and(TopSlowEndpointsHandler::percentile([], 50))->toBe(0.0)
        ->and(TopSlowEndpointsHandler::percentile([3.0, 1.0, 2.0], 50))->toBe(2.0);
});
