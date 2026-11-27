<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Observability\Profiler\IndexAdvisor;

it('suggests a concurrent index for an expensive sequential scan, equality columns first', function (): void {
    $plan = ['Plan' => [
        'Node Type' => 'Seq Scan', 'Relation Name' => 'obs_orders', 'Alias' => 'obs_orders', 'Total Cost' => 5400.0, 'Plan Rows' => 200,
        'Filter' => '((due_date < ?) AND (tenant_id = ?) AND ((status)::text = ?::text))',
    ]];

    $suggestions = (new IndexAdvisor(1000))->advise($plan);

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0]['table'])->toBe('obs_orders')
        ->and($suggestions[0]['columns'])->toBe(['tenant_id', 'status', 'due_date'])
        ->and($suggestions[0]['reason'])->toBe('seq_scan_filter')
        ->and($suggestions[0]['sql'])->toBe('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_obs_orders_tenant_id_status_due_date ON obs_orders (tenant_id, status, due_date);');
});

it('does not suggest anything for a cheap scan or a scan without a filter', function (): void {
    $cheap = ['Plan' => ['Node Type' => 'Seq Scan', 'Relation Name' => 'obs_orders', 'Total Cost' => 12.0, 'Filter' => '(status = ?)']];
    $unfiltered = ['Plan' => ['Node Type' => 'Seq Scan', 'Relation Name' => 'obs_orders', 'Total Cost' => 9000.0]];

    expect((new IndexAdvisor(1000))->advise($cheap))->toBe([])
        ->and((new IndexAdvisor(1000))->advise($unfiltered))->toBe([])
        ->and((new IndexAdvisor(1000))->advise([]))->toBe([]);
});

it('puts tenant_id first on a tenant-owned table', function (): void {
    Schema::create('obs_tmp_docs', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('tenant_id')->nullable();
        $table->string('status');
    });

    $plan = ['Plan' => ['Node Type' => 'Seq Scan', 'Relation Name' => 'obs_tmp_docs', 'Total Cost' => 4000.0, 'Filter' => '(status = ?)']];
    $suggestions = (new IndexAdvisor(1000))->advise($plan);

    expect($suggestions[0]['columns'])->toBe(['tenant_id', 'status']);

    Schema::drop('obs_tmp_docs');
});

it('proposes the join keys of scanned tables', function (): void {
    $plan = ['Plan' => [
        'Node Type' => 'Hash Join', 'Hash Cond' => '(l.order_id = o.id)',
        'Plans' => [
            ['Node Type' => 'Seq Scan', 'Relation Name' => 'obs_lines', 'Alias' => 'l', 'Total Cost' => 3000.0],
            ['Node Type' => 'Hash', 'Plans' => [['Node Type' => 'Seq Scan', 'Relation Name' => 'obs_orders', 'Alias' => 'o', 'Total Cost' => 2500.0]]],
        ],
    ]];

    $suggestions = (new IndexAdvisor(1000))->advise($plan);

    expect(array_column($suggestions, 'columns', 'table'))->toBe(['obs_lines' => ['order_id'], 'obs_orders' => ['id']])
        ->and(array_unique(array_column($suggestions, 'reason')))->toBe(['join_key']);
});

it('proposes a sort index combined with the filter of the feeding scan', function (): void {
    $plan = ['Plan' => [
        'Node Type' => 'Sort', 'Sort Key' => ['obs_orders.created_at DESC'],
        'Plans' => [['Node Type' => 'Seq Scan', 'Relation Name' => 'obs_orders', 'Alias' => 'obs_orders', 'Total Cost' => 4000.0, 'Filter' => '(status = ?)']],
    ]];

    $columns = array_column((new IndexAdvisor(1000))->advise(['Plan' => $plan['Plan']]), 'columns');

    expect($columns)->toContain(['status'])->and($columns)->toContain(['status', 'created_at']);
});

it('skips system tables and unsafe identifiers and de-duplicates', function (): void {
    $plan = ['Plan' => [
        'Node Type' => 'Append',
        'Plans' => [
            ['Node Type' => 'Seq Scan', 'Relation Name' => 'pg_class', 'Alias' => 'pg_class', 'Total Cost' => 9000.0, 'Filter' => '(relname = ?)'],
            ['Node Type' => 'Seq Scan', 'Relation Name' => 'Bad-Table', 'Alias' => 'b', 'Total Cost' => 9000.0, 'Filter' => '(x = ?)'],
            ['Node Type' => 'Seq Scan', 'Relation Name' => 'obs_a', 'Alias' => 'a1', 'Total Cost' => 9000.0, 'Filter' => '(code = ?)'],
            ['Node Type' => 'Seq Scan', 'Relation Name' => 'obs_a', 'Alias' => 'a2', 'Total Cost' => 9000.0, 'Filter' => '(code = ?)'],
        ],
    ]];

    expect((new IndexAdvisor(1000))->advise($plan))->toHaveCount(1);
});

it('shortens a very long index name to the PostgreSQL limit', function (): void {
    $plan = ['Plan' => ['Node Type' => 'Seq Scan', 'Relation Name' => 'obs_a_really_long_table_name_for_testing', 'Total Cost' => 9000.0, 'Filter' => '(first_long_column_name = ? AND second_long_column_name = ?)']];

    preg_match('/EXISTS (\w+) ON/', (new IndexAdvisor(1000))->advise($plan)[0]['sql'], $match);

    expect(strlen($match[1]))->toBeLessThanOrEqual(63);
});

it('extracts the compared columns of a filter', function (): void {
    $advisor = new IndexAdvisor;

    expect($advisor->filterColumns('((a.code)::text = ?::text)'))->toBe(['code'])
        ->and($advisor->filterColumns('(deleted_at IS NULL)'))->toBe(['deleted_at'])
        ->and($advisor->filterColumns('(amount >= ? AND kind = ?)'))->toBe(['kind', 'amount'])
        ->and($advisor->filterColumns(''))->toBe([]);
});
