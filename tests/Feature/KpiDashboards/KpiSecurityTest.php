<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/KpiTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    kpiDeferForeignKeys();
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/kpi-dashboards/cockpit')->assertUnauthorized();
    $this->getJson('/api/v1/kpi-dashboards/drill-down/dso')->assertUnauthorized();
});

it('opens a cockpit only for the roles allowed to see it', function (): void {
    $tenant = kpiTenant();
    kpiActAs($tenant, 'sales');

    kpiCockpit($this, 'sales')->assertOk();
    kpiCockpit($this, 'cfo')->assertForbidden()->assertJsonPath('title', 'Forbidden KPI Access');
    kpiCockpit($this, 'ceo')->assertForbidden();
    kpiCockpit($this, 'hr')->assertForbidden();
});

it('lets finance read the CFO cockpit but not the HR cockpit, and owners every cockpit', function (): void {
    $tenant = kpiTenant();
    kpiActAs($tenant, 'accountant');

    kpiCockpit($this, 'cfo')->assertOk();
    kpiCockpit($this, 'hr')->assertForbidden();

    kpiActAs($tenant, 'owner');
    foreach (['ceo', 'cfo', 'sales', 'operations', 'hr'] as $role) {
        kpiCockpit($this, $role)->assertOk();
    }
});

it('opens a cockpit for a member granted its permission and closes it for a denied one', function (): void {
    $tenant = kpiTenant();
    kpiActAs($tenant, 'member', ['kpi.cockpit.hr']);
    kpiCockpit($this, 'hr')->assertOk();
    kpiCockpit($this, 'cfo')->assertForbidden();

    kpiActAs($tenant, 'accountant', ['deny:kpi_dashboards']);
    kpiCockpit($this, 'cfo')->assertForbidden();
});

it('masks overtime cost for a viewer without the compensation grant and never serves it from another viewer\'s cache', function (): void {
    $tenant = kpiTenant();
    $employee = kpiEmployee($tenant->id, '2020-01-01');
    kpiTimesheet($tenant->id, $employee, '2026-02-02', 8, 5, 6, 40);

    kpiActAs($tenant, 'owner');
    $full = kpiWidget(kpiCockpit($this, 'hr')->assertOk(), 'overtime_trend');
    expect($full['data'])->toMatchArray(['masked' => false, 'hours' => 5.0, 'cost' => 200.0]);

    // Same tenant, same filters, a member who may open the HR cockpit but has no compensation grant.
    kpiActAs($tenant, 'member', ['kpi.cockpit.hr']);
    $masked = kpiWidget(kpiCockpit($this, 'hr')->assertOk(), 'overtime_trend');
    expect($masked['data']['masked'])->toBeTrue()
        ->and($masked['data']['cost'])->toBeNull()
        ->and($masked['data']['hours'])->toEqual(5.0)
        ->and(array_column($masked['data']['series'], 'cost'))->toEqual([null]);

    // The grant unmasks it.
    kpiActAs($tenant, 'member', ['kpi.cockpit.hr', 'kpi.hr.compensation']);
    expect(kpiWidget(kpiCockpit($this, 'hr')->assertOk(), 'overtime_trend')['data']['cost'])->toEqual(200.0);
});

it('restricts the legal entities of a member by attribute', function (): void {
    $tenant = kpiTenant();
    $a = kpiCompany($tenant, 'CH01');
    $b = kpiCompany($tenant, 'IT01', 'EUR');
    $revenue = kpiAccount($tenant, '4000', 'revenue');
    $cash = kpiAccount($tenant, '1000', 'asset', 'cash');
    kpiPost($tenant, '2026-03-01', [[$cash, 1000, 0], [$revenue, 0, 1000]], $a);
    kpiPost($tenant, '2026-03-02', [[$cash, 7000, 0], [$revenue, 0, 7000]], $b);

    kpiActAs($tenant, 'accountant', ["kpi.entity:{$a}"]);

    // Asking for everything, or for the other entity, never reveals the entity outside the grant.
    kpiActAs($tenant, 'sales_manager', ['kpi.cockpit.ceo', "kpi.entity:{$a}"]);
    expect(kpiWidget(kpiCockpit($this, 'ceo')->assertOk(), 'revenue_ytd_vs_budget')['value'])->toEqual(1000.0)
        ->and(kpiWidget(kpiCockpit($this, 'ceo', ['company_ids' => [$b]])->assertOk(), 'revenue_ytd_vs_budget')['value'])->toEqual(0.0)
        ->and(kpiWidget(kpiCockpit($this, 'ceo', ['company_ids' => [$a, $b]])->assertOk(), 'revenue_ytd_vs_budget')['value'])->toEqual(1000.0);

    // An owner is not restricted.
    kpiActAs($tenant, 'owner');
    expect(kpiWidget(kpiCockpit($this, 'ceo')->assertOk(), 'revenue_ytd_vs_budget')['value'])->toEqual(8000.0);
});

it('applies the same role rules to the drill-down', function (): void {
    $tenant = kpiTenant();
    kpiActAs($tenant, 'sales');

    $this->getJson('/api/v1/kpi-dashboards/drill-down/dso?'.http_build_query(['preset' => 'custom', 'from' => '2026-01-01', 'to' => '2026-06-30']))
        ->assertForbidden();
    $this->getJson('/api/v1/kpi-dashboards/drill-down/win_rate?'.http_build_query(['preset' => 'custom', 'from' => '2026-01-01', 'to' => '2026-06-30']))
        ->assertOk();
    $this->getJson('/api/v1/kpi-dashboards/drill-down/not_a_kpi')->assertNotFound();
});

it('never returns compensation fields in a timesheet drill-down without the grant', function (): void {
    $tenant = kpiTenant();
    $employee = kpiEmployee($tenant->id, '2020-01-01');
    kpiTimesheet($tenant->id, $employee, '2026-02-02', 8, 5, 6, 40);
    $query = http_build_query(['preset' => 'custom', 'from' => '2026-01-01', 'to' => '2026-06-30']);

    kpiActAs($tenant, 'member', ['kpi.cockpit.hr']);
    $masked = $this->getJson("/api/v1/kpi-dashboards/drill-down/overtime_trend?{$query}")->assertOk();
    expect($masked->json('data.rows.0.hourly_cost_rate'))->toBeNull()
        ->and($masked->json('data.rows.0.total_labor_cost'))->toBeNull()
        ->and($masked->json('data.rows.0.overtime_hours'))->toEqual(5.0);

    kpiActAs($tenant, 'owner');
    $open = $this->getJson("/api/v1/kpi-dashboards/drill-down/overtime_trend?{$query}")->assertOk();
    expect($open->json('data.rows.0.hourly_cost_rate'))->toEqual(40.0);
});

it('isolates tenants in the drill-down', function (): void {
    $mine = kpiTenant();
    $other = kpiTenant();
    $customer = kpiContact($other);
    kpiDashInvoice($other, $customer, '2026-03-01', 1000, ['due_date' => '2026-03-01']);
    kpiActAs($mine, 'accountant');

    $response = $this->getJson('/api/v1/kpi-dashboards/drill-down/dso?'.http_build_query(['preset' => 'custom', 'from' => '2026-01-01', 'to' => '2026-06-30']))->assertOk();

    expect($response->json('data.rows'))->toBe([])->and($response->json('data.pagination.total'))->toBe(0);
});

it('keeps threshold and refresh administration to finance and managers', function (): void {
    $tenant = kpiTenant();
    kpiActAs($tenant, 'sales');

    $this->postJson('/api/v1/kpi-dashboards/cubes/refresh')->assertForbidden();
    $this->postJson('/api/v1/kpi-dashboards/alerts', ['kpi_code' => 'dso', 'operator' => '>', 'warning_value' => 60])->assertForbidden();
    $this->getJson('/api/v1/kpi-dashboards/alerts')->assertForbidden();

    kpiActAs($tenant, 'accountant');
    $this->postJson('/api/v1/kpi-dashboards/alerts', ['kpi_code' => 'dso', 'operator' => '>', 'warning_value' => 60])->assertOk();
});
