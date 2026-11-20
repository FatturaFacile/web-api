<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Copilot\DTOs\ToolContext;
use Modules\Copilot\Semantic\ExecuteGovernedSemanticQuery;
use Modules\Copilot\Semantic\SemanticQueryException;
use Modules\HumanResources\Models\Employee;
use Tests\Support\CopilotScenario;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    $this->owner = CopilotScenario::member($this->tenant, 'owner');
    CopilotScenario::actAs($this, $this->owner, $this->tenant);

    $this->contact = Contact::factory()->create(['tenant_id' => $this->tenant->id, 'company_name' => 'Rossi SA']);
    $foreign = Contact::factory()->create(['tenant_id' => $this->other->id, 'company_name' => 'Foreign Ltd']);

    CopilotScenario::invoice($this->tenant, $this->contact, 'INV-1', '100.00', '2026-01-10');
    CopilotScenario::invoice($this->tenant, $this->contact, 'INV-2', '250.50', '2026-01-25', 'paid');
    CopilotScenario::invoice($this->tenant, $this->contact, 'INV-3', '400.00', '2026-02-03');
    CopilotScenario::invoice($this->other, $foreign, 'FOREIGN-1', '99999.00', '2026-01-15');

    $this->engine = app(ExecuteGovernedSemanticQuery::class);
    $this->context = new ToolContext($this->owner, $this->tenant->id, 'en');
});

afterEach(fn () => app(TenantContext::class)->clear());

test('revenue by month is summed per bucket and never includes another tenant', function (): void {
    $result = $this->engine->execute($this->context, [
        'entity' => 'invoices',
        'metrics' => [['fn' => 'sum', 'field' => 'total_net', 'alias' => 'revenue'], ['fn' => 'count']],
        'dimensions' => ['month:date'],
    ]);

    expect($result->data['rows'])->toBe([
        ['month_date' => '2026-01', 'revenue' => '350.50', 'count' => 2],
        ['month_date' => '2026-02', 'revenue' => '400.00', 'count' => 1],
    ])
        ->and($result->visualization['type'])->toBe('line')
        ->and($result->visualization['labels'])->toBe(['2026-01', '2026-02'])
        ->and($result->visualization['datasets'][0])->toBe(['name' => 'revenue', 'data' => [350.5, 400.0]]);
});

test('a single metric without dimension is a kpi card and avg min max follow the data', function (): void {
    $kpi = $this->engine->execute($this->context, ['entity' => 'invoices', 'metrics' => [['fn' => 'avg', 'field' => 'total_net', 'alias' => 'average']]]);

    expect($kpi->visualization['type'])->toBe('kpi_card')
        ->and($kpi->visualization['value'])->toBe('250.17');

    $range = $this->engine->execute($this->context, ['entity' => 'invoices', 'metrics' => [['fn' => 'min', 'field' => 'date', 'alias' => 'first'], ['fn' => 'max', 'field' => 'total_net', 'alias' => 'biggest']]]);

    expect($range->data['rows'][0])->toBe(['first' => '2026-01-10', 'biggest' => '400.00']);
});

test('a grouped result honours the requested chart when it fits and falls back when it does not', function (): void {
    $pie = $this->engine->execute($this->context, ['entity' => 'invoices', 'metrics' => [['fn' => 'sum', 'field' => 'total_net', 'alias' => 'revenue']], 'dimensions' => ['payment_status'], 'chart' => 'pie']);
    $badKpi = $this->engine->execute($this->context, ['entity' => 'invoices', 'metrics' => [['fn' => 'count']], 'dimensions' => ['payment_status'], 'chart' => 'kpi_card']);

    expect($pie->visualization['type'])->toBe('pie')
        ->and($pie->data['rows'][0]['payment_status'])->toBe('unpaid')
        ->and($badKpi->visualization['type'])->toBe('bar');
});

test('filters bind their values and the listing cites the records', function (): void {
    $result = $this->engine->execute($this->context, [
        'entity' => 'invoices',
        'select' => ['invoice_number', 'total_net'],
        'filters' => [['field' => 'date', 'op' => 'between', 'value' => ['2026-01-01', '2026-01-31']], ['field' => 'total_net', 'op' => 'gte', 'value' => 100]],
        'order_by' => ['field' => 'total_net', 'direction' => 'desc'],
    ]);

    expect(array_column($result->data['rows'], 'invoice_number'))->toBe(['INV-2', 'INV-1'])
        ->and($result->visualization['type'])->toBe('table')
        ->and($result->citations[0]['label'])->toBe('#Invoice-INV-2')
        ->and($result->citations[0]['entity_type'])->toBe('invoices')
        ->and($result->citations[0]['route'])->toStartWith('/invoices/');
});

test('text in a filter is a value, never SQL', function (): void {
    $injection = "x' OR 1=1; DROP TABLE invoices; --";

    $result = $this->engine->execute($this->context, ['entity' => 'invoices', 'select' => ['invoice_number'], 'filters' => [['field' => 'invoice_number', 'op' => 'eq', 'value' => $injection]]]);
    $contains = $this->engine->execute($this->context, ['entity' => 'invoices', 'select' => ['invoice_number'], 'filters' => [['field' => 'invoice_number', 'op' => 'contains', 'value' => '%']]]);

    expect($result->data['rows'])->toBe([])
        ->and($contains->data['rows'])->toBe([])
        ->and(DB::table('invoices')->count())->toBe(4);
});

test('unknown entities, fields, functions and operators are refused with a message for the model', function (array $query, string $message): void {
    expect(fn () => $this->engine->execute($this->context, $query))->toThrow(SemanticQueryException::class, $message);
})->with([
    'entity' => [['entity' => 'passwords'], 'Unknown entity'],
    'field' => [['entity' => 'invoices', 'select' => ['secret']], 'Unknown field'],
    'function' => [['entity' => 'invoices', 'metrics' => [['fn' => 'median', 'field' => 'total_net']]], 'Unknown metric function'],
    'sum of text' => [['entity' => 'invoices', 'metrics' => [['fn' => 'sum', 'field' => 'invoice_number']]], 'needs a number field'],
    'operator' => [['entity' => 'invoices', 'filters' => [['field' => 'date', 'op' => 'like', 'value' => 'x']]], 'Unknown filter operator'],
    'bucket' => [['entity' => 'invoices', 'metrics' => [['fn' => 'count']], 'dimensions' => ['month:total_net']], 'needs a date field'],
    'number filter' => [['entity' => 'invoices', 'filters' => [['field' => 'total_net', 'op' => 'gt', 'value' => 'abc']]], 'compared with a number'],
]);

test('salaries are redacted in a listing and refused anywhere else without payroll access', function (): void {
    Employee::factory()->create(['tenant_id' => $this->tenant->id, 'full_name' => 'Anna Bianchi', 'base_annual_salary' => '72000']);
    $hr = CopilotScenario::member($this->tenant, 'hr');
    $hrContext = new ToolContext($hr, $this->tenant->id, 'en');

    $listing = $this->engine->execute($hrContext, ['entity' => 'employees', 'select' => ['full_name', 'base_annual_salary']]);

    expect($listing->data['rows'][0])->toBe(['full_name' => 'Anna Bianchi', 'base_annual_salary' => '[REDACTED]'])
        ->and($listing->data['masked_fields'])->toContain('base_annual_salary', 'hourly_cost_rate');

    expect(fn () => $this->engine->execute($hrContext, ['entity' => 'employees', 'metrics' => [['fn' => 'avg', 'field' => 'base_annual_salary']]]))
        ->toThrow(SemanticQueryException::class, 'restricted');
    expect(fn () => $this->engine->execute($hrContext, ['entity' => 'employees', 'select' => ['full_name'], 'filters' => [['field' => 'base_annual_salary', 'op' => 'gt', 'value' => 70000]]]))
        ->toThrow(SemanticQueryException::class, 'restricted');

    $owner = $this->engine->execute($this->context, ['entity' => 'employees', 'select' => ['full_name', 'base_annual_salary']]);

    expect((float) $owner->data['rows'][0]['base_annual_salary'])->toBe(72000.0)
        ->and($owner->data['masked_fields'])->toBe([]);
});

test('an entity of a module the role cannot read is refused', function (): void {
    $member = CopilotScenario::member($this->tenant, 'member');

    expect(fn () => $this->engine->execute(new ToolContext($member, $this->tenant->id, 'en'), ['entity' => 'employees', 'select' => ['full_name']]))
        ->toThrow(AuthorizationException::class);
});
