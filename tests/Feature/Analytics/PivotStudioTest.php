<?php

declare(strict_types=1);

use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/BiTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2027-03-15 10:00:00');
    $this->tenant = biTenant('Alfa');
    $this->user = biActAs($this->tenant);
    $alpha = biCustomer($this->tenant, 'Alpha Srl');
    $beta = biCustomer($this->tenant, 'Beta Srl');
    biInvoice($this->tenant, $alpha, '2026-02-10', '100.00');
    biInvoice($this->tenant, $alpha, '2026-05-10', '50.00');
    biInvoice($this->tenant, $alpha, '2027-01-10', '200.00');
    biInvoice($this->tenant, $beta, '2026-03-01', '300.00');
    biInvoice($this->tenant, $beta, '2027-02-01', '20.00', ['invoice_type' => 'credit_note']);

    // Another company with big numbers: it must never show up here.
    $other = biTenant('Other');
    biInvoice($other, biCustomer($other, 'Alpha Srl'), '2026-02-10', '99999.00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function pivotRun(array $pivot, array $measures = ['net_amount', 'document_count', 'average_net_amount'], array $extra = [], array $spec = []): TestResponse
{
    return test()->postJson('/api/v1/analytics/designer/pivot', ['dataset' => 'sales', 'spec' => biSpec([], $measures, $spec), 'pivot' => $pivot, ...$extra]);
}

test('a pivot with rows and columns has detail cells, row totals, column totals and a grand total', function (): void {
    $data = pivotRun(['rows' => ['customer'], 'columns' => ['date:year']])->assertOk()->json('data');

    expect(array_column($data['column_headers'], 'label'))->toBe(['2026-01-01', '2027-01-01'])
        ->and(array_column($data['values'], 'key'))->toBe(['net_amount', 'document_count', 'average_net_amount'])
        ->and($data['has_total_column'])->toBeTrue();

    [$alpha, $beta, $grand] = $data['rows'];

    expect($alpha['label'])->toBe('Alpha Srl')
        ->and($alpha['type'])->toBe('data')
        ->and($alpha['cells'][0]['values'])->toBe(['net_amount' => '150.00', 'document_count' => 2, 'average_net_amount' => '75.00'])
        ->and($alpha['cells'][1]['values']['net_amount'])->toBe('200.00')
        ->and($alpha['total']['values'])->toBe(['net_amount' => '350.00', 'document_count' => 3, 'average_net_amount' => '116.66'])
        ->and($beta['cells'][0]['values']['net_amount'])->toBe('300.00')
        ->and($beta['cells'][1]['values']['net_amount'])->toBe('-20.00')
        ->and($beta['total']['values']['net_amount'])->toBe('280.00')
        ->and($grand['type'])->toBe('grand_total')
        ->and($grand['cells'][0]['values'])->toBe(['net_amount' => '450.00', 'document_count' => 3, 'average_net_amount' => '150.00'])
        ->and($grand['cells'][1]['values'])->toBe(['net_amount' => '180.00', 'document_count' => 2, 'average_net_amount' => '90.00'])
        // The average of the whole is not the average of the averages (that would be 128.33).
        ->and($grand['total']['values'])->toBe(['net_amount' => '630.00', 'document_count' => 5, 'average_net_amount' => '126.00']);
});

test('multi-level rows get a subtotal after every group of the outer level and a grand total', function (): void {
    $data = pivotRun(['rows' => ['customer', 'invoice_type'], 'columns' => [], 'subtotals' => true, 'grand_totals' => true], ['net_amount'])->assertOk()->json('data');

    expect(array_column($data['rows'], 'type'))->toBe(['data', 'subtotal', 'data', 'data', 'subtotal', 'grand_total'])
        ->and(array_column($data['rows'], 'keys'))->toBe([['Alpha Srl', 'standard'], ['Alpha Srl'], ['Beta Srl', 'credit_note'], ['Beta Srl', 'standard'], ['Beta Srl'], []])
        ->and(array_map(fn (array $row): ?string => $row['total']['values']['net_amount'], $data['rows']))->toBe(['350.00', '350.00', '-20.00', '300.00', '280.00', '630.00'])
        ->and($data['rows'][0]['cells'])->toBe([]);
});

test('subtotals and grand totals can be switched off', function (): void {
    $data = pivotRun(['rows' => ['customer', 'invoice_type'], 'columns' => [], 'subtotals' => false, 'grand_totals' => false], ['net_amount'])->assertOk()->json('data');

    expect(array_unique(array_column($data['rows'], 'type')))->toBe(['data'])->and($data['rows'])->toHaveCount(3);
});

test('a pivot with only columns is one row of totals per column value', function (): void {
    $data = pivotRun(['rows' => [], 'columns' => ['invoice_type']], ['net_amount'])->assertOk()->json('data');

    expect(array_column($data['column_headers'], 'label'))->toBe(['credit_note', 'standard'])
        ->and($data['rows'])->toHaveCount(1)
        ->and($data['rows'][0]['cells'][0]['values']['net_amount'])->toBe('-20.00')
        ->and($data['rows'][0]['cells'][1]['values']['net_amount'])->toBe('650.00')
        ->and($data['rows'][0]['total']['values']['net_amount'])->toBe('630.00');
});

test('calculated measures are evaluated on the aggregated cells and a division by zero is an empty cell', function (): void {
    $fields = [
        ['key' => 'per_document', 'label' => 'Per document', 'formula' => 'net_amount / NULLIF(document_count, 0)', 'format' => 'currency'],
        ['key' => 'broken', 'label' => 'Broken', 'formula' => 'net_amount / 0', 'format' => 'number'],
        ['key' => 'double', 'label' => 'Double', 'formula' => 'ROUND(per_document * 2, 2)', 'format' => 'currency'],
    ];
    $data = pivotRun(['rows' => ['customer'], 'columns' => []], ['net_amount', 'document_count'], ['calculated_fields' => $fields])->assertOk()->json('data');

    expect(array_column($data['values'], 'calculated'))->toBe([false, false, true, true, true])
        ->and($data['rows'][0]['total']['values']['per_document'])->toBe('116.666666')
        ->and($data['rows'][0]['total']['values']['broken'])->toBeNull()
        ->and($data['rows'][0]['total']['values']['double'])->toBe('233.33')
        // 630 over 5 documents: the ratio of the totals, not the sum of the ratios.
        ->and($data['rows'][2]['total']['values']['per_document'])->toBe('126.000000');
});

test('formulas and filters cannot reach the database as sql', function (): void {
    foreach (['net_amount; DROP TABLE invoices', 'net_amount + (SELECT 1)', "net_amount + 'x'", 'pg_sleep(5)', 'unknown_measure * 2'] as $formula) {
        pivotRun(['rows' => ['customer'], 'columns' => []], ['net_amount'], ['calculated_fields' => [['key' => 'x', 'formula' => $formula, 'format' => 'number']]])
            ->assertUnprocessable()->assertJsonValidationErrors('calculated_fields');
    }

    // A hostile value is only ever a bound parameter: it matches nothing and breaks nothing.
    $tree = ['logic' => 'or', 'conditions' => [['field' => 'customer', 'operator' => 'eq', 'value' => "x' OR 1=1 --"], ['field' => 'customer', 'operator' => 'contains', 'value' => "%'; DROP TABLE invoices; --"]]];
    $data = pivotRun(['rows' => ['customer'], 'columns' => []], ['net_amount'], spec: ['filter_tree' => $tree])->assertOk()->json('data');
    expect($data['rows'][0]['type'])->toBe('grand_total')->and($data['rows'][0]['total']['values']['net_amount'])->toBeNull();
    expect(Invoice::query()->count())->toBe(5);

    // Names are keys of the allow-list, never columns or SQL.
    pivotRun(['rows' => ['invoices.total_net; --'], 'columns' => []])->assertUnprocessable();
    pivotRun(['rows' => ['customer'], 'columns' => []], ['net_amount; DROP'])->assertUnprocessable();
});

test('nested and/or filter groups are applied and validated', function (): void {
    $tree = ['logic' => 'or', 'conditions' => [
        ['field' => 'customer', 'operator' => 'eq', 'value' => 'Alpha Srl'],
        ['logic' => 'and', 'conditions' => [
            ['field' => 'invoice_type', 'operator' => 'eq', 'value' => 'credit_note'],
            ['field' => 'date', 'operator' => 'gte', 'value' => '2027-01-01'],
        ]],
    ]];
    $data = pivotRun(['rows' => ['customer'], 'columns' => []], ['net_amount'], spec: ['filter_tree' => $tree])->assertOk()->json('data');

    expect(array_map(fn (array $row): ?string => $row['total']['values']['net_amount'], $data['rows']))->toBe(['350.00', '-20.00', '330.00']);

    $and = ['logic' => 'and', 'conditions' => [['field' => 'customer', 'operator' => 'in', 'value' => ['Alpha Srl', 'Beta Srl']], ['field' => 'date', 'operator' => 'between', 'value' => ['2026-01-01', '2026-12-31']], ['field' => 'customer', 'operator' => 'not_null']]];
    $data = pivotRun(['rows' => [], 'columns' => []], ['net_amount'], spec: ['filter_tree' => $and])->assertOk()->json('data');
    expect($data['rows'][0]['total']['values']['net_amount'])->toBe('450.00');

    foreach ([
        'unknown operator' => ['logic' => 'and', 'conditions' => [['field' => 'customer', 'operator' => 'regex', 'value' => 'x']]],
        'unknown field' => ['logic' => 'and', 'conditions' => [['field' => 'password', 'operator' => 'eq', 'value' => 'x']]],
        'measures belong in filters, not in groups' => ['logic' => 'and', 'conditions' => [['field' => 'net_amount', 'operator' => 'gt', 'value' => '1']]],
        'unknown logic' => ['logic' => 'xor', 'conditions' => []],
        'too deep' => ['logic' => 'and', 'conditions' => [['logic' => 'or', 'conditions' => [['logic' => 'and', 'conditions' => [['logic' => 'or', 'conditions' => [['field' => 'customer', 'operator' => 'eq', 'value' => 'x']]]]]]]]],
    ] as $name => $bad) {
        pivotRun(['rows' => ['customer'], 'columns' => []], ['net_amount'], spec: ['filter_tree' => $bad])->assertUnprocessable($name);
    }
});

test('conditional formatting marks the cells that match and refuses anything that is not a token', function (): void {
    $rules = [['target' => 'net_amount', 'operator' => 'gt', 'value' => '300', 'background' => 'red', 'text' => 'white'], ['target' => 'net_amount', 'operator' => 'between', 'value' => '0', 'value2' => '299', 'background' => 'green', 'text' => 'default']];
    $data = pivotRun(['rows' => ['customer'], 'columns' => []], ['net_amount'], ['conditional_formatting' => $rules])->assertOk()->json('data');

    expect($data['rows'][0]['total']['styles'])->toBe(['net_amount' => ['background' => 'red', 'text' => 'white']])
        ->and($data['rows'][1]['total']['styles'])->toBe(['net_amount' => ['background' => 'green', 'text' => 'default']])
        ->and($data['rows'][2]['total']['styles'])->toBe(['net_amount' => ['background' => 'red', 'text' => 'white']]);

    foreach ([
        ['target' => 'net_amount', 'operator' => 'gt', 'value' => '1', 'background' => 'url(javascript:alert(1))'],
        ['target' => 'secret', 'operator' => 'gt', 'value' => '1', 'background' => 'red'],
        ['target' => 'net_amount', 'operator' => 'like', 'value' => '1', 'background' => 'red'],
        ['target' => 'net_amount', 'operator' => 'gt', 'value' => 'abc', 'background' => 'red'],
    ] as $bad) {
        pivotRun(['rows' => ['customer'], 'columns' => []], ['net_amount'], ['conditional_formatting' => [$bad]])->assertUnprocessable()->assertJsonValidationErrors('conditional_formatting.0');
    }
});

test('the chart series follow the pivot', function (): void {
    $chart = ['enabled' => true, 'chartType' => 'stacked_bar', 'xAxis' => 'customer', 'series' => ['net_amount'], 'showLegend' => true, 'showDataLabels' => false];
    $data = pivotRun(['rows' => ['customer'], 'columns' => ['date:year']], ['net_amount', 'document_count'], ['chart_config' => $chart])->assertOk()->json('data.chart');

    expect($data['type'])->toBe('stacked_bar')
        ->and($data['categories'])->toBe(['Alpha Srl', 'Beta Srl'])
        ->and(array_column($data['series'], 'name'))->toBe(['Net amount · 2026-01-01', 'Net amount · 2027-01-01'])
        ->and($data['series'][0]['data'])->toBe(['150.00', '300.00']);

    pivotRun(['rows' => ['customer'], 'columns' => []], ['net_amount'], ['chart_config' => ['enabled' => true, 'chartType' => 'radar']])->assertUnprocessable()->assertJsonValidationErrors('chart_config');
    pivotRun(['rows' => ['customer'], 'columns' => []], ['net_amount'])->assertOk()->assertJsonPath('data.chart', null);
});

test('the layout is validated: dimensions of the dataset only, no repeats, at most four in total', function (): void {
    pivotRun(['rows' => ['customer'], 'columns' => ['customer']])->assertUnprocessable();
    pivotRun(['rows' => ['customer', 'invoice_type', 'currency'], 'columns' => ['date:year', 'payment_status']])->assertUnprocessable();
    pivotRun(['rows' => ['nothing'], 'columns' => []])->assertUnprocessable();
    pivotRun(['rows' => 'customer', 'columns' => []])->assertUnprocessable();
});

test('field level security hides the fields a role may not see, from the catalog and from every request', function (): void {
    $admin = pivotCatalogKeys('inventory_moves');
    expect($admin['measures'])->toContain('total_valuation', 'average_unit_cost');

    // An accountant reads analytics and inventory but cannot write inventory data: cost and valuation are not theirs.
    biActAs($this->tenant, 'accountant');
    $accountant = pivotCatalogKeys('inventory_moves');
    expect($accountant['measures'])->toContain('quantity')->not->toContain('total_valuation')->not->toContain('average_unit_cost');
    $this->getJson('/api/v1/analytics/semantic/datasets/inventory_moves')->assertOk()->assertJsonMissing(['key' => 'total_valuation']);

    $spec = fn (array $measures, array $extra = []): array => ['dataset' => 'inventory_moves', 'spec' => biSpec(['movement_type'], $measures, $extra)];
    $this->postJson('/api/v1/analytics/designer/run', $spec(['total_valuation']))->assertUnprocessable();
    $this->postJson('/api/v1/analytics/designer/run', $spec(['quantity'], ['filters' => [['field' => 'total_valuation', 'operator' => 'gt', 'value' => '1']]]))->assertUnprocessable();
    $this->postJson('/api/v1/analytics/designer/pivot', [...$spec(['quantity']), 'pivot' => ['rows' => ['movement_type'], 'columns' => []], 'calculated_fields' => [['key' => 'unit', 'formula' => 'quantity / total_valuation', 'format' => 'number']]])->assertUnprocessable();
    $this->postJson('/api/v1/analytics/designer/run', $spec(['quantity']))->assertOk();

    // Saved by an administrator, run by the accountant: refused against the permissions of whoever runs it.
    biActAs($this->tenant, 'admin');
    $saved = $this->postJson('/api/v1/analytics/designed-reports', ['name' => 'Valuation', 'dataset' => 'inventory_moves', 'spec' => biSpec(['movement_type'], ['total_valuation']), 'visibility' => 'tenant_public'])->assertCreated()->json('data.id');
    biActAs($this->tenant, 'accountant');
    $this->getJson("/api/v1/analytics/designed-reports/{$saved}/run")->assertUnprocessable();
});

test('tenant isolation: a pivot only ever sees the rows of the active company', function (): void {
    $data = pivotRun(['rows' => ['customer'], 'columns' => []], ['net_amount'])->assertOk()->json('data');
    expect(array_map(fn (array $row): ?string => $row['total']['values']['net_amount'], $data['rows']))->toBe(['350.00', '280.00', '630.00']);

    $other = biTenant('Third');
    biActAs($other);
    $empty = pivotRun(['rows' => ['customer'], 'columns' => []], ['net_amount'])->assertOk()->json('data');
    expect($empty['rows'])->toHaveCount(1)->and($empty['rows'][0]['total']['values']['net_amount'])->toBeNull();
});

test('the studio catalog lists the governed datasets and every option the API accepts', function (): void {
    $data = $this->getJson('/api/v1/analytics/semantic/catalog')->assertOk()->json('data');

    expect(array_column($data['datasets'], 'key'))->toContain('sales_lines', 'purchase_lines', 'inventory_moves', 'timesheets', 'hr_headcount', 'ledger')
        ->and($data['options']['locales'])->toBe(['it-CH', 'de-CH', 'fr-CH', 'it-IT', 'en'])
        ->and($data['options']['formats'])->toBe(['csv', 'excel_csv', 'xlsx', 'pdf', 'json'])
        ->and($data['options']['chart_types'])->toBe(['bar', 'stacked_bar', 'line', 'area', 'pie', 'donut', 'combo'])
        ->and($data['options']['calculated']['functions'])->toBe(['ABS', 'ROUND', 'COALESCE', 'NULLIF'])
        ->and($data['options']['visibilities'])->toBe(['private', 'role_shared', 'tenant_public']);
});

test('reports are saved with their pivot, formulas, formatting, chart and visibility, and shared by role', function (): void {
    $payload = [
        'name' => 'Sales matrix',
        'dataset' => 'sales',
        'spec' => biSpec([], ['net_amount', 'document_count']),
        'pivot' => ['rows' => ['customer'], 'columns' => ['date:year'], 'subtotals' => true, 'grand_totals' => true],
        'calculated_fields' => [['key' => 'per_document', 'label' => 'Per document', 'formula' => 'net_amount / NULLIF(document_count, 0)', 'format' => 'currency']],
        'conditional_formatting' => [['target' => 'net_amount', 'operator' => 'gt', 'value' => '100', 'background' => 'green']],
        'chart_config' => ['enabled' => true, 'chartType' => 'bar', 'xAxis' => 'customer', 'series' => ['net_amount']],
        'visibility' => 'role_shared',
        'shared_role_ids' => ['accountant'],
    ];
    $id = $this->postJson('/api/v1/analytics/designed-reports', $payload)->assertCreated()
        ->assertJsonPath('data.visibility', 'role_shared')
        ->assertJsonPath('data.is_shared', false)
        ->assertJsonPath('data.spec.dimensions', ['customer', 'date:year'])
        ->assertJsonPath('data.pivot.rows', ['customer'])
        ->json('data.id');

    // The saved definition runs as a pivot.
    $this->getJson("/api/v1/analytics/designed-reports/{$id}/pivot")->assertOk()->assertJsonPath('data.rows.0.cells.0.values.net_amount', '150.00')->assertJsonPath('data.rows.0.cells.0.values.per_document', '75.000000');

    // Role shared: an accountant sees it, a sales user does not.
    biActAs($this->tenant, 'accountant');
    expect(collect($this->getJson('/api/v1/analytics/designed-reports')->assertOk()->json('data'))->pluck('id')->all())->toContain($id);
    biActAs($this->tenant, 'sales');
    expect(collect($this->getJson('/api/v1/analytics/designed-reports')->json('data') ?? [])->pluck('id')->all())->not->toContain($id);

    // Tenant public is visible to every member and keeps the legacy flag in step.
    biActAs($this->tenant, 'admin');
    $public = $this->postJson('/api/v1/analytics/designed-reports', [...$payload, 'name' => 'Public matrix', 'visibility' => 'tenant_public'])->assertCreated()->assertJsonPath('data.is_shared', true)->json('data.id');
    biActAs($this->tenant, 'accountant_readonly');
    expect(collect($this->getJson('/api/v1/analytics/designed-reports')->assertOk()->json('data'))->pluck('id')->all())->toContain($public);

    // Validation of the whole definition, and of the visibility choice.
    biActAs($this->tenant, 'admin');
    $this->postJson('/api/v1/analytics/designed-reports', [...$payload, 'name' => 'Bad role share', 'shared_role_ids' => []])->assertUnprocessable()->assertJsonValidationErrors('shared_role_ids');
    $this->postJson('/api/v1/analytics/designed-reports', [...$payload, 'name' => 'Bad visibility', 'visibility' => 'world'])->assertUnprocessable()->assertJsonValidationErrors('visibility');
    $this->postJson('/api/v1/analytics/designed-reports', [...$payload, 'name' => 'Bad formula', 'calculated_fields' => [['key' => 'x', 'formula' => 'net_amount; --', 'format' => 'number']]])->assertUnprocessable()->assertJsonValidationErrors('calculated_fields');
    $this->postJson('/api/v1/analytics/designed-reports', [...$payload, 'name' => 'Bad dimension', 'pivot' => ['rows' => ['tax_code'], 'columns' => []]])->assertUnprocessable()->assertJsonValidationErrors('spec.dimensions.0');
});

/**
 * @return array{dimensions: list<string>, measures: list<string>}
 */
function pivotCatalogKeys(string $dataset): array
{
    $found = collect(test()->getJson('/api/v1/analytics/semantic/catalog')->assertOk()->json('data.datasets'))->firstWhere('key', $dataset);

    return ['dimensions' => array_column($found['dimensions'], 'key'), 'measures' => array_column($found['measures'], 'key')];
}
