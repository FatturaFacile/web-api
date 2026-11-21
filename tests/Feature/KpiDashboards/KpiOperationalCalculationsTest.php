<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/KpiTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    kpiDeferForeignKeys();
});

it('computes the weighted pipeline by stage and the win rate of the sales cockpit', function (): void {
    $tenant = kpiTenant();
    $qualified = (string) Str::uuid();
    $proposal = (string) Str::uuid();
    foreach ([[$qualified, 'QUAL', 'Qualified', 1, 25], [$proposal, 'PROP', 'Proposal', 2, 60]] as [$id, $code, $name, $sequence, $probability]) {
        DB::table('crm_opportunity_stages')->insert(['id' => $id, 'tenant_id' => $tenant->id, 'code' => $code, 'name' => $name, 'sequence' => $sequence, 'default_probability' => $probability, 'created_at' => now(), 'updated_at' => now()]);
    }

    $opportunity = static function (string $stage, float $amount, string $status, ?float $probability = null, ?string $closed = null) use ($tenant): void {
        DB::table('crm_opportunities')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'account_id' => (string) Str::uuid(), 'stage_id' => $stage, 'opportunity_number' => 'OPP-'.Str::random(6), 'name' => 'Deal',
            'amount' => $amount, 'currency' => 'CHF', 'probability' => $probability ?? 0, 'status' => $status, 'expected_close_date' => '2026-09-01', 'actual_close_date' => $closed,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $opportunity($qualified, 10000, 'open', 25);
    $opportunity($proposal, 20000, 'open', 50);
    $opportunity($proposal, 5000, 'won', 100, '2026-03-10');
    $opportunity($proposal, 8000, 'won', 100, '2026-05-01');
    $opportunity($qualified, 5000, 'lost', 0, '2026-04-10');
    kpiActAs($tenant, 'sales_manager');

    $response = kpiCockpit($this, 'sales')->assertOk();

    $pipeline = kpiWidget($response, 'weighted_pipeline');
    expect($pipeline['widget_type'])->toBe('funnel')
        ->and($pipeline['value'])->toEqual(12500.0)
        ->and(array_column($pipeline['data']['stages'], 'stage'))->toEqual(['Qualified', 'Proposal'])
        ->and(array_column($pipeline['data']['stages'], 'weighted'))->toEqual([2500.0, 10000.0]);

    expect(kpiWidget($response, 'win_rate')['value'])->toEqual(66.67)
        ->and(kpiWidget($response, 'win_rate')['data'])->toMatchArray(['won' => 2, 'lost' => 1]);
});

it('computes gross margin and the top customers and products with margin', function (): void {
    $tenant = kpiTenant();
    $revenue = kpiAccount($tenant, '4000', 'revenue');
    $cogs = kpiAccount($tenant, '5000', 'cogs');
    $cash = kpiAccount($tenant, '1000', 'asset', 'cash');
    kpiPost($tenant, '2026-03-01', [[$cash, 100000, 0], [$revenue, 0, 100000]]);
    kpiPost($tenant, '2026-03-02', [[$cogs, 60000, 0], [$cash, 0, 60000]]);

    $product = (string) Str::uuid();
    DB::table('products')->insert(['id' => $product, 'tenant_id' => $tenant->id, 'sku' => 'SKU1', 'name' => 'Widget', 'cost_price' => 10, 'created_at' => now(), 'updated_at' => now()]);
    $customerA = kpiContact($tenant, 'Alfa AG');
    $customerB = kpiContact($tenant, 'Beta AG');
    foreach ([[$customerA, 100, 5000], [$customerB, 10, 2000]] as [$customer, $quantity, $total]) {
        $invoice = kpiInvoice($tenant, $customer, '2026-03-05', (float) $total);
        DB::table('invoice_items')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'invoice_id' => $invoice, 'product_id' => $product, 'description' => 'Widget', 'quantity' => $quantity, 'unit_price' => $total / $quantity, 'line_total' => $total, 'created_at' => now(), 'updated_at' => now()]);
    }
    kpiActAs($tenant, 'sales');

    $response = kpiCockpit($this, 'sales')->assertOk();

    expect(kpiWidget($response, 'gross_margin')['value'])->toEqual(40.0);

    $customers = kpiWidget($response, 'top_customers');
    expect($customers['widget_type'])->toBe('top_n_table')
        ->and(array_column($customers['data']['rows'], 'name'))->toEqual(['Alfa AG', 'Beta AG'])
        ->and(array_column($customers['data']['rows'], 'revenue'))->toEqual([5000.0, 2000.0])
        ->and(array_column($customers['data']['rows'], 'margin_percent'))->toEqual([80.0, 95.0]);

    $products = kpiWidget($response, 'top_products');
    expect($products['data']['rows'])->toHaveCount(1)
        ->and($products['data']['rows'][0]['revenue'])->toEqual(7000.0)
        ->and($products['data']['rows'][0]['margin_percent'])->toEqual(84.29);
});

it('computes inventory turnover, OTIF, OEE and scrap rate of the operations cockpit', function (): void {
    $tenant = kpiTenant();
    $cogs = kpiAccount($tenant, '5000', 'cogs');
    $inventory = kpiAccount($tenant, '1200', 'asset', 'inventory');
    $equity = kpiAccount($tenant, '2800', 'equity', 'equity');
    kpiPost($tenant, '2025-12-01', [[$inventory, 10000, 0], [$equity, 0, 10000]]);
    kpiPost($tenant, '2026-03-01', [[$inventory, 10000, 0], [$equity, 0, 10000]]);
    kpiPost($tenant, '2026-04-01', [[$cogs, 60000, 0], [$equity, 0, 60000]]);

    // Opening inventory 10,000, closing 20,000: average 15,000; COGS 60,000 over 181 days, annualized.
    $expectedTurnover = round(60000 / 15000 * (365 / 181), 2);

    $order = static function (string $due, float $delivered, string $updated) use ($tenant): void {
        DB::table('sales_orders')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'contact_id' => (string) Str::uuid(), 'order_number' => 'SO-'.Str::random(6), 'date' => '2026-02-01', 'status' => 'confirmed',
            'requested_delivery_date' => $due, 'delivered_percentage' => $delivered, 'created_at' => $updated, 'updated_at' => $updated,
        ]);
    };
    $order('2026-03-10', 100, '2026-03-01');   // on time, in full
    $order('2026-03-10', 100, '2026-03-20');   // in full but late
    $order('2026-03-10', 50, '2026-03-05');    // short

    DB::table('mfg_production_orders')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'order_number' => 'PO-1', 'mfg_item_id' => (string) Str::uuid(), 'bom_id' => (string) Str::uuid(), 'status' => 'closed',
        'target_quantity' => 100, 'completed_quantity' => 90, 'scrapped_quantity' => 9, 'planned_start_date' => '2026-03-01', 'planned_end_date' => '2026-03-04',
        'actual_start_date' => '2026-03-01', 'actual_end_date' => '2026-03-05', 'created_at' => now(), 'updated_at' => now(),
    ]);
    kpiActAs($tenant, 'production');

    $response = kpiCockpit($this, 'operations')->assertOk();

    expect(kpiWidget($response, 'inventory_turnover')['value'])->toEqual($expectedTurnover);

    $otif = kpiWidget($response, 'otif');
    expect($otif['value'])->toEqual(33.33)
        ->and($otif['data'])->toMatchArray(['orders' => 3, 'on_time_in_full' => 1, 'late' => 1, 'short' => 1])
        ->and($otif['widget_type'])->toBe('gauge');

    // Availability 72h planned over 96h actual = 75%, performance 90%, quality 81/90 = 90%: OEE 60.75%.
    $oee = kpiWidget($response, 'oee');
    expect($oee['value'])->toEqual(60.75)
        ->and($oee['data']['factors'])->toEqual(['availability' => 75.0, 'performance' => 90.0, 'quality' => 90.0])
        ->and(array_column($oee['data']['waterfall'], 'step'))->toEqual(['available_time', 'availability_loss', 'performance_loss', 'quality_loss', 'oee']);

    expect(kpiWidget($response, 'scrap_rate')['value'])->toEqual(10.0)
        ->and(kpiWidget($response, 'scrap_rate')['data']['series'][0])->toEqual(['period' => '2026-03', 'rate' => 10.0]);
});

it('computes FTE headcount for the CEO cockpit and turnover for the HR cockpit', function (): void {
    $tenant = kpiTenant();
    kpiEmployee($tenant->id, '2020-01-01', 1.0);
    kpiEmployee($tenant->id, '2025-06-01', 0.5);
    kpiEmployee($tenant->id, '2020-01-01', 1.0, ['termination_date' => '2026-03-15', 'employment_status' => 'terminated']);
    kpiActAs($tenant, 'owner');

    $ceo = kpiWidget(kpiCockpit($this, 'ceo')->assertOk(), 'headcount_fte');
    expect($ceo['value'])->toEqual(1.5)->and($ceo['data']['breakdown']['headcount'])->toBe(2);

    // Opening headcount 3, closing 2, one leaver: 1 / 2.5 = 40%.
    $hr = kpiCockpit($this, 'hr')->assertOk();
    expect(kpiWidget($hr, 'headcount_turnover')['value'])->toEqual(40.0)
        ->and(kpiWidget($hr, 'headcount_turnover')['data'])->toMatchArray(['leavers' => 1, 'opening_headcount' => 3, 'closing_headcount' => 2])
        ->and(kpiWidget($hr, 'headcount_fte')['value'])->toEqual(1.5);
});

it('computes absenteeism from approved sick leave and billable utilization by team', function (): void {
    $tenant = kpiTenant();
    $teamA = (string) Str::uuid();
    $teamB = (string) Str::uuid();
    $e1 = kpiEmployee($tenant->id, '2020-01-01', 1.0, ['team_id' => $teamA]);
    $e2 = kpiEmployee($tenant->id, '2024-01-01', 0.5, ['team_id' => $teamB]);

    $sick = (string) Str::uuid();
    $vacation = (string) Str::uuid();
    DB::table('hr_leave_types')->insert(['id' => $sick, 'tenant_id' => $tenant->id, 'code' => 'SICK', 'name' => 'Sick', 'category' => 'sick', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('hr_leave_types')->insert(['id' => $vacation, 'tenant_id' => $tenant->id, 'code' => 'VAC', 'name' => 'Vacation', 'category' => 'vacation', 'created_at' => now(), 'updated_at' => now()]);
    foreach ([[$sick, 'approved', 16], [$vacation, 'approved', 80], [$sick, 'pending', 8]] as [$type, $status, $hours]) {
        DB::table('hr_leave_requests')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'employee_id' => $e1, 'leave_type_id' => $type, 'request_number' => 'LR-'.Str::random(6), 'start_date' => '2026-02-10', 'end_date' => '2026-02-11', 'total_hours' => $hours, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
    }

    kpiTimesheet($tenant->id, $e1, '2026-03-02', 8, 0, 6);
    kpiTimesheet($tenant->id, $e1, '2026-03-03', 8, 0, 8);
    kpiTimesheet($tenant->id, $e2, '2026-03-02', 8, 0, 2);
    kpiTimesheet($tenant->id, $e2, '2026-03-03', 8, 0, 2);
    kpiTimesheet($tenant->id, $e2, '2026-03-04', 8, 0, 8, 40, ['status' => 'draft']);
    kpiActAs($tenant, 'owner');

    $workingDays = 0;
    for ($day = CarbonImmutable::parse('2026-01-01'); $day <= CarbonImmutable::parse('2026-06-30'); $day = $day->addDay()) {
        $workingDays += $day->isWeekend() ? 0 : 1;
    }

    $response = kpiCockpit($this, 'hr')->assertOk();

    // 16 approved sick hours over 1.5 FTE × 8 h × working days.
    $absenteeism = kpiWidget($response, 'absenteeism');
    expect($absenteeism['value'])->toEqual(round(16 / (1.5 * 8 * $workingDays) * 100, 2))
        ->and($absenteeism['data']['absence_hours'])->toEqual(16.0)
        ->and($absenteeism['data']['series'])->toHaveCount(6)
        ->and($absenteeism['data']['heatmap'][0])->toMatchArray(['period' => '2026-02', 'hours' => 16.0]);

    // Billable 18 of 32 approved hours; the draft timesheet is ignored.
    $utilization = kpiWidget($response, 'billable_utilization');
    expect($utilization['value'])->toEqual(56.25)
        ->and($utilization['data']['teams'][0])->toMatchArray(['team_id' => $teamA, 'rate' => 87.5])
        ->and($utilization['data']['teams'][1])->toMatchArray(['team_id' => $teamB, 'rate' => 25.0]);
});

it('computes overtime hours and cost, and the backlog of confirmed orders', function (): void {
    $tenant = kpiTenant();
    $employee = kpiEmployee($tenant->id, '2020-01-01');
    kpiTimesheet($tenant->id, $employee, '2026-02-02', 8, 5, 6, 40);
    kpiTimesheet($tenant->id, $employee, '2026-03-02', 8, 3, 6, 50);
    kpiTimesheet($tenant->id, $employee, '2026-03-03', 8, 9, 6, 50, ['status' => 'draft']);

    $order = (string) Str::uuid();
    DB::table('sales_orders')->insert(['id' => $order, 'tenant_id' => $tenant->id, 'contact_id' => (string) Str::uuid(), 'order_number' => 'SO-1', 'date' => '2026-02-01', 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('sales_order_items')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'sales_order_id' => $order, 'description' => 'Item', 'quantity' => 10, 'quantity_delivered' => 4, 'unit_price' => 100, 'discount_percentage' => 0, 'created_at' => now(), 'updated_at' => now()]);
    $cancelled = (string) Str::uuid();
    DB::table('sales_orders')->insert(['id' => $cancelled, 'tenant_id' => $tenant->id, 'contact_id' => (string) Str::uuid(), 'order_number' => 'SO-2', 'date' => '2026-02-01', 'status' => 'cancelled', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('sales_order_items')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'sales_order_id' => $cancelled, 'description' => 'Item', 'quantity' => 99, 'quantity_delivered' => 0, 'unit_price' => 100, 'discount_percentage' => 0, 'created_at' => now(), 'updated_at' => now()]);
    kpiActAs($tenant, 'owner');

    $overtime = kpiWidget(kpiCockpit($this, 'hr')->assertOk(), 'overtime_trend');
    expect($overtime['value'])->toEqual(8.0)
        ->and($overtime['data'])->toMatchArray(['masked' => false, 'hours' => 8.0, 'cost' => 350.0])
        ->and($overtime['data']['series'])->toHaveCount(2);

    $backlog = kpiWidget(kpiCockpit($this, 'ceo')->assertOk(), 'order_backlog');
    expect($backlog['value'])->toEqual(600.0)->and($backlog['data']['series'][0])->toEqual(['period' => '2026-02', 'value' => 600.0]);
});
