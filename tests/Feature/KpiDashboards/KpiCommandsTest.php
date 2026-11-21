<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\KpiDashboards\Infrastructure\Services\KpiCache;

require_once __DIR__.'/KpiTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    kpiDeferForeignKeys();
});

/**
 * @param  list<array<string, mixed>>  $widgets
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function kpiLayoutBody(array $widgets, array $extra = []): array
{
    return ['refresh_interval_seconds' => 120, 'widgets' => $widgets, ...$extra];
}

it('clones a system cockpit into a custom layout and leaves the system default untouched', function (): void {
    $tenant = kpiTenant();
    kpiActAs($tenant, 'owner');
    $default = kpiCockpit($this, 'cfo')->assertOk();
    $systemId = $default->json('data.dashboard.id');

    expect($default->json('data.dashboard'))->toMatchArray(['is_system_default' => true, 'version' => 1, 'title_key' => 'KPI_DASHBOARDS.DASHBOARD_CFO'])
        ->and($default->json('data.widgets'))->toHaveCount(6);

    $saved = $this->postJson('/api/v1/kpi-dashboards/layout', kpiLayoutBody([
        ['kpi_code' => 'cash_runway', 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 3, 'widget_type' => 'area_trend'],
        ['kpi_code' => 'dso', 'x' => 6, 'y' => 0, 'w' => 6, 'h' => 3, 'default_time_comparison' => 'mom'],
    ], ['dashboard_id' => $systemId, 'custom_title' => 'My CFO view']))->assertOk();

    expect($saved->json('data.id'))->not->toBe($systemId)
        ->and($saved->json('data'))->toMatchArray(['role_code' => 'cfo', 'is_system_default' => false, 'custom_title' => 'My CFO view', 'refresh_interval_seconds' => 120])
        ->and($saved->json('data.widgets'))->toHaveCount(2)
        ->and($saved->json('data.widgets.0.widget_type'))->toBe('area_trend')
        ->and($saved->json('data.widgets.1.default_time_comparison'))->toBe('mom');

    // The cockpit of the role now serves the user's own layout; another member still gets the system default.
    $mine = kpiCockpit($this, 'cfo')->assertOk();
    expect($mine->json('data.dashboard.id'))->toBe($saved->json('data.id'))->and($mine->json('data.widgets'))->toHaveCount(2);

    kpiActAs($tenant, 'accountant');
    $other = kpiCockpit($this, 'cfo')->assertOk();
    expect($other->json('data.dashboard.id'))->toBe($systemId)->and($other->json('data.widgets'))->toHaveCount(6);
    expect(DB::table('kpi_dashboards')->where('id', $systemId)->value('version'))->toEqual(1);
});

it('updates the custom layout in place, bumps its version and rejects a stale version', function (): void {
    $tenant = kpiTenant();
    kpiActAs($tenant, 'owner');
    $first = $this->postJson('/api/v1/kpi-dashboards/layout', kpiLayoutBody([['kpi_code' => 'ebitda', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3]], ['role' => 'custom']))->assertOk();
    $id = $first->json('data.id');
    expect($first->json('data'))->toMatchArray(['role_code' => 'custom', 'version' => 1, 'title_key' => 'KPI_DASHBOARDS.DASHBOARD_CUSTOM']);

    $second = $this->postJson('/api/v1/kpi-dashboards/layout', kpiLayoutBody([
        ['kpi_code' => 'ebitda', 'x' => 4, 'y' => 0, 'w' => 4, 'h' => 3],
        ['kpi_code' => 'net_financial_position', 'x' => 8, 'y' => 0, 'w' => 4, 'h' => 3],
    ], ['dashboard_id' => $id, 'role' => 'custom', 'expected_version' => 1]))->assertOk();

    expect($second->json('data.id'))->toBe($id)->and($second->json('data.version'))->toBe(2)->and($second->json('data.widgets'))->toHaveCount(2);
    expect(DB::table('kpi_widgets')->where('dashboard_id', $id)->whereNull('deleted_at')->count())->toBe(2);

    $this->postJson('/api/v1/kpi-dashboards/layout', kpiLayoutBody([['kpi_code' => 'ebitda', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3]], ['dashboard_id' => $id, 'role' => 'custom', 'expected_version' => 1]))
        ->assertUnprocessable();

    // "My Custom Cockpit" renders with its widgets.
    $cockpit = kpiCockpit($this, 'custom')->assertOk();
    expect($cockpit->json('data.widgets'))->toHaveCount(2);
});

it('rejects an invalid layout', function (): void {
    $tenant = kpiTenant();
    kpiActAs($tenant, 'owner');
    $widget = ['kpi_code' => 'ebitda', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3];

    $this->postJson('/api/v1/kpi-dashboards/layout', kpiLayoutBody([$widget], ['refresh_interval_seconds' => 10]))->assertUnprocessable()->assertJsonValidationErrors('refresh_interval_seconds');
    $this->postJson('/api/v1/kpi-dashboards/layout', kpiLayoutBody([]))->assertUnprocessable();
    $this->postJson('/api/v1/kpi-dashboards/layout', kpiLayoutBody([['kpi_code' => 'nope', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3]]))->assertUnprocessable();
    // Wider than the 12-column grid: x 10 + w 4.
    $this->postJson('/api/v1/kpi-dashboards/layout', kpiLayoutBody([[...$widget, 'x' => 10, 'w' => 4]]))->assertUnprocessable();
    // The same KPI twice.
    $this->postJson('/api/v1/kpi-dashboards/layout', kpiLayoutBody([$widget, [...$widget, 'x' => 4]]))->assertUnprocessable();
});

it('refuses to place a KPI the member may not see', function (): void {
    $tenant = kpiTenant();
    kpiActAs($tenant, 'sales');

    $this->postJson('/api/v1/kpi-dashboards/layout', kpiLayoutBody([['kpi_code' => 'dso', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3]]))->assertForbidden();
    $this->postJson('/api/v1/kpi-dashboards/layout', kpiLayoutBody([['kpi_code' => 'win_rate', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3]]))->assertOk();
});

it('lists the KPI catalog for the widget picker', function (): void {
    $tenant = kpiTenant();
    kpiActAs($tenant, 'member');

    $catalog = collect($this->getJson('/api/v1/kpi-dashboards/catalog')->assertOk()->json('data'));

    expect($catalog)->toHaveCount(24)
        ->and($catalog->firstWhere('kpi_code', 'net_financial_position'))->toMatchArray(['is_stock' => true, 'is_monetary' => true, 'widget_type' => 'metric_card'])
        ->and($catalog->firstWhere('kpi_code', 'ebitda')['is_stock'])->toBeFalse()
        ->and($catalog->pluck('widget_type')->unique()->sort()->values()->all())->toEqual(['area_trend', 'funnel', 'gauge', 'heatmap', 'metric_card', 'top_n_table', 'waterfall_chart']);
});

/**
 * Cash 100,000 and a monthly burn of 20,000: a runway of 5 months.
 */
function kpiRunwayCompany(): Tenant
{
    $tenant = kpiTenant();
    $opex = kpiAccount($tenant, '6000', 'expense');
    $cash = kpiAccount($tenant, '1000', 'asset', 'cash');
    kpiPost($tenant, '2026-04-15', [[$opex, 60000, 0], [$cash, 0, 60000]]);
    DB::table('bank_account_balances')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'bank_account_id' => (string) Str::uuid(), 'balance_date' => '2026-06-29', 'ledger_balance' => 100000, 'available_balance' => 100000, 'currency' => 'CHF', 'created_at' => now(), 'updated_at' => now()]);

    return $tenant;
}

it('raises a threshold alert once per cooldown and deduplicates repeated breaches', function (): void {
    $tenant = kpiRunwayCompany();
    kpiActAs($tenant, 'accountant');

    $rule = $this->postJson('/api/v1/kpi-dashboards/alerts', ['kpi_code' => 'cash_runway', 'operator' => '<', 'warning_value' => 6, 'critical_value' => 3, 'channels' => ['in_app', 'push'], 'cooldown_minutes' => 60])->assertOk();
    expect($rule->json('data'))->toMatchArray(['kpi_code' => 'cash_runway', 'operator' => '<', 'cooldown_minutes' => 60, 'is_active' => true])
        ->and($rule->json('data.channels'))->toEqual(['in_app', 'push']);

    $widget = kpiWidget(kpiCockpit($this, 'cfo')->assertOk(), 'cash_runway');
    expect($widget['value'])->toEqual(5.0)
        ->and($widget['alerts'])->toHaveCount(1)
        ->and($widget['alerts'][0])->toMatchArray(['severity' => 'warning', 'observed_value' => 5.0, 'threshold_value' => 6.0]);

    // The cockpit is recomputed (cubes refreshed) but the cooldown suppresses a second alert of the same severity.
    kpiRefreshCubes($this);
    expect(kpiWidget(kpiCockpit($this, 'cfo')->assertOk(), 'cash_runway')['alerts'])->toBe([]);

    $log = $this->getJson('/api/v1/kpi-dashboards/alerts')->assertOk();
    expect($log->json('data.alerts'))->toHaveCount(1)
        ->and($log->json('data.alerts.0.deep_link'))->toBe('fatturafacile://kpi/cfo/cash_runway?drill=1')
        ->and($log->json('data.alerts.0.channels'))->toEqual(['in_app', 'push'])
        ->and($log->json('data.rules'))->toHaveCount(1);

    // A worse severity is not a repeat: burn rises to a 2-month runway and raises the critical alert.
    $opex = DB::table('chart_of_accounts')->where('tenant_id', $tenant->id)->where('code', '6000')->value('id');
    $cash = DB::table('chart_of_accounts')->where('tenant_id', $tenant->id)->where('code', '1000')->value('id');
    kpiPost($tenant, '2026-05-15', [[$opex, 90000, 0], [$cash, 0, 90000]]);
    kpiRefreshCubes($this);
    $critical = kpiWidget(kpiCockpit($this, 'cfo')->assertOk(), 'cash_runway');
    expect($critical['alerts'])->toHaveCount(1)->and($critical['alerts'][0]['severity'])->toBe('critical');

    $this->postJson('/api/v1/kpi-dashboards/alerts/'.$log->json('data.alerts.0.id').'/acknowledge')->assertOk()->assertJsonPath('data.status', 'acknowledged');
    $this->postJson('/api/v1/kpi-dashboards/alerts/'.Str::uuid().'/acknowledge')->assertNotFound();
});

it('raises a new alert once the cooldown has passed', function (): void {
    $tenant = kpiRunwayCompany();
    kpiActAs($tenant, 'accountant');
    $this->postJson('/api/v1/kpi-dashboards/alerts', ['kpi_code' => 'cash_runway', 'operator' => '<', 'warning_value' => 6, 'cooldown_minutes' => 30])->assertOk();

    expect(kpiWidget(kpiCockpit($this, 'cfo')->assertOk(), 'cash_runway')['alerts'])->toHaveCount(1);

    DB::table('kpi_threshold_alert_logs')->update(['cooldown_until' => now()->subMinute()]);
    kpiRefreshCubes($this);

    expect(kpiWidget(kpiCockpit($this, 'cfo')->assertOk(), 'cash_runway')['alerts'])->toHaveCount(1)
        ->and(DB::table('kpi_threshold_alert_logs')->count())->toBe(2);
});

it('does not alert for an inactive rule or a value within bounds', function (): void {
    $tenant = kpiRunwayCompany();
    kpiActAs($tenant, 'accountant');
    $this->postJson('/api/v1/kpi-dashboards/alerts', ['kpi_code' => 'cash_runway', 'operator' => '<', 'warning_value' => 6, 'is_active' => false])->assertOk();
    $this->postJson('/api/v1/kpi-dashboards/alerts', ['kpi_code' => 'cash_runway', 'operator' => '<', 'warning_value' => 4])->assertOk();

    expect(kpiWidget(kpiCockpit($this, 'cfo')->assertOk(), 'cash_runway')['alerts'])->toBe([])
        ->and(DB::table('kpi_threshold_alert_logs')->count())->toBe(0);
});

it('validates threshold rules', function (): void {
    $tenant = kpiTenant();
    kpiActAs($tenant, 'accountant');

    $this->postJson('/api/v1/kpi-dashboards/alerts', ['kpi_code' => 'dso', 'operator' => '>'])->assertUnprocessable();
    $this->postJson('/api/v1/kpi-dashboards/alerts', ['kpi_code' => 'dso', 'operator' => '=', 'warning_value' => 60])->assertUnprocessable();
    // "<": the critical boundary must be lower than the warning one; ">": higher.
    $this->postJson('/api/v1/kpi-dashboards/alerts', ['kpi_code' => 'cash_runway', 'operator' => '<', 'warning_value' => 3, 'critical_value' => 6])->assertUnprocessable();
    $this->postJson('/api/v1/kpi-dashboards/alerts', ['kpi_code' => 'dso', 'operator' => '>', 'warning_value' => 60, 'critical_value' => 45])->assertUnprocessable();
    $this->postJson('/api/v1/kpi-dashboards/alerts', ['kpi_code' => 'dso', 'operator' => '>', 'warning_value' => 60, 'critical_value' => 90])->assertOk();

    // Updating a rule by id changes it instead of adding another.
    $id = DB::table('kpi_threshold_rules')->value('id');
    $this->postJson('/api/v1/kpi-dashboards/alerts', ['rule_id' => $id, 'kpi_code' => 'dso', 'operator' => '>=', 'warning_value' => 70])->assertOk();
    expect(DB::table('kpi_threshold_rules')->count())->toBe(1)->and(DB::table('kpi_threshold_rules')->value('operator'))->toBe('>=');
});

function kpiRefreshCubes(object $test): void
{
    // The refresh is debounced and the cache holds the debounce key: flushing it makes each call of a test really invalidate.
    Cache::flush();
    $test->postJson('/api/v1/kpi-dashboards/cubes/refresh')->assertOk();
}

it('serves a cached cockpit until the cubes are refreshed, and debounces refresh bursts', function (): void {
    $tenant = kpiTenant();
    $a = ['revenue' => kpiAccount($tenant, '4000', 'revenue'), 'cash' => kpiAccount($tenant, '1000', 'asset', 'cash')];
    kpiPost($tenant, '2026-03-01', [[$a['cash'], 1000, 0], [$a['revenue'], 0, 1000]]);
    kpiActAs($tenant, 'owner');

    expect(kpiWidget(kpiCockpit($this, 'ceo')->assertOk(), 'revenue_ytd_vs_budget')['value'])->toEqual(1000.0);

    // A raw insert bypasses the model events, so the cached payload is still served.
    kpiPost($tenant, '2026-03-02', [[$a['cash'], 500, 0], [$a['revenue'], 0, 500]]);
    expect(kpiWidget(kpiCockpit($this, 'ceo')->assertOk(), 'revenue_ytd_vs_budget')['value'])->toEqual(1000.0);

    $first = $this->postJson('/api/v1/kpi-dashboards/cubes/refresh')->assertOk();
    $second = $this->postJson('/api/v1/kpi-dashboards/cubes/refresh')->assertOk();
    expect($first->json('data'))->toMatchArray(['refreshed' => true, 'debounced' => false])
        ->and($second->json('data'))->toMatchArray(['refreshed' => false, 'debounced' => true])
        ->and($second->json('data.cache_version'))->toBe($first->json('data.cache_version'));

    expect(kpiWidget(kpiCockpit($this, 'ceo')->assertOk(), 'revenue_ytd_vs_budget')['value'])->toEqual(1500.0);
});

it('invalidates only the cache of the tenant whose document changed', function (): void {
    $mine = kpiTenant();
    $other = kpiTenant();
    $cache = app(KpiCache::class);
    $before = [$cache->version((string) $mine->id), $cache->version((string) $other->id)];

    $invoice = new Invoice;
    $invoice->setAttribute('tenant_id', (string) $mine->id);
    Event::dispatch('eloquent.saved: '.Invoice::class, [$invoice]);

    expect($cache->version((string) $mine->id))->toBe($before[0] + 1)
        ->and($cache->version((string) $other->id))->toBe($before[1]);
});

it('drills a revenue month down to the journal entries behind it', function (): void {
    $tenant = kpiTenant();
    $revenue = kpiAccount($tenant, '4000', 'revenue');
    $cash = kpiAccount($tenant, '1000', 'asset', 'cash');
    $feb = kpiPost($tenant, '2026-02-10', [[$cash, 700, 0], [$revenue, 0, 700]]);
    kpiPost($tenant, '2026-03-10', [[$cash, 300, 0], [$revenue, 0, 300]]);
    kpiActAs($tenant, 'owner');

    $response = $this->getJson('/api/v1/kpi-dashboards/drill-down/revenue_ytd_vs_budget?'.http_build_query(['preset' => 'custom', 'from' => '2026-01-01', 'to' => '2026-06-30', 'period' => '2026-02']))->assertOk();

    expect($response->json('data.document_type'))->toBe('journal_entries')
        ->and($response->json('data.rows'))->toHaveCount(1)
        ->and($response->json('data.rows.0.id'))->toBe($feb)
        ->and($response->json('data.rows.0.credit'))->toEqual(700.0)
        ->and($response->json('data.breadcrumb'))->toEqual(['CEO', 'revenue_ytd_vs_budget', 'period: 2026-02', 'currency: CHF'])
        ->and($response->json('data.deep_link.route'))->toBe('/general-ledger/journal-entries')
        ->and($response->json('data.deep_link.query.period'))->toBe('2026-02');
});

it('drills an aging bucket down to the open invoices and paginates', function (): void {
    $tenant = kpiTenant();
    $customer = kpiContact($tenant);
    $late = kpiDashInvoice($tenant, $customer, '2026-05-01', 20000, ['total_tax' => 0, 'due_date' => '2026-06-15']);
    kpiDashInvoice($tenant, $customer, '2026-01-10', 4000, ['total_tax' => 0, 'due_date' => '2026-01-20']);
    kpiDashInvoice($tenant, $customer, '2026-02-01', 60000, ['total_tax' => 0, 'due_date' => '2026-02-15', 'payment_status' => 'paid', 'total_paid' => 60000]);
    kpiActAs($tenant, 'accountant');
    $base = ['preset' => 'custom', 'from' => '2026-01-01', 'to' => '2026-06-30'];

    $bucket = $this->getJson('/api/v1/kpi-dashboards/drill-down/ar_ap_aging?'.http_build_query([...$base, 'bucket' => '1_30']))->assertOk();
    expect($bucket->json('data.rows'))->toHaveCount(1)->and($bucket->json('data.rows.0.id'))->toBe($late)
        ->and($bucket->json('data.deep_link.route'))->toBe('/invoicing/invoices')
        ->and($bucket->json('data.deep_link.query.bucket'))->toBe('1_30');

    $over = $this->getJson('/api/v1/kpi-dashboards/drill-down/ar_ap_aging?'.http_build_query([...$base, 'bucket' => 'over_90']))->assertOk();
    expect($over->json('data.rows'))->toHaveCount(1)->and($over->json('data.rows.0.total_net'))->toEqual(4000.0);

    $all = $this->getJson('/api/v1/kpi-dashboards/drill-down/dso?'.http_build_query([...$base, 'per_page' => 1, 'page' => 2]))->assertOk();
    expect($all->json('data.pagination'))->toEqual(['page' => 2, 'per_page' => 1, 'total' => 2, 'last_page' => 2])->and($all->json('data.rows'))->toHaveCount(1);
});

it('drills the payables side and the pipeline stages', function (): void {
    $tenant = kpiTenant();
    $supplier = kpiContact($tenant, 'Lieferant AG');
    $open = kpiPassiveInvoice($tenant, $supplier, '2026-06-01', 12000, ['total_tax' => 0, 'due_date' => '2026-07-01']);
    $stage = (string) Str::uuid();
    DB::table('crm_opportunity_stages')->insert(['id' => $stage, 'tenant_id' => $tenant->id, 'code' => 'Q', 'name' => 'Qualified', 'sequence' => 1, 'default_probability' => 25, 'created_at' => now(), 'updated_at' => now()]);
    $opportunity = (string) Str::uuid();
    DB::table('crm_opportunities')->insert(['id' => $opportunity, 'tenant_id' => $tenant->id, 'account_id' => (string) Str::uuid(), 'stage_id' => $stage, 'opportunity_number' => 'OPP-1', 'name' => 'Deal', 'amount' => 9000, 'currency' => 'CHF', 'probability' => 25, 'status' => 'open', 'expected_close_date' => '2026-09-01', 'created_at' => now(), 'updated_at' => now()]);
    kpiActAs($tenant, 'owner');
    $base = ['preset' => 'custom', 'from' => '2026-01-01', 'to' => '2026-06-30'];

    $ap = $this->getJson('/api/v1/kpi-dashboards/drill-down/ar_ap_aging?'.http_build_query([...$base, 'side' => 'payable', 'bucket' => 'current']))->assertOk();
    expect($ap->json('data.document_type'))->toBe('invoices')->and($ap->json('data.rows.0.id'))->toBe($open)->and($ap->json('data.deep_link.route'))->toBe('/procurement/vendor-invoices');

    $pipeline = $this->getJson('/api/v1/kpi-dashboards/drill-down/weighted_pipeline?'.http_build_query([...$base, 'stage_id' => $stage]))->assertOk();
    expect($pipeline->json('data.rows.0.id'))->toBe($opportunity)
        ->and($this->getJson('/api/v1/kpi-dashboards/drill-down/weighted_pipeline?'.http_build_query([...$base, 'stage_id' => (string) Str::uuid()]))->json('data.rows'))->toBe([]);
});

it('exports a drill-down as CSV without formula injection', function (): void {
    $tenant = kpiTenant();
    $customer = kpiContact($tenant);
    kpiDashInvoice($tenant, $customer, '2026-05-01', 20000, ['total_tax' => 0, 'due_date' => '2026-06-15', 'invoice_number' => '=HYPERLINK("http://evil")']);
    kpiActAs($tenant, 'accountant');

    $response = $this->get('/api/v1/kpi-dashboards/drill-down/dso?'.http_build_query(['preset' => 'custom', 'from' => '2026-01-01', 'to' => '2026-06-30', 'export' => 'csv']), ['Accept' => 'text/csv'])->assertOk();
    $csv = $response->streamedContent();

    expect($response->headers->get('Content-Type'))->toContain('text/csv')
        ->and($csv)->toStartWith('invoice_number,date,due_date')
        ->and($csv)->toContain("\"'=HYPERLINK(\"\"http://evil\"\")\"")
        ->and($csv)->not->toContain(',=HYPERLINK');
});
