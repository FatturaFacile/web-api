<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Analytics\Semantic\Dataset;
use Modules\BusinessIntelligence\Models\BiCalculatedMeasure;
use Modules\BusinessIntelligence\Models\BiDashboard;
use Modules\BusinessIntelligence\Models\BiDashboardWidget;
use Modules\BusinessIntelligence\Models\BiSavedQuery;
use Modules\BusinessIntelligence\Models\BiSnapshot;
use Modules\BusinessIntelligence\Security\RowLevelSecurityRegistry;
use Modules\BusinessIntelligence\Security\RowLevelSecurityRule;
use Modules\BusinessIntelligence\Services\FormulaEvaluator;
use Modules\BusinessIntelligence\Services\SnapshotService;

require_once __DIR__.'/../Analytics/BiTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2027-03-15 10:00:00');
    $this->tenant = biTenant('Alfa');
    $this->admin = biActAs($this->tenant);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function bixActAs(User $user, Tenant $tenant): void
{
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);
}

/**
 * Three customers with posted invoices: Rossi (IT) 100 + 50, Bianchi (IT) 300, Verdi (CH) 20.
 */
function bixSeedSales(Tenant $tenant): void
{
    $rossi = biCustomer($tenant, 'Rossi');
    $bianchi = biCustomer($tenant, 'Bianchi');
    $verdi = biCustomer($tenant, 'Verdi', 'CH');
    biInvoice($tenant, $rossi, '2027-03-05', '100.00');
    biInvoice($tenant, $rossi, '2027-03-06', '50.00');
    biInvoice($tenant, $bianchi, '2027-03-07', '300.00');
    biInvoice($tenant, $verdi, '2027-03-08', '20.00');
}

/**
 * @param  array<string, mixed>  $overrides
 */
function bixCalculated(array $overrides = []): TestResponse
{
    return test()->postJson('/api/v1/business-intelligence/calculated-measures', [
        'dataset' => 'sales', 'key' => 'average_ticket', 'label' => 'Average ticket', 'formula' => 'net_amount / document_count', 'unit' => 'currency', ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function bixQuery(array $overrides = []): TestResponse
{
    return test()->postJson('/api/v1/business-intelligence/saved-queries', [
        'name' => 'Sales by customer', 'dataset' => 'sales', 'spec' => biSpec(['customer'], ['net_amount', 'document_count']), ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function bixDashboard(array $overrides = []): array
{
    return test()->postJson('/api/v1/business-intelligence/dashboards', ['name' => 'Overview', ...$overrides])->assertCreated()->json('data');
}

/**
 * @param  array<string, mixed>  $overrides
 */
function bixWidget(string $dashboardId, array $overrides = []): TestResponse
{
    return test()->postJson("/api/v1/business-intelligence/dashboards/{$dashboardId}/widgets", [
        'title' => 'Net by customer', 'widget_type' => 'table', 'dataset' => 'sales', 'spec' => biSpec(['customer'], ['net_amount']), ...$overrides,
    ]);
}

test('the formula evaluator follows precedence, never errors on empty cells and refuses everything but arithmetic', function (): void {
    $formulas = new FormulaEvaluator;

    expect($formulas->evaluate('1 + 2 * 3', [], 2))->toBe('7.00')
        ->and($formulas->evaluate('(1 + 2) * 3', [], 0))->toBe('9')
        ->and($formulas->evaluate('-a + b / 3', ['a' => '1.00', 'b' => 10], 4))->toBe('2.3333')
        ->and($formulas->evaluate('net / count * 100', ['net' => '50.00', 'count' => 3], 2))->toBe('1666.67')
        ->and($formulas->evaluate('a / b', ['a' => '5', 'b' => '0'], 2))->toBeNull()
        ->and($formulas->evaluate('a + b', ['a' => '5', 'b' => null], 2))->toBeNull()
        ->and($formulas->references('net_amount / document_count + net_amount'))->toBe(['net_amount', 'document_count']);

    foreach (['', '1 +', '(1 + 2', 'sum(a)', 'a; DROP TABLE users', "a' or 1=1", '1 + 2)', 'a $ b', '1..2', str_repeat('(', 30).'1'.str_repeat(')', 30), str_repeat('a+', 300).'a'] as $bad) {
        expect(fn () => $formulas->parse($bad))->toThrow(InvalidArgumentException::class);
    }
});

test('a calculated measure is a validated formula over the base measures of a dataset the caller can read', function (): void {
    $id = bixCalculated()->assertCreated()->json('data.id');

    bixCalculated()->assertUnprocessable()->assertJsonValidationErrors('key');
    bixCalculated(['key' => 'net_amount'])->assertUnprocessable()->assertJsonValidationErrors('key');
    bixCalculated(['key' => 'customer'])->assertUnprocessable()->assertJsonValidationErrors('key');
    bixCalculated(['key' => 'bad', 'formula' => 'net_amount / nothing'])->assertUnprocessable()->assertJsonValidationErrors('formula');
    bixCalculated(['key' => 'bad', 'formula' => '10 / 2'])->assertUnprocessable()->assertJsonValidationErrors('formula');
    bixCalculated(['key' => 'bad', 'formula' => 'net_amount; DROP TABLE invoices'])->assertUnprocessable()->assertJsonValidationErrors('formula');
    bixCalculated(['key' => 'Bad Key'])->assertUnprocessable()->assertJsonValidationErrors('key');
    bixCalculated(['key' => 'other', 'dataset' => 'nothing'])->assertNotFound();

    $this->putJson("/api/v1/business-intelligence/calculated-measures/{$id}", ['formula' => 'tax_amount / document_count', 'label' => 'Tax per document', 'key' => 'ignored'])
        ->assertOk()->assertJsonPath('data.formula', 'tax_amount / document_count')->assertJsonPath('data.key', 'average_ticket');
    $this->putJson("/api/v1/business-intelligence/calculated-measures/{$id}", ['formula' => 'ghost * 2'])->assertUnprocessable();

    $catalog = collect($this->getJson('/api/v1/business-intelligence/datasets')->assertOk()->json('data'))->firstWhere('key', 'sales');
    expect($catalog['calculated_measures'][0]['key'])->toBe('average_ticket');

    $this->getJson('/api/v1/business-intelligence/calculated-measures?dataset=sales')->assertOk()->assertJsonCount(1, 'data');
    $this->deleteJson("/api/v1/business-intelligence/calculated-measures/{$id}")->assertNoContent();
    expect(BiCalculatedMeasure::query()->count())->toBe(0);
});

test('a calculated measure of a dataset the caller cannot read is refused, and a measure in use cannot be deleted', function (): void {
    bixActAs(biUser($this->tenant, 'accountant'), $this->tenant);
    bixCalculated(['dataset' => 'hr_headcount', 'key' => 'ratio', 'formula' => 'employee_count / employee_count'])->assertForbidden();

    bixActAs($this->admin, $this->tenant);
    $id = bixCalculated()->assertCreated()->json('data.id');
    bixQuery(['calculated_measures' => ['average_ticket']])->assertCreated();

    $this->deleteJson("/api/v1/business-intelligence/calculated-measures/{$id}")->assertUnprocessable()->assertJsonValidationErrors('id');
    expect(BiCalculatedMeasure::query()->count())->toBe(1);
});

test('a saved query runs with calculated measures computed on the aggregated rows and the helper measures stay hidden', function (): void {
    bixSeedSales($this->tenant);
    bixCalculated()->assertCreated();
    $id = bixQuery(['spec' => biSpec(['customer'], ['document_count'], ['sort' => [['field' => 'customer', 'direction' => 'asc']]]), 'calculated_measures' => ['average_ticket']])->assertCreated()->json('data.id');

    $result = $this->getJson("/api/v1/business-intelligence/saved-queries/{$id}/run")->assertOk()->json('data.result');

    expect(array_column($result['columns'], 'key'))->toBe(['customer', 'document_count', 'average_ticket'])
        ->and(array_column($result['rows'], 'customer'))->toBe(['Bianchi', 'Rossi', 'Verdi'])
        ->and(array_column($result['rows'], 'average_ticket'))->toBe(['300.00', '75.00', '20.00'])
        ->and($result['rows'][0])->not->toHaveKey('net_amount')
        ->and($result['columns'][2]['calculated'])->toBeTrue()
        ->and($result['meta']['truncated'])->toBeFalse();
});

test('a query outside the declaration of the dataset is refused whatever it contains', function (): void {
    bixQuery(['spec' => biSpec(['customer; DROP TABLE invoices'], ['net_amount'])])->assertUnprocessable();
    bixQuery(['spec' => biSpec(['customer'], ['SUM(total_net)'])])->assertUnprocessable();
    bixQuery(['spec' => biSpec(['customer'], ['net_amount'], ['filters' => [['field' => 'contacts.password', 'operator' => 'eq', 'value' => 'x']]])])->assertUnprocessable();
    bixQuery(['spec' => biSpec(['customer'], ['net_amount'], ['sort' => [['field' => 'invoices.id', 'direction' => 'asc']]])])->assertUnprocessable();
    bixQuery(['dataset' => 'users', 'spec' => biSpec(['email'], ['count'])])->assertNotFound();
    bixQuery(['calculated_measures' => ['ghost']])->assertUnprocessable();
    $this->postJson('/api/v1/business-intelligence/explorer/run', ['dataset' => 'sales', 'spec' => biSpec(['customer'], ['net_amount']), 'pivot' => ['rows' => ['customer'], 'values' => ['document_count']]])
        ->assertUnprocessable();
    expect(BiSavedQuery::query()->count())->toBe(0);
});

test('a pivot crosses dimensions, adds up additive measures and leaves the totals of the others empty', function (): void {
    bixSeedSales($this->tenant);
    $rossi = biCustomer($this->tenant, 'Neri');
    biInvoice($this->tenant, $rossi, '2027-03-09', '40.00', ['payment_status' => 'paid']);

    $pivot = $this->postJson('/api/v1/business-intelligence/explorer/run', [
        'dataset' => 'sales',
        'spec' => biSpec(['customer_country', 'payment_status'], ['net_amount', 'document_count', 'average_net_amount']),
        'pivot' => ['rows' => ['customer_country'], 'columns' => ['payment_status'], 'values' => ['net_amount', 'document_count', 'average_net_amount']],
    ])->assertOk()->json('data.pivot');

    $members = array_column($pivot['rows'], 'members');
    expect($members)->toBe([['CH'], ['IT']])
        ->and(array_column($pivot['columns'], 'members'))->toBe([['paid'], ['unpaid']])
        ->and($pivot['rows'][1]['cells']['1']['net_amount'])->toBe('450.00')
        ->and($pivot['rows'][1]['cells']['0']['net_amount'])->toBe('40.00')
        ->and($pivot['rows'][1]['totals']['net_amount'])->toBe('490.00')
        ->and($pivot['rows'][1]['totals']['document_count'])->toBe('4')
        ->and($pivot['grand_totals']['net_amount'])->toBe('510.00')
        ->and($pivot['column_totals']['1']['net_amount'])->toBe('470.00')
        ->and($pivot['grand_totals']['average_net_amount'])->toBeNull()
        ->and($pivot['rows'][1]['totals']['average_net_amount'])->toBeNull()
        ->and(array_column($pivot['values'], 'additive'))->toBe([true, true, false]);

    $this->postJson('/api/v1/business-intelligence/explorer/run', ['dataset' => 'sales', 'spec' => biSpec(['customer'], ['net_amount']), 'pivot' => ['rows' => ['customer'], 'columns' => ['customer'], 'values' => ['net_amount']]])
        ->assertUnprocessable()->assertJsonValidationErrors('pivot.columns.0');
    $this->postJson('/api/v1/business-intelligence/explorer/run', ['dataset' => 'sales', 'spec' => biSpec(['customer'], ['net_amount']), 'pivot' => ['rows' => ['customer']]])
        ->assertUnprocessable()->assertJsonValidationErrors('pivot.values');
    $this->postJson('/api/v1/business-intelligence/explorer/run', ['dataset' => 'sales', 'spec' => biSpec(['customer'], ['net_amount']), 'pivot' => ['values' => ['net_amount']]])
        ->assertUnprocessable()->assertJsonValidationErrors('pivot.rows');
});

test('a saved pivot keeps its layout and a snapshot schedule starts the day after', function (): void {
    bixSeedSales($this->tenant);
    $saved = bixQuery([
        'spec' => biSpec(['customer_country', 'customer'], ['net_amount']),
        'pivot' => ['rows' => ['customer_country'], 'columns' => ['customer'], 'values' => ['net_amount']],
        'snapshot_frequency' => 'weekly',
    ])->assertCreated()->json('data');

    expect($saved['pivot']['rows'])->toBe(['customer_country'])->and($saved['next_snapshot_on'])->toBe('2027-03-22');

    $run = $this->getJson("/api/v1/business-intelligence/saved-queries/{$saved['id']}/run")->assertOk()->json('data');
    expect($run['pivot']['grand_totals']['net_amount'])->toBe('470.00');

    $this->putJson("/api/v1/business-intelligence/saved-queries/{$saved['id']}", ['snapshot_frequency' => null])->assertOk()->assertJsonPath('data.next_snapshot_on', null);
    $this->putJson("/api/v1/business-intelligence/saved-queries/{$saved['id']}", ['pivot' => ['rows' => ['ghost'], 'values' => ['net_amount']]])->assertUnprocessable();
});

test('widgets take the first free place of the grid and never overlap or leave it', function (): void {
    $dashboard = bixDashboard();
    $first = bixWidget($dashboard['id'])->assertCreated()->json('data');
    $second = bixWidget($dashboard['id'])->assertCreated()->json('data');
    $third = bixWidget($dashboard['id'])->assertCreated()->json('data');

    expect($first['layout'])->toBe(['x' => 0, 'y' => 0, 'w' => 6, 'h' => 4])
        ->and($second['layout'])->toBe(['x' => 6, 'y' => 0, 'w' => 6, 'h' => 4])
        ->and($third['layout'])->toBe(['x' => 0, 'y' => 4, 'w' => 6, 'h' => 4]);

    bixWidget($dashboard['id'], ['layout' => ['x' => 3, 'y' => 1, 'w' => 4, 'h' => 2]])->assertUnprocessable()->assertJsonValidationErrors('layout');
    bixWidget($dashboard['id'], ['layout' => ['x' => 8, 'y' => 20, 'w' => 6, 'h' => 2]])->assertUnprocessable();
    bixWidget($dashboard['id'], ['layout' => ['x' => 6, 'y' => 4, 'w' => 6, 'h' => 2]])->assertCreated();

    $this->putJson("/api/v1/business-intelligence/widgets/{$second['id']}", ['layout' => ['x' => 0, 'y' => 0, 'w' => 6, 'h' => 4]])->assertUnprocessable();

    $this->putJson("/api/v1/business-intelligence/dashboards/{$dashboard['id']}/layout", ['layout' => [
        ['id' => $first['id'], 'x' => 6, 'y' => 0, 'w' => 6, 'h' => 4],
        ['id' => $second['id'], 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 4],
    ]])->assertOk();
    expect(BiDashboardWidget::query()->find($first['id'])->layout_x)->toBe(6)->and(BiDashboardWidget::query()->find($second['id'])->layout_x)->toBe(0);

    $this->putJson("/api/v1/business-intelligence/dashboards/{$dashboard['id']}/layout", ['layout' => [
        ['id' => $first['id'], 'x' => 0, 'y' => 0, 'w' => 12, 'h' => 4],
    ]])->assertUnprocessable();
    expect(BiDashboardWidget::query()->find($first['id'])->layout_x)->toBe(6);
});

test('a dashboard holds a limited number of widgets and a widget has exactly one source', function (): void {
    $dashboard = bixDashboard();
    $model = BiDashboard::query()->findOrFail($dashboard['id']);

    foreach (range(0, 29) as $index) {
        BiDashboardWidget::factory()->create(['tenant_id' => $this->tenant->id, 'dashboard_id' => $model->id, 'layout_x' => $index % 12, 'layout_y' => intdiv($index, 12) * 4, 'layout_w' => 1, 'layout_h' => 1]);
    }

    bixWidget($dashboard['id'])->assertUnprocessable()->assertJsonValidationErrors('widget_type');

    $other = bixDashboard(['name' => 'Second']);
    bixWidget($other['id'], ['dataset' => null, 'spec' => null])->assertUnprocessable()->assertJsonValidationErrors('saved_query_id');
    $queryId = bixQuery()->assertCreated()->json('data.id');
    bixWidget($other['id'], ['saved_query_id' => $queryId])->assertUnprocessable()->assertJsonValidationErrors('saved_query_id');
    bixWidget($other['id'], ['saved_query_id' => $queryId, 'dataset' => null, 'spec' => null])->assertCreated()->assertJsonPath('data.dataset', null);
    bixWidget($other['id'], ['widget_type' => 'radar'])->assertUnprocessable();
    bixWidget($other['id'], ['spec' => biSpec(['customer'], ['ghost'])])->assertUnprocessable();
});

test('the data of a dashboard applies its filters only to widgets whose dataset has the dimension', function (): void {
    bixSeedSales($this->tenant);
    $dashboard = bixDashboard(['filters' => ['filters' => [['field' => 'customer_country', 'operator' => 'eq', 'value' => 'IT']]]]);
    bixWidget($dashboard['id'], ['title' => 'Sales'])->assertCreated();
    bixWidget($dashboard['id'], ['title' => 'Stock', 'dataset' => 'inventory', 'spec' => biSpec(['sku'], ['quantity_on_hand'])])->assertCreated();

    $data = $this->getJson("/api/v1/business-intelligence/dashboards/{$dashboard['id']}/data")->assertOk()->json('data');
    expect($data)->toHaveCount(2)
        ->and(array_column($data[0]['result']['rows'], 'customer'))->toEqualCanonicalizing(['Bianchi', 'Rossi'])
        ->and($data[1]['status'])->toBe('ok');

    $viewer = $this->getJson("/api/v1/business-intelligence/dashboards/{$dashboard['id']}/data?filters[0][field]=customer_country&filters[0][operator]=eq&filters[0][value]=CH")->assertOk()->json('data');
    expect(array_column($viewer[0]['result']['rows'], 'customer'))->toBe(['Verdi']);

    $this->getJson("/api/v1/business-intelligence/dashboards/{$dashboard['id']}/data?filters[0][field]=customer_country&filters[0][operator]=drop&filters[0][value]=CH")->assertOk()
        ->assertJsonPath('data.0.status', 'error');

    $period = $this->getJson("/api/v1/business-intelligence/dashboards/{$dashboard['id']}/data?period[from]=2027-03-07&period[to]=2027-03-31")->assertOk()->json('data');
    expect(array_column($period[0]['result']['rows'], 'customer'))->toBe(['Bianchi']);
});

test('a widget drills down through its drill path one level at a time and refuses a path that does not fit', function (): void {
    bixSeedSales($this->tenant);
    $dashboard = bixDashboard();
    $widget = bixWidget($dashboard['id'], [
        'spec' => biSpec(['customer_country'], ['net_amount']),
        'config' => ['drill_path' => ['customer_country', 'customer', 'payment_status']],
    ])->assertCreated()->json('data');

    $top = $this->getJson("/api/v1/business-intelligence/widgets/{$widget['id']}/data")->assertOk()->json('data');
    expect(array_column($top['result']['rows'], 'customer_country'))->toBe(['CH', 'IT'])
        ->and($top['drill']['level'])->toBe(0)->and($top['drill']['next_dimension'])->toBe('customer');

    $url = "/api/v1/business-intelligence/widgets/{$widget['id']}/data";
    $level1 = $this->getJson($url.'?drill[0][dimension]=customer_country&drill[0][value]=IT')->assertOk()->json('data');
    expect(array_column($level1['result']['rows'], 'customer'))->toEqualCanonicalizing(['Bianchi', 'Rossi'])
        ->and($level1['drill']['current_dimension'])->toBe('customer')->and($level1['drill']['next_dimension'])->toBe('payment_status');

    $level2 = $this->getJson($url.'?drill[0][dimension]=customer_country&drill[0][value]=IT&drill[1][dimension]=customer&drill[1][value]=Rossi')->assertOk()->json('data');
    expect($level2['result']['rows'])->toHaveCount(1)->and($level2['result']['rows'][0]['payment_status'])->toBe('unpaid')->and($level2['result']['rows'][0]['net_amount'])->toBe('150.00')
        ->and($level2['drill']['next_dimension'])->toBeNull();

    $this->getJson($url.'?drill[0][dimension]=customer&drill[0][value]=Rossi')->assertUnprocessable()->assertJsonValidationErrors('drill.0.dimension');
    $this->getJson($url.'?drill[0][dimension]=customer_country&drill[0][value]=IT&drill[1][dimension]=customer&drill[1][value]=Rossi&drill[2][dimension]=payment_status&drill[2][value]=unpaid')
        ->assertUnprocessable()->assertJsonValidationErrors('drill');

    bixWidget($dashboard['id'], ['config' => ['drill_path' => ['date']]])->assertUnprocessable()->assertJsonValidationErrors('config.drill_path.0');
    bixWidget($dashboard['id'], ['config' => ['drill_path' => ['ghost']]])->assertUnprocessable();
    bixWidget($dashboard['id'], ['config' => ['drill_path' => ['customer', 'customer']]])->assertUnprocessable();
});

test('a widget of a dataset the viewer cannot read carries its error instead of failing the dashboard', function (): void {
    bixSeedSales($this->tenant);
    $dashboard = bixDashboard(['visibility' => 'company']);
    bixWidget($dashboard['id'], ['title' => 'Sales'])->assertCreated();
    bixWidget($dashboard['id'], ['title' => 'Headcount', 'dataset' => 'hr_headcount', 'spec' => biSpec(['employment_status'], ['employee_count'])])->assertCreated();

    bixActAs(biUser($this->tenant, 'accountant'), $this->tenant);
    $data = $this->getJson("/api/v1/business-intelligence/dashboards/{$dashboard['id']}/data")->assertOk()->json('data');

    expect(collect($data)->pluck('status')->sort()->values()->all())->toBe(['error', 'ok'])
        ->and(collect($data)->firstWhere('status', 'error')['result'])->toBeNull();
});

test('dashboards and queries are private until shared, and sharing follows the permission of each share', function (): void {
    $dashboard = bixDashboard();
    $widget = bixWidget($dashboard['id'])->assertCreated()->json('data');
    $query = bixQuery()->assertCreated()->json('data');
    $colleague = biUser($this->tenant, 'member');

    bixActAs($colleague, $this->tenant);
    $this->getJson('/api/v1/business-intelligence/dashboards')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/business-intelligence/dashboards/{$dashboard['id']}")->assertForbidden();
    $this->getJson("/api/v1/business-intelligence/saved-queries/{$query['id']}/run")->assertForbidden();
    $this->getJson("/api/v1/business-intelligence/widgets/{$widget['id']}/data")->assertForbidden();
    $this->getJson("/api/v1/business-intelligence/snapshots?source_type=saved_query&source_id={$query['id']}")->assertForbidden();

    bixActAs($this->admin, $this->tenant);
    $this->postJson('/api/v1/business-intelligence/shares', ['resource_type' => 'dashboard', 'resource_id' => $dashboard['id'], 'user_id' => $colleague->id, 'permission' => 'view'])
        ->assertCreated()->assertJsonPath('data.permission', 'view');
    $this->getJson("/api/v1/business-intelligence/dashboards/{$dashboard['id']}")->assertOk()->assertJsonPath('data.visibility', 'shared');

    bixActAs($colleague, $this->tenant);
    $this->getJson('/api/v1/business-intelligence/dashboards')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/v1/business-intelligence/dashboards/{$dashboard['id']}")->assertOk();
    $this->putJson("/api/v1/business-intelligence/dashboards/{$dashboard['id']}", ['name' => 'Mine'])->assertForbidden();
    bixWidget($dashboard['id'])->assertForbidden();
    $this->deleteJson("/api/v1/business-intelligence/widgets/{$widget['id']}")->assertForbidden();
    $this->postJson('/api/v1/business-intelligence/shares', ['resource_type' => 'dashboard', 'resource_id' => $dashboard['id'], 'user_id' => $colleague->id, 'permission' => 'edit'])->assertForbidden();
    $this->deleteJson("/api/v1/business-intelligence/dashboards/{$dashboard['id']}")->assertForbidden();

    bixActAs($this->admin, $this->tenant);
    $this->postJson('/api/v1/business-intelligence/shares', ['resource_type' => 'dashboard', 'resource_id' => $dashboard['id'], 'user_id' => $colleague->id, 'permission' => 'edit'])->assertCreated();
    $this->getJson("/api/v1/business-intelligence/shares?resource_type=dashboard&resource_id={$dashboard['id']}")->assertOk()->assertJsonCount(1, 'data');

    bixActAs($colleague, $this->tenant);
    $this->putJson("/api/v1/business-intelligence/dashboards/{$dashboard['id']}", ['name' => 'Renamed'])->assertOk()->assertJsonPath('data.name', 'Renamed');
    bixWidget($dashboard['id'])->assertCreated();
    $this->deleteJson("/api/v1/business-intelligence/dashboards/{$dashboard['id']}")->assertForbidden();
    $this->getJson("/api/v1/business-intelligence/shares?resource_type=dashboard&resource_id={$dashboard['id']}")->assertForbidden();

    bixActAs($this->admin, $this->tenant);
    $shareId = BiDashboard::query()->findOrFail($dashboard['id']) && \Modules\BusinessIntelligence\Models\BiShare::query()->where('resource_id', $dashboard['id'])->value('id');
    $this->deleteJson("/api/v1/business-intelligence/shares/{$shareId}")->assertNoContent();
    expect(BiDashboard::query()->find($dashboard['id'])->visibility)->toBe('private');

    $this->postJson('/api/v1/business-intelligence/shares', ['resource_type' => 'dashboard', 'resource_id' => $dashboard['id'], 'user_id' => $this->admin->id, 'permission' => 'view'])
        ->assertUnprocessable()->assertJsonValidationErrors('user_id');
    $this->postJson('/api/v1/business-intelligence/shares', ['resource_type' => 'saved_query', 'resource_id' => $query['id'], 'user_id' => (string) Illuminate\Support\Str::uuid(), 'permission' => 'view'])
        ->assertUnprocessable()->assertJsonValidationErrors('user_id');
});

test('a query shared with the company opens for every member and a read-only role cannot write', function (): void {
    $query = bixQuery(['visibility' => 'company'])->assertCreated()->json('data');
    $dashboard = bixDashboard(['visibility' => 'company']);

    bixActAs(biUser($this->tenant, 'accountant_readonly'), $this->tenant);
    $this->getJson("/api/v1/business-intelligence/saved-queries/{$query['id']}")->assertOk();
    $this->getJson("/api/v1/business-intelligence/dashboards/{$dashboard['id']}")->assertOk();
    $this->getJson('/api/v1/business-intelligence/overview')->assertOk()->assertJsonPath('data.dashboards', 1);
    $this->postJson('/api/v1/business-intelligence/dashboards', ['name' => 'Nope'])->assertForbidden();
    $this->putJson("/api/v1/business-intelligence/saved-queries/{$query['id']}", ['name' => 'Nope'])->assertForbidden();
    $this->deleteJson("/api/v1/business-intelligence/saved-queries/{$query['id']}")->assertForbidden();

    bixActAs(biUser($this->tenant, 'member'), $this->tenant);
    $this->putJson("/api/v1/business-intelligence/saved-queries/{$query['id']}", ['name' => 'Nope'])->assertForbidden();
});

test('nothing crosses the company boundary: records, shares and figures of another company stay out', function (): void {
    bixSeedSales($this->tenant);
    $other = biTenant('Beta');
    $stranger = biUser($other, 'admin');
    biInvoice($other, biCustomer($other, 'Stranger'), '2027-03-05', '9999.00');

    $query = bixQuery(['visibility' => 'company'])->assertCreated()->json('data');
    $dashboard = bixDashboard(['visibility' => 'company']);
    $measure = bixCalculated()->assertCreated()->json('data');
    $widget = bixWidget($dashboard['id'])->assertCreated()->json('data');

    bixActAs($stranger, $other);
    $this->getJson('/api/v1/business-intelligence/saved-queries')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/business-intelligence/dashboards')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/business-intelligence/saved-queries/{$query['id']}")->assertNotFound();
    $this->getJson("/api/v1/business-intelligence/dashboards/{$dashboard['id']}/data")->assertNotFound();
    $this->putJson("/api/v1/business-intelligence/calculated-measures/{$measure['id']}", ['label' => 'Hacked'])->assertNotFound();
    $this->deleteJson("/api/v1/business-intelligence/widgets/{$widget['id']}")->assertNotFound();
    $this->postJson('/api/v1/business-intelligence/shares', ['resource_type' => 'dashboard', 'resource_id' => $dashboard['id'], 'user_id' => $stranger->id, 'permission' => 'edit'])->assertNotFound();
    $this->postJson("/api/v1/business-intelligence/dashboards/{$dashboard['id']}/widgets", ['title' => 'x', 'widget_type' => 'table', 'dataset' => 'sales', 'spec' => biSpec(['customer'], ['net_amount'])])->assertNotFound();
    $this->postJson('/api/v1/business-intelligence/dashboards', ['name' => 'Beta board'])->assertCreated();
    $this->postJson('/api/v1/business-intelligence/saved-queries', ['name' => 'Cross', 'dataset' => 'sales', 'spec' => biSpec(['customer'], ['net_amount'])])->assertCreated();
    $figures = $this->postJson('/api/v1/business-intelligence/explorer/run', ['dataset' => 'sales', 'spec' => biSpec(['customer'], ['net_amount'])])->assertOk()->json('data.result.rows');
    expect(array_column($figures, 'customer'))->toBe(['Stranger']);

    bixActAs($this->admin, $this->tenant);
    $mine = $this->getJson("/api/v1/business-intelligence/saved-queries/{$query['id']}/run")->assertOk()->json('data.result.rows');
    expect(array_column($mine, 'customer'))->not->toContain('Stranger');
});

test('a row-level security rule narrows every query of the user through the same validation', function (): void {
    bixSeedSales($this->tenant);
    $euroOnly = new class implements RowLevelSecurityRule
    {
        public function appliesTo(Dataset $dataset): bool
        {
            return $dataset->key === 'sales';
        }

        public function filtersFor(User $user, Dataset $dataset): array
        {
            return [['field' => 'customer_country', 'operator' => 'eq', 'value' => 'IT']];
        }
    };

    $before = $this->postJson('/api/v1/business-intelligence/explorer/run', ['dataset' => 'sales', 'spec' => biSpec([], ['net_amount'])])->assertOk()->json('data.result.rows.0.net_amount');
    expect($before)->toBe('470.00');

    app(RowLevelSecurityRegistry::class)->register($euroOnly);
    $after = $this->postJson('/api/v1/business-intelligence/explorer/run', ['dataset' => 'sales', 'spec' => biSpec([], ['net_amount'])])->assertOk()->json('data.result.rows.0.net_amount');
    expect($after)->toBe('450.00');

    $dashboard = bixDashboard();
    bixWidget($dashboard['id'], ['spec' => biSpec(['customer'], ['net_amount'])])->assertCreated();
    $rows = $this->getJson("/api/v1/business-intelligence/dashboards/{$dashboard['id']}/data")->assertOk()->json('data.0.result.rows');
    expect(array_column($rows, 'customer'))->not->toContain('Verdi');

    $broken = new class implements RowLevelSecurityRule
    {
        public function appliesTo(Dataset $dataset): bool
        {
            return true;
        }

        public function filtersFor(User $user, Dataset $dataset): array
        {
            return [['field' => 'not_a_dimension', 'operator' => 'eq', 'value' => 'x']];
        }
    };
    app(RowLevelSecurityRegistry::class)->register($broken);
    $this->postJson('/api/v1/business-intelligence/explorer/run', ['dataset' => 'sales', 'spec' => biSpec([], ['net_amount'])])->assertUnprocessable();
});

test('a snapshot freezes the figures of the day and compares them with today', function (): void {
    bixSeedSales($this->tenant);
    $query = bixQuery()->assertCreated()->json('data');

    $snapshot = $this->postJson('/api/v1/business-intelligence/snapshots', ['source_type' => 'saved_query', 'source_id' => $query['id']])->assertCreated()->assertJsonPath('data.row_count', 3)->json('data');
    expect($snapshot)->not->toHaveKey('payload');

    biInvoice($this->tenant, biCustomer($this->tenant, 'Gialli'), '2027-03-10', '10.00');
    biInvoice($this->tenant, \App\Models\Contact::query()->where('company_name', 'Rossi')->firstOrFail(), '2027-03-11', '25.00');

    $list = $this->getJson("/api/v1/business-intelligence/snapshots?source_type=saved_query&source_id={$query['id']}")->assertOk()->json('data');
    expect($list)->toHaveCount(1)->and($list[0])->not->toHaveKey('payload');
    $detail = $this->getJson("/api/v1/business-intelligence/snapshots/{$snapshot['id']}")->assertOk()->json('data.payload');
    expect(array_column($detail['rows'], 'customer'))->toBe(['Bianchi', 'Rossi', 'Verdi']);

    $comparison = $this->getJson("/api/v1/business-intelligence/snapshots/{$snapshot['id']}/compare")->assertOk()->json('data');
    $byCustomer = collect($comparison['rows'])->keyBy('customer');
    expect($byCustomer['Rossi']['net_amount'])->toBe(['snapshot' => '150.00', 'current' => '175.00', 'change' => '25.00'])
        ->and($byCustomer['Gialli']['net_amount'])->toBe(['snapshot' => null, 'current' => '10.00', 'change' => null])
        ->and($byCustomer['Bianchi']['net_amount']['change'])->toBe('0.00');

    $this->deleteJson("/api/v1/business-intelligence/snapshots/{$snapshot['id']}")->assertNoContent();
});

test('a dashboard snapshot stores the figures of every widget and cannot be compared with today', function (): void {
    bixSeedSales($this->tenant);
    $dashboard = bixDashboard();
    bixWidget($dashboard['id'])->assertCreated();
    bixWidget($dashboard['id'], ['dataset' => 'inventory', 'spec' => biSpec(['sku'], ['quantity_on_hand'])])->assertCreated();

    $snapshot = $this->postJson('/api/v1/business-intelligence/snapshots', ['source_type' => 'dashboard', 'source_id' => $dashboard['id']])->assertCreated()->json('data');
    $payload = $this->getJson("/api/v1/business-intelligence/snapshots/{$snapshot['id']}")->assertOk()->json('data.payload');

    expect($payload['widgets'])->toHaveCount(2)->and(array_column($payload['widgets'], 'title'))->toBe(['Net by customer', 'Net by customer']);
    $this->getJson("/api/v1/business-intelligence/snapshots/{$snapshot['id']}/compare")->assertUnprocessable();
});

test('scheduled snapshots run once a day with the permissions of the owner and a failure moves the schedule on', function (): void {
    bixSeedSales($this->tenant);
    $good = bixQuery(['snapshot_frequency' => 'daily'])->assertCreated()->json('data');
    $leaver = biUser($this->tenant, 'member');
    bixActAs($leaver, $this->tenant);
    $orphan = bixQuery(['name' => 'Leaver query', 'snapshot_frequency' => 'monthly'])->assertCreated()->json('data');
    $this->tenant->users()->detach($leaver->id);
    bixActAs($this->admin, $this->tenant);

    Carbon::setTestNow('2027-03-16 06:00:00');
    $service = app(SnapshotService::class);
    $inTenant = fn (callable $callback): mixed => app(TenantContext::class)->runWithTenant($this->tenant, $callback);
    expect($inTenant(fn (): array => $service->runDue()))->toBe(['succeeded' => 1, 'failed' => 0])
        ->and(BiSavedQuery::query()->find($good['id'])->next_snapshot_on->toDateString())->toBe('2027-03-17');
    $this->tenant->users()->attach($leaver->id, ['id' => (string) Illuminate\Support\Str::uuid(), 'role' => 'member']);
    $this->tenant->users()->detach($leaver->id);

    BiSavedQuery::query()->whereKey($orphan['id'])->update(['next_snapshot_on' => '2027-03-16']);
    $outcome = $inTenant(fn (): array => $service->runDue());
    $failed = BiSnapshot::query()->where('source_id', $orphan['id'])->get();

    expect($outcome['failed'])->toBe(1)->and($failed)->toHaveCount(1)->and($failed[0]->status)->toBe('failed')->and($failed[0]->trigger)->toBe('scheduled')
        ->and(BiSavedQuery::query()->find($orphan['id'])->next_snapshot_on->toDateString())->toBe('2027-04-16')
        ->and(BiSnapshot::query()->where('source_id', $good['id'])->count())->toBe(1);

    BiSavedQuery::query()->whereKey($good['id'])->update(['next_snapshot_on' => '2027-03-16']);
    expect($inTenant(fn (): array => $service->runDue())['succeeded'])->toBe(1)->and(BiSnapshot::query()->where('source_id', $good['id'])->count())->toBe(1);

    $this->artisan('business-intelligence:run-snapshots', ['--tenant' => [$this->tenant->id]])->assertSuccessful();
});

test('only the most recent snapshots of a source are kept', function (): void {
    config(['business_intelligence.snapshots_keep' => 2]);
    bixSeedSales($this->tenant);
    $query = bixQuery()->assertCreated()->json('data');

    foreach (range(1, 4) as $day) {
        Carbon::setTestNow("2027-03-1{$day} 10:00:00");
        $this->postJson('/api/v1/business-intelligence/snapshots', ['source_type' => 'saved_query', 'source_id' => $query['id']])->assertCreated();
    }

    $kept = BiSnapshot::query()->where('source_id', $query['id'])->orderBy('taken_on')->pluck('taken_on')->map(fn ($date) => $date->toDateString())->all();
    expect($kept)->toBe(['2027-03-13', '2027-03-14']);
});

test('the overview counts what the caller can open and the module routes are documented in the openapi catalog', function (): void {
    bixQuery(['snapshot_frequency' => 'daily'])->assertCreated();
    bixDashboard();
    bixCalculated()->assertCreated();

    $this->getJson('/api/v1/business-intelligence/overview')->assertOk()
        ->assertJsonPath('data.dashboards', 1)->assertJsonPath('data.saved_queries', 1)->assertJsonPath('data.scheduled_queries', 1)->assertJsonPath('data.calculated_measures', 1);

    $this->getJson('/api/v1/business-intelligence/datasets/sales')->assertOk()->assertJsonPath('data.key', 'sales');
    $this->getJson('/api/v1/business-intelligence/datasets/ghost')->assertNotFound();
});
