<?php

declare(strict_types=1);

use App\Models\Invoice;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Analytics\Models\DesignedReport;
use Modules\Analytics\Models\ExportRun;
use Modules\Analytics\Semantic\Dataset;
use Modules\Analytics\Semantic\Dimension;
use Modules\Analytics\Semantic\Measure;
use Modules\Analytics\Semantic\SemanticCatalog;
use Modules\HumanResources\Models\Employee;

require_once __DIR__.'/BiTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2027-03-15 10:00:00');
    $this->tenant = biTenant('Alfa');
    $this->user = biActAs($this->tenant);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function biRun(array $spec, string $dataset = 'sales', array $extra = []): TestResponse
{
    return test()->postJson('/api/v1/analytics/designer/run', ['dataset' => $dataset, 'spec' => $spec, ...$extra]);
}

test('the catalogue lists only the datasets of modules the caller can read', function (): void {
    $keys = collect($this->getJson('/api/v1/analytics/semantic/datasets')->assertOk()->json('data'))->pluck('key')->all();
    expect($keys)->toEqualCanonicalizing(['sales', 'purchases', 'inventory', 'ledger', 'projects', 'hr_headcount']);

    biActAs($this->tenant, 'accountant');
    $keys = collect($this->getJson('/api/v1/analytics/semantic/datasets')->assertOk()->json('data'))->pluck('key')->all();
    expect($keys)->not->toContain('hr_headcount')->toContain('sales');
    $this->getJson('/api/v1/analytics/semantic/datasets/hr_headcount')->assertForbidden();
    $this->getJson('/api/v1/analytics/semantic/datasets/nothing')->assertNotFound();

    $sales = $this->getJson('/api/v1/analytics/semantic/datasets/sales')->assertOk()->json('data');
    expect(collect($sales['dimensions'])->pluck('key')->all())->toContain('customer', 'date')
        ->and(collect($sales['measures'])->pluck('key')->all())->toContain('net_amount');
});

test('a report groups, sorts, takes the top-N and paginates over posted data only', function (): void {
    $rossi = biCustomer($this->tenant, 'Rossi');
    $bianchi = biCustomer($this->tenant, 'Bianchi');
    $verdi = biCustomer($this->tenant, 'Verdi', 'CH');
    biInvoice($this->tenant, $rossi, '2027-03-05', '150.00');
    biInvoice($this->tenant, $rossi, '2027-03-06', '-50.00', ['invoice_type' => 'credit_note']);
    biInvoice($this->tenant, $bianchi, '2027-03-07', '300.00');
    biInvoice($this->tenant, $verdi, '2027-03-08', '20.00');
    biInvoice($this->tenant, $verdi, '2027-03-09', '999.00', posted: false);
    biInvoice($this->tenant, $verdi, '2027-03-09', '999.00', ['status' => 'cancelled']);

    $result = biRun(biSpec(['customer'], ['net_amount', 'document_count']))->assertOk()->json('data');
    expect(array_column($result['rows'], 'customer'))->toBe(['Bianchi', 'Rossi', 'Verdi'])
        ->and(array_column($result['rows'], 'net_amount'))->toBe(['300.00', '100.00', '20.00'])
        ->and($result['rows'][1]['document_count'])->toBe(2)
        ->and($result['meta']['total'])->toBe(3)
        ->and(array_column($result['columns'], 'key'))->toBe(['customer', 'net_amount', 'document_count']);

    $top = biRun(biSpec(['customer'], ['net_amount'], ['limit' => 2, 'sort' => [['field' => 'customer', 'direction' => 'desc']]]))->assertOk()->json('data');
    expect(array_column($top['rows'], 'customer'))->toBe(['Verdi', 'Rossi'])->and($top['meta']['total'])->toBe(2)->and($top['meta']['truncated'])->toBeFalse();

    $page = biRun(biSpec(['customer'], ['net_amount']), extra: ['page' => 2, 'per_page' => 2])->assertOk()->json('data');
    expect(array_column($page['rows'], 'customer'))->toBe(['Verdi'])->and($page['meta']['pages'])->toBe(2)->and($page['meta']['page'])->toBe(2);

    $total = biRun(biSpec([], ['net_amount', 'document_count']))->assertOk()->json('data');
    expect($total['rows'])->toHaveCount(1)->and($total['rows'][0]['net_amount'])->toBe('420.00');
});

test('filters, date granularity and period presets work and the period is resolved on the calendar of today', function (): void {
    $rossi = biCustomer($this->tenant, 'Rossi');
    $verdi = biCustomer($this->tenant, 'Verdi', 'CH');
    biInvoice($this->tenant, $rossi, '2027-01-10', '100.00');
    biInvoice($this->tenant, $rossi, '2027-02-10', '200.00');
    biInvoice($this->tenant, $rossi, '2027-03-10', '400.00');
    biInvoice($this->tenant, $verdi, '2027-03-11', '50.00');

    $byMonth = biRun(biSpec(['date:month'], ['net_amount'], ['period' => ['from' => '2027-01-01', 'to' => '2027-03-31'], 'sort' => [['field' => 'date', 'direction' => 'asc']]]))->assertOk()->json('data');
    expect(array_column($byMonth['rows'], 'date'))->toBe(['2027-01-01', '2027-02-01', '2027-03-01'])
        ->and(array_column($byMonth['rows'], 'net_amount'))->toBe(['100.00', '200.00', '450.00']);

    $mtd = biRun(biSpec([], ['net_amount'], ['period' => ['preset' => 'month_to_date']]))->assertOk()->json('data');
    expect($mtd['rows'][0]['net_amount'])->toBe('450.00')->and($mtd['meta']['period'])->toBe(['from' => '2027-03-01', 'to' => '2027-03-15']);

    $filters = [
        [['field' => 'customer_country', 'operator' => 'eq', 'value' => 'CH'], '50.00'],
        [['field' => 'customer', 'operator' => 'contains', 'value' => 'oss'], '700.00'],
        [['field' => 'customer', 'operator' => 'starts_with', 'value' => 'ver'], '50.00'],
        [['field' => 'customer', 'operator' => 'in', 'value' => ['Rossi', 'Verdi']], '750.00'],
        [['field' => 'customer', 'operator' => 'neq', 'value' => 'Rossi'], '50.00'],
        [['field' => 'date', 'operator' => 'between', 'value' => ['2027-02-01', '2027-03-10']], '600.00'],
        [['field' => 'date', 'operator' => 'gte', 'value' => '2027-03-11'], '50.00'],
        [['field' => 'invoice_number', 'operator' => 'not_null'], '750.00'],
    ];

    foreach ($filters as [$filter, $expected]) {
        expect(biRun(biSpec([], ['net_amount'], ['filters' => [$filter]]))->assertOk()->json('data.rows.0.net_amount'))->toBe($expected, json_encode($filter));
    }
});

test('a period comparison adds the previous value and the change, and refuses to be combined with a date grouping', function (): void {
    $rossi = biCustomer($this->tenant, 'Rossi');
    $verdi = biCustomer($this->tenant, 'Verdi');
    biInvoice($this->tenant, $rossi, '2027-02-10', '100.00');
    biInvoice($this->tenant, $rossi, '2027-03-10', '150.00');
    biInvoice($this->tenant, $verdi, '2027-03-10', '80.00');

    $period = ['from' => '2027-03-01', 'to' => '2027-03-31'];
    $result = biRun(biSpec(['customer'], ['net_amount'], ['period' => $period, 'compare' => 'previous_year']))->assertOk()->json('data');
    expect($result['meta']['comparison_period'])->toBe(['from' => '2026-03-01', 'to' => '2026-03-31'])
        ->and($result['rows'][0]['net_amount__previous'])->toBeNull();

    $result = biRun(biSpec(['customer'], ['net_amount'], ['period' => ['from' => '2027-03-01', 'to' => '2027-03-28'], 'compare' => 'previous_period']))->assertOk()->json('data');
    $rossiRow = collect($result['rows'])->firstWhere('customer', 'Rossi');
    expect($result['meta']['comparison_period'])->toBe(['from' => '2027-02-01', 'to' => '2027-02-28'])
        ->and($rossiRow['net_amount__previous'])->toBe('100.00')
        ->and($rossiRow['net_amount__change'])->toBe('50.00')
        ->and($rossiRow['net_amount__change_percent'])->toBe('50.00')
        ->and(collect($result['rows'])->firstWhere('customer', 'Verdi')['net_amount__change_percent'])->toBeNull();

    biRun(biSpec(['date:month'], ['net_amount'], ['period' => $period, 'compare' => 'previous_year']))->assertUnprocessable()->assertJsonValidationErrors(['spec.compare']);
    biRun(biSpec(['customer'], ['net_amount'], ['compare' => 'previous_year']))->assertUnprocessable()->assertJsonValidationErrors(['spec.compare']);
    biRun(biSpec(['sku'], ['stock_value'], ['period' => $period]), 'inventory')->assertUnprocessable()->assertJsonValidationErrors(['spec.period']);
});

test('field names, operators, granularities and values cannot inject SQL or reach a column outside the allow-list', function (): void {
    $rossi = biCustomer($this->tenant, 'Rossi');
    biInvoice($this->tenant, $rossi, '2027-03-05', '150.00');
    $invoices = Invoice::query()->count();

    $attacks = [
        ['dimensions' => ['customer; DROP TABLE invoices; --'], 'measures' => ['net_amount']],
        ['dimensions' => ['invoices.tenant_id'], 'measures' => ['net_amount']],
        ['dimensions' => ['tenant_id'], 'measures' => ['net_amount']],
        ['dimensions' => ['customer'], 'measures' => ['net_amount) FROM users --']],
        ['dimensions' => ['customer'], 'measures' => ['total_net']],
        ['dimensions' => ['customer'], 'measures' => ['SUM(total_net)']],
        ['dimensions' => ['date:month\') FROM users --'], 'measures' => ['net_amount']],
        ['dimensions' => ['date:century'], 'measures' => ['net_amount']],
        ['dimensions' => ['customer:month'], 'measures' => ['net_amount']],
        ['dimensions' => ['1'], 'measures' => ['net_amount']],
        ['dimensions' => [['customer']], 'measures' => ['net_amount']],
        ['dimensions' => ['customer'], 'measures' => ['net_amount'], 'sort' => [['field' => 'total_gross']]],
        ['dimensions' => ['customer'], 'measures' => ['net_amount'], 'sort' => [['field' => 'net_amount; DROP TABLE invoices', 'direction' => 'asc']]],
        ['dimensions' => ['customer'], 'measures' => ['net_amount'], 'sort' => [['field' => 'net_amount', 'direction' => 'asc; --']]],
        ['dimensions' => ['customer'], 'measures' => ['net_amount'], 'filters' => [['field' => 'tenant_id', 'operator' => 'eq', 'value' => 'x']]],
        ['dimensions' => ['customer'], 'measures' => ['net_amount'], 'filters' => [['field' => 'customer) OR (1=1', 'operator' => 'eq', 'value' => 'x']]],
        ['dimensions' => ['customer'], 'measures' => ['net_amount'], 'filters' => [['field' => 'customer', 'operator' => '= 1 OR 1=1 --', 'value' => 'x']]],
        ['dimensions' => ['customer'], 'measures' => ['net_amount'], 'filters' => [['field' => 'customer', 'operator' => 'gt', 'value' => 'x']]],
        ['dimensions' => ['customer'], 'measures' => ['net_amount'], 'filters' => [['field' => 'date', 'operator' => 'eq', 'value' => '2027-03-05\' OR 1=1 --']]],
        ['dimensions' => ['customer'], 'measures' => ['net_amount'], 'filters' => [['field' => 'date', 'operator' => 'eq', 'value' => '2027-02-30']]],
        ['dimensions' => ['customer'], 'measures' => ['net_amount'], 'filters' => [['field' => 'customer', 'operator' => 'in', 'value' => 'a,b']]],
        ['dimensions' => ['customer'], 'measures' => ['net_amount'], 'filters' => [['field' => 'customer', 'operator' => 'eq', 'value' => ['a']]]],
        ['dimensions' => ['customer'], 'measures' => ['net_amount'], 'limit' => '1; DROP TABLE invoices'],
        ['dimensions' => ['customer'], 'measures' => ['net_amount'], 'period' => ['from' => '2027-03-01\'; --', 'to' => '2027-03-31']],
        ['dimensions' => ['customer'], 'measures' => ['net_amount'], 'period' => ['preset' => 'everything']],
        ['dimensions' => ['customer'], 'measures' => ['net_amount'], 'compare' => 'previous_year; --'],
        ['dimensions' => ['customer'], 'measures' => []],
    ];

    foreach ($attacks as $index => $spec) {
        biRun($spec)->assertUnprocessable();
        expect(Invoice::query()->count())->toBe($invoices, "attack {$index}");
    }

    // The dataset name is not a table name either.
    $this->postJson('/api/v1/analytics/designer/run', ['dataset' => 'users', 'spec' => biSpec([], ['net_amount'])])->assertUnprocessable()->assertJsonValidationErrors(['dataset']);
    $this->postJson('/api/v1/analytics/designer/run', ['dataset' => 'invoices; DROP TABLE invoices', 'spec' => biSpec([], ['net_amount'])])->assertUnprocessable();

    // A value that looks like SQL is only a value: it matches nothing, instead of everything.
    $none = biRun(biSpec([], ['document_count'], ['filters' => [['field' => 'customer', 'operator' => 'eq', 'value' => "Rossi' OR '1'='1"]]]))->assertOk()->json('data.rows.0.document_count');
    expect($none)->toBe(0);

    // LIKE wildcards are literal.
    expect(biRun(biSpec([], ['document_count'], ['filters' => [['field' => 'customer', 'operator' => 'contains', 'value' => '%']]]))->assertOk()->json('data.rows.0.document_count'))->toBe(0)
        ->and(biRun(biSpec([], ['document_count'], ['filters' => [['field' => 'customer', 'operator' => 'contains', 'value' => 'R_ssi']]]))->assertOk()->json('data.rows.0.document_count'))->toBe(0)
        ->and(biRun(biSpec([], ['document_count'], ['filters' => [['field' => 'customer', 'operator' => 'contains', 'value' => 'ROSS']]]))->assertOk()->json('data.rows.0.document_count'))->toBe(1);
});

test('reports never cross the company boundary, not even through a join', function (): void {
    $other = biTenant('Beta');
    $foreignCustomer = biCustomer($other, 'Straniero');
    biInvoice($other, $foreignCustomer, '2027-03-05', '9000.00');
    $ownCustomer = biCustomer($this->tenant, 'Rossi');
    biInvoice($this->tenant, $ownCustomer, '2027-03-05', '100.00');
    // An invoice of this company pointing at a contact of another company must not read its name.
    biInvoice($this->tenant, $foreignCustomer, '2027-03-06', '10.00');

    $rows = biRun(biSpec(['customer'], ['net_amount']))->assertOk()->json('data.rows');
    expect(collect($rows)->pluck('customer')->all())->toContain('Rossi', '')->not->toContain('Straniero')
        ->and(collect($rows)->sum(fn (array $row): float => (float) $row['net_amount']))->toBe(110.0);

    // A filter on the other company's contact finds nothing; a tenant filter cannot be asked for at all.
    expect(biRun(biSpec([], ['net_amount'], ['filters' => [['field' => 'customer', 'operator' => 'eq', 'value' => 'Straniero']]]))->assertOk()->json('data.rows.0.net_amount'))->toBeNull();

    // The other company sees only its own invoice.
    biActAs($other);
    expect(biRun(biSpec([], ['net_amount']))->assertOk()->json('data.rows.0.net_amount'))->toBe('9000.00');
});

test('every dataset runs on its own tables with its allow-listed columns and the module permission', function (): void {
    Employee::factory()->create(['tenant_id' => $this->tenant->id, 'hire_date' => '2026-01-10', 'employment_status' => 'active', 'termination_date' => null]);
    Employee::factory()->create(['tenant_id' => $this->tenant->id, 'hire_date' => '2019-01-10', 'employment_status' => 'terminated', 'termination_date' => '2020-02-20']);
    Employee::factory()->create(['tenant_id' => $this->tenant->id, 'hire_date' => '2027-04-01', 'employment_status' => 'candidate']);

    $headcount = biRun(biSpec(['employment_status'], ['employee_count', 'active_headcount'], ['sort' => [['field' => 'employment_status', 'direction' => 'asc']]]), 'hr_headcount')->assertOk()->json('data.rows');
    expect($headcount)->toBe([
        ['employment_status' => 'active', 'employee_count' => 1, 'active_headcount' => 1],
        ['employment_status' => 'terminated', 'employee_count' => 1, 'active_headcount' => 0],
    ]);

    // Personal and pay data are not in the allow-list.
    biRun(biSpec(['tax_code'], ['employee_count']), 'hr_headcount')->assertUnprocessable();
    biRun(biSpec([], ['base_annual_salary']), 'hr_headcount')->assertUnprocessable();

    foreach (['purchases' => 'net_amount', 'inventory' => 'stock_value', 'ledger' => 'debit', 'projects' => 'contract_value'] as $dataset => $measure) {
        biRun(biSpec([], [$measure]), $dataset)->assertOk();
    }

    biActAs($this->tenant, 'accountant');
    biRun(biSpec([], ['employee_count']), 'hr_headcount')->assertForbidden();
    biRun(biSpec([], ['net_amount']), 'sales')->assertOk();
});

test('a report shared with a user who cannot read its module is refused to that user', function (): void {
    $hrReport = DesignedReport::factory()->shared()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'name' => 'Headcount', 'dataset' => 'hr_headcount', 'spec' => biSpec(['department'], ['active_headcount'])]);
    $this->getJson("/api/v1/analytics/designed-reports/{$hrReport->id}/run")->assertOk();

    biActAs($this->tenant, 'accountant');
    $this->getJson("/api/v1/analytics/designed-reports/{$hrReport->id}/run")->assertForbidden();
    $this->get("/api/v1/analytics/designed-reports/{$hrReport->id}/export")->assertForbidden();
    expect(ExportRun::query()->count())->toBe(0);
    $this->postJson('/api/v1/analytics/designed-reports', ['name' => 'Mine', 'dataset' => 'hr_headcount', 'spec' => biSpec([], ['active_headcount'])])->assertForbidden();
});

test('the row cap, the page size, the top-N and the time limit are hard', function (): void {
    foreach (range(1, 5) as $number) {
        biInvoice($this->tenant, biCustomer($this->tenant, "Cliente {$number}"), '2027-03-05', (string) ($number * 10).'.00');
    }

    config(['analytics.reporting.max_rows' => 3]);
    $result = biRun(biSpec(['customer'], ['net_amount']), extra: ['per_page' => 2, 'page' => 2])->assertOk()->json('data');
    expect($result['meta']['total'])->toBe(3)->and($result['meta']['truncated'])->toBeTrue()->and($result['rows'])->toHaveCount(1);
    expect(biRun(biSpec(['customer'], ['net_amount']), extra: ['per_page' => 2, 'page' => 3])->assertOk()->json('data.rows'))->toBe([]);

    $export = $this->postJson('/api/v1/analytics/designer/export', ['dataset' => 'sales', 'spec' => biSpec(['customer'], ['net_amount'])])->assertOk();
    expect($export->headers->get('X-Report-Truncated'))->toBe('true')->and(substr_count($export->getContent(), "\r\n"))->toBe(4);

    biRun(biSpec(['customer'], ['net_amount'], ['limit' => 4]))->assertUnprocessable()->assertJsonValidationErrors(['spec.limit']);
    config(['analytics.reporting.max_rows' => 10000]);
    biRun(biSpec(['customer'], ['net_amount'], ['limit' => 0]))->assertUnprocessable();
    biRun(biSpec(['customer'], ['net_amount']), extra: ['per_page' => 501])->assertUnprocessable()->assertJsonValidationErrors(['per_page']);

    // A query that runs too long is cancelled by the database and reported as a 422.
    app(SemanticCatalog::class)->extend(new Dataset(
        key: 'slow',
        label: 'Slow',
        description: 'Test dataset whose measure sleeps for every row',
        module: 'invoicing',
        table: 'invoices',
        joins: [],
        conditions: [],
        dimensions: [new Dimension('status', 'Status', Dimension::TypeString, 'invoices.status')],
        measures: [new Measure('slept', 'Slept', Measure::UnitCount, 'COUNT(*) FILTER (WHERE length(pg_sleep(0.4)::text) >= 0)')],
    ));
    config(['analytics.reporting.timeout_ms' => 300]);
    biRun(biSpec(['status'], ['slept']), 'slow')->assertUnprocessable()->assertJsonValidationErrors(['report']);
    config(['analytics.reporting.timeout_ms' => 15000]);
    $this->postJson('/api/v1/analytics/designer/run', ['dataset' => 'slow', 'spec' => biSpec(['status'], ['slept'])])->assertOk()->assertJsonPath('data.rows.0.slept', 5);
});

test('designed reports are saved per user, shared with the company or with named members, and validated', function (): void {
    $colleague = biUser($this->tenant, 'accountant');
    $outsider = biUser(biTenant('Beta'), 'accountant');
    $payload = ['name' => 'Top customers', 'dataset' => 'sales', 'spec' => biSpec(['customer'], ['net_amount'], ['limit' => 10, 'period' => ['preset' => 'year_to_date']])];

    $id = $this->postJson('/api/v1/analytics/designed-reports', $payload)->assertCreated()->assertJsonPath('data.is_owner', true)->assertJsonPath('data.spec.limit', 10)->json('data.id');
    $this->postJson('/api/v1/analytics/designed-reports', $payload)->assertUnprocessable()->assertJsonValidationErrors(['name']);
    $this->postJson('/api/v1/analytics/designed-reports', [...$payload, 'name' => 'Bad', 'spec' => biSpec(['nope'], ['net_amount'])])->assertUnprocessable()->assertJsonValidationErrors(['spec.dimensions.0']);
    $this->postJson('/api/v1/analytics/designed-reports', [...$payload, 'name' => 'Bad', 'dataset' => 'users'])->assertUnprocessable()->assertJsonValidationErrors(['dataset']);
    $this->postJson('/api/v1/analytics/designed-reports', [...$payload, 'name' => 'Share', 'shared_with' => [(string) $outsider->id]])->assertUnprocessable()->assertJsonValidationErrors(['shared_with.0']);

    // Private to the owner until shared.
    Sanctum::actingAs($colleague);
    $this->getJson("/api/v1/analytics/designed-reports/{$id}")->assertNotFound();
    expect($this->getJson('/api/v1/analytics/designed-reports')->json('data'))->toBe([]);

    Sanctum::actingAs($this->user);
    $this->putJson("/api/v1/analytics/designed-reports/{$id}", [...$payload, 'shared_with' => [(string) $colleague->id]])->assertOk()->assertJsonPath('data.shared_with.0', (string) $colleague->id);

    Sanctum::actingAs($colleague);
    $this->getJson("/api/v1/analytics/designed-reports/{$id}")->assertOk()->assertJsonPath('data.is_owner', false)->assertJsonPath('data.shared_with', []);
    $this->getJson("/api/v1/analytics/designed-reports/{$id}/run")->assertOk()->assertJsonPath('data.meta.dataset', 'sales');
    $this->putJson("/api/v1/analytics/designed-reports/{$id}", $payload)->assertForbidden();
    $this->deleteJson("/api/v1/analytics/designed-reports/{$id}")->assertForbidden();

    // Shared with the whole company, still changed only by its owner or an administrator.
    Sanctum::actingAs($this->user);
    $this->putJson("/api/v1/analytics/designed-reports/{$id}", [...$payload, 'is_shared' => true])->assertOk();
    Sanctum::actingAs(biUser($this->tenant, 'accountant'));
    $this->getJson("/api/v1/analytics/designed-reports/{$id}")->assertOk();

    // A read-only role runs reports but cannot design or save them.
    Sanctum::actingAs(biUser($this->tenant, 'accountant_readonly'));
    $this->getJson("/api/v1/analytics/designed-reports/{$id}/run")->assertOk();
    $this->postJson('/api/v1/analytics/designed-reports', [...$payload, 'name' => 'Nope'])->assertForbidden();
    $this->postJson('/api/v1/analytics/designer/run', ['dataset' => 'sales', 'spec' => $payload['spec']])->assertForbidden();

    // Another company sees nothing of it.
    biActAs(biTenant('Gamma'));
    $this->getJson("/api/v1/analytics/designed-reports/{$id}")->assertNotFound();
    $this->getJson("/api/v1/analytics/designed-reports/{$id}/run")->assertNotFound();
    expect($this->getJson('/api/v1/analytics/designed-reports')->json('data'))->toBe([]);

    // An administrator of the owning company may remove it.
    biActAs($this->tenant, 'admin');
    $this->deleteJson("/api/v1/analytics/designed-reports/{$id}")->assertOk();
    $this->getJson("/api/v1/analytics/designed-reports/{$id}")->assertNotFound();

    app('auth')->forgetGuards();
    $this->withHeaders(['X-Tenant-Id' => $this->tenant->id])->getJson('/api/v1/analytics/designed-reports')->assertUnauthorized();
});

test('exports are neutralised csv in two formats, limited, and audit logged', function (): void {
    biInvoice($this->tenant, biCustomer($this->tenant, '=HYPERLINK("http://evil")'), '2027-03-05', '150.50');
    biInvoice($this->tenant, biCustomer($this->tenant, 'Rossi; "Srl"'), '2027-03-06', '20.00');
    $spec = biSpec(['customer'], ['net_amount', 'document_count']);
    $id = $this->postJson('/api/v1/analytics/designed-reports', ['name' => 'Export me', 'dataset' => 'sales', 'spec' => $spec])->assertCreated()->json('data.id');

    $csv = $this->get("/api/v1/analytics/designed-reports/{$id}/export")->assertOk();
    expect($csv->headers->get('Content-Type'))->toContain('text/csv')
        ->and($csv->headers->get('Content-Disposition'))->toContain('export-me-')
        ->and($csv->getContent())->toStartWith("\"customer\",\"net_amount\",\"document_count\"\r\n")
        ->and($csv->getContent())->toContain("\"'=HYPERLINK(\"\"http://evil\"\")\",\"150.50\",\"1\"")
        ->and($csv->getContent())->toContain('"Rossi; ""Srl""","20.00","1"');

    $excel = $this->get("/api/v1/analytics/designed-reports/{$id}/export?format=excel_csv")->assertOk()->getContent();
    expect($excel)->toStartWith("\xEF\xBB\xBF\"customer\";\"net_amount\";\"document_count\"")->and($excel)->toContain("\"'=HYPERLINK(");

    $this->get("/api/v1/analytics/designed-reports/{$id}/export?format=xls")->assertUnprocessable();
    $this->postJson('/api/v1/analytics/designer/export', ['dataset' => 'sales', 'spec' => $spec, 'format' => 'excel_csv'])->assertOk();

    expect(ExportRun::query()->count())->toBe(3);
    $entries = AuditLedgerEntry::query()->where('action', 'ANALYTICS_REPORT_EXPORTED')->get();
    expect($entries)->toHaveCount(3)
        ->and((string) $entries->first()->actor_user_id)->toBe((string) $this->user->id)
        ->and(json_encode($entries->first()->payload_after))->toContain('"dataset":"sales"', '"row_count":2');
});

test('the existing kpi and report definition endpoints keep their shape', function (): void {
    biInvoice($this->tenant, biCustomer($this->tenant, 'Rossi'), '2027-03-05', '150.00');

    $kpi = $this->getJson('/api/v1/analytics/kpis/sales?from=2027-03-01&to=2027-03-31')->assertOk()->json('data');
    expect($kpi['key'])->toBe('sales')->and($kpi['value'])->toBe('150.00');
    $this->postJson('/api/v1/analytics/report-definitions', ['name' => 'Old', 'kpis' => ['sales'], 'period_preset' => 'month_to_date'])->assertCreated();
    $this->getJson('/api/v1/analytics/report-definitions')->assertOk()->assertJsonCount(1, 'data');
});

test('the xlsx export is a real workbook with typed cells, neutralised text, a parameters sheet, the row limit and the audit entry', function (): void {
    biInvoice($this->tenant, biCustomer($this->tenant, '=HYPERLINK("http://evil")'), '2027-03-05', '150.50');
    biInvoice($this->tenant, biCustomer($this->tenant, '007'), '2027-02-06', '20.00');
    $spec = biSpec(['customer', 'date:month'], ['net_amount', 'document_count'], ['filters' => [['field' => 'customer_country', 'operator' => 'eq', 'value' => 'IT']], 'period' => ['from' => '2027-01-01', 'to' => '2027-03-31'], 'sort' => [['field' => 'net_amount', 'direction' => 'desc']]]);
    $id = $this->postJson('/api/v1/analytics/designed-reports', ['name' => 'Q1/Q2: sales? with a very long name', 'dataset' => 'sales', 'spec' => $spec])->assertCreated()->json('data.id');

    $response = $this->get("/api/v1/analytics/designed-reports/{$id}/export?format=xlsx")->assertOk();
    $binary = $response->getContent();
    expect($binary)->toStartWith('PK')
        ->and($response->headers->get('Content-Type'))->toContain('spreadsheetml.sheet')
        ->and($response->headers->get('Content-Disposition'))->toContain('.xlsx');

    $sheets = biReadXlsx($binary);
    expect(array_keys($sheets))->toBe(['Q1 Q2 sales with a very long na', 'Parameters']);
    $rows = array_values($sheets)[0];
    expect($rows[0])->toBe(['customer', 'date', 'net_amount', 'document_count'])
        ->and($rows[1][0])->toBe("'=HYPERLINK(\"http://evil\")")
        ->and($rows[1][1])->toBeInstanceOf(DateTimeInterface::class)
        ->and($rows[1][1]->format('Y-m-d'))->toBe('2027-03-01')
        ->and($rows[1][2])->toBe(150.5)
        ->and($rows[1][3])->toBe(1)
        ->and($rows[2][0])->toBe('007')
        ->and($rows)->toHaveCount(3);

    $parameters = array_column($sheets['Parameters'], 1, 0);
    expect($parameters['Dataset'])->toBe('sales')
        ->and($parameters['Report'])->toBe('Q1/Q2: sales? with a very long name')
        ->and($parameters['Filters'])->toBe('customer_country eq "IT"')
        ->and($parameters['Period'])->toBe('2027-01-01 - 2027-03-31')
        ->and($parameters['Truncated by the row limit'])->toBe('no')
        ->and($parameters['Generated at'])->toBeInstanceOf(DateTimeInterface::class);

    $zip = new ZipArchive;
    $path = tempnam(sys_get_temp_dir(), 'bi');
    file_put_contents($path, $binary);
    $zip->open($path);
    expect($zip->getFromName('xl/worksheets/sheet1.xml'))->toContain('<pane')->toContain('<cols>');
    $zip->close();
    unlink($path);

    $entry = AuditLedgerEntry::query()->where('action', 'ANALYTICS_REPORT_EXPORTED')->sole();
    expect($entry->payload_after['format'])->toBe('xlsx')->and($entry->payload_after['row_count'])->toBe(2);

    config(['analytics.reporting.max_rows' => 1]);
    $limited = $this->postJson('/api/v1/analytics/designer/export', ['dataset' => 'sales', 'spec' => biSpec(['customer'], ['net_amount']), 'format' => 'xlsx'])->assertOk();
    $limitedSheets = biReadXlsx($limited->getContent());
    expect($limited->headers->get('X-Report-Truncated'))->toBe('true')
        ->and(array_values($limitedSheets)[0])->toHaveCount(2)
        ->and(array_column($limitedSheets['Parameters'], 1, 0)['Truncated by the row limit'])->toBe('yes');

    $this->postJson('/api/v1/analytics/designer/export', ['dataset' => 'sales', 'spec' => $spec, 'format' => 'xls'])->assertUnprocessable();
});
