<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Database\Seeders\ManufacturingDemoDataSeeder;
use Database\Seeders\ManufacturingReferenceSeeder;
use Database\Seeders\ManufacturingTestingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgBomComponent;
use Modules\Manufacturing\Models\MfgDemandForecast;
use Modules\Manufacturing\Models\MfgEngineeringChangeOrder;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgLaborTimeLog;
use Modules\Manufacturing\Models\MfgMachineCenter;
use Modules\Manufacturing\Models\MfgMachineLog;
use Modules\Manufacturing\Models\MfgMrpPegging;
use Modules\Manufacturing\Models\MfgMrpPlannedOrder;
use Modules\Manufacturing\Models\MfgMrpRun;
use Modules\Manufacturing\Models\MfgMrpShortage;
use Modules\Manufacturing\Models\MfgOperation;
use Modules\Manufacturing\Models\MfgProductionCost;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderLine;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use Modules\Manufacturing\Models\MfgProductionVersion;
use Modules\Manufacturing\Models\MfgQualityCharacteristic;
use Modules\Manufacturing\Models\MfgQualityInspection;
use Modules\Manufacturing\Models\MfgQualityNonConformance;
use Modules\Manufacturing\Models\MfgQualityPlan;
use Modules\Manufacturing\Models\MfgRouting;
use Modules\Manufacturing\Models\MfgShopFloorExecution;
use Modules\Manufacturing\Models\MfgWorkCenter;
use Modules\Manufacturing\Models\MfgWorkCenterCapacity;

require_once __DIR__.'/ManufacturingSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    mfgSetUp($this);
});

/**
 * Every write route of the module, with path parameters.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function mfgWriteRoutes(): array
{
    $id = '01970000-0000-7000-8000-000000000001';

    return collect([
        ['POST', '/items'], ['PUT', "/items/{$id}"], ['POST', "/items/{$id}/cost-rollup"],
        ['POST', '/work-centers'], ['PUT', "/work-centers/{$id}"], ['PUT', "/work-centers/{$id}/calendar"], ['POST', "/work-centers/{$id}/calendar-exceptions"],
        ['POST', '/boms'], ['PUT', "/boms/{$id}"], ['POST', "/boms/{$id}/revise"], ['POST', "/boms/{$id}/activate"],
        ['POST', '/routings'],
        ['POST', '/engineering-changes'], ['POST', "/engineering-changes/{$id}/submit"], ['POST', "/engineering-changes/{$id}/approve"], ['POST', "/engineering-changes/{$id}/reject"], ['POST', "/engineering-changes/{$id}/implement"],
        ['POST', '/production-orders'], ['POST', "/production-orders/{$id}/reschedule"], ['POST', "/production-orders/{$id}/release"], ['POST', "/production-orders/{$id}/start"], ['POST', "/production-orders/{$id}/complete"], ['POST', "/production-orders/{$id}/close"], ['POST', "/production-orders/{$id}/cancel"],
        ['POST', "/production-orders/{$id}/issues"], ['POST', "/production-orders/{$id}/operations/{$id}/confirmations"], ['POST', "/production-orders/{$id}/receipts"], ['POST', "/production-orders/{$id}/subcontract-orders"],
        ['POST', "/subcontract-orders/{$id}/receive"], ['POST', "/subcontract-orders/{$id}/cancel"],
        ['POST', '/mrp/runs'], ['POST', "/mrp/planned-orders/{$id}/convert"], ['POST', "/mrp/planned-orders/{$id}/procurement-link"], ['POST', '/mrp/forecasts'], ['DELETE', "/mrp/forecasts/{$id}"],
        ['POST', '/shop-floor/executions'], ['POST', "/shop-floor/executions/{$id}/complete"], ['POST', "/shop-floor/executions/{$id}/downtime"],
        ['POST', '/quality/plans'], ['POST', '/quality/inspections'], ['POST', '/quality/non-conformances'], ['POST', "/quality/non-conformances/{$id}/resolve"],
        ['PUT', '/settings/accounting'],
    ])->mapWithKeys(static fn (array $route): array => [$route[0].' '.$route[1] => $route])->all();
}

test('read-only roles cannot call any write route of the module', function (string $method, string $path): void {
    mfgActAs($this, $this->reader);

    $this->json($method, '/api/v1/manufacturing'.$path, [])->assertForbidden();
})->with(mfgWriteRoutes());

test('read-only roles read the module', function (): void {
    mfgActAs($this, $this->reader);

    foreach (['/items', '/work-centers', '/boms', '/routings', '/engineering-changes', '/production-orders', '/subcontract-orders', '/mrp/runs', '/mrp/planned-orders', '/mrp/purchase-suggestions', '/mrp/shortages', '/mrp/forecasts', '/shop-floor/executions', '/quality/plans', '/quality/inspections', '/quality/non-conformances', '/settings/accounting'] as $path) {
        $this->getJson('/api/v1/manufacturing'.$path)->assertOk();
    }
});

test('create endpoints validate their payload', function (string $path, array $fields): void {
    $this->postJson('/api/v1/manufacturing'.$path, [])->assertUnprocessable()->assertJsonValidationErrors($fields);
})->with([
    'items' => ['/items', ['item_code', 'name', 'item_type']],
    'work centres' => ['/work-centers', ['work_center_code', 'name']],
    'boms' => ['/boms', ['mfg_item_id', 'name', 'components']],
    'routings' => ['/routings', ['mfg_item_id', 'name', 'operations']],
    'engineering changes' => ['/engineering-changes', ['bom_id', 'title', 'reason', 'components']],
    'production orders' => ['/production-orders', ['mfg_item_id', 'target_quantity', 'planned_start_date', 'planned_end_date']],
    'mrp runs' => ['/mrp/runs', ['horizon_start_date', 'horizon_end_date']],
    'forecasts' => ['/mrp/forecasts', ['mfg_item_id', 'forecast_date', 'quantity']],
    'job cards' => ['/shop-floor/executions', ['production_order_operation_id']],
    'quality plans' => ['/quality/plans', ['plan_code', 'name', 'inspection_type']],
    'inspections' => ['/quality/inspections', ['quality_plan_id', 'inspection_point']],
    'non-conformances' => ['/quality/non-conformances', ['severity', 'defect_type', 'description']],
]);

test('records of another company are invisible: lists skip them, reads and actions answer 404', function (): void {
    $records = app(TenantContext::class)->runWithTenant($this->otherTenant, function (): array {
        $tenantId = $this->otherTenant->id;
        $item = MfgItem::factory()->create(['tenant_id' => $tenantId]);

        return [
            'item' => $item,
            'work_center' => MfgWorkCenter::factory()->create(['tenant_id' => $tenantId]),
            'bom' => MfgBom::factory()->create(['tenant_id' => $tenantId, 'mfg_item_id' => $item->id, 'status' => 'draft']),
            'routing' => MfgRouting::factory()->create(['tenant_id' => $tenantId]),
            'eco' => MfgEngineeringChangeOrder::factory()->create(['tenant_id' => $tenantId, 'status' => 'submitted']),
            'order' => MfgProductionOrder::factory()->create(['tenant_id' => $tenantId, 'status' => 'planned']),
            'run' => MfgMrpRun::factory()->create(['tenant_id' => $tenantId]),
            'planned' => MfgMrpPlannedOrder::factory()->create(['tenant_id' => $tenantId, 'status' => 'planned', 'order_type' => 'buy']),
            'forecast' => MfgDemandForecast::create(['tenant_id' => $tenantId, 'mfg_item_id' => $item->id, 'forecast_date' => now()->toDateString(), 'quantity' => '1']),
            'nc' => MfgQualityNonConformance::factory()->create(['tenant_id' => $tenantId, 'status' => 'open']),
            'job' => MfgShopFloorExecution::factory()->create(['tenant_id' => $tenantId, 'status' => 'active']),
        ];
    });

    foreach (['/items', '/work-centers', '/boms', '/routings', '/engineering-changes', '/production-orders', '/mrp/runs', '/mrp/planned-orders', '/mrp/forecasts', '/quality/non-conformances', '/shop-floor/executions'] as $path) {
        $this->getJson('/api/v1/manufacturing'.$path)->assertOk()->assertJsonCount(0, 'data');
    }

    $this->getJson("/api/v1/manufacturing/items/{$records['item']->id}")->assertNotFound();
    $this->putJson("/api/v1/manufacturing/items/{$records['item']->id}", ['name' => 'x'])->assertNotFound();
    $this->postJson("/api/v1/manufacturing/items/{$records['item']->id}/cost-rollup")->assertNotFound();
    $this->getJson("/api/v1/manufacturing/items/{$records['item']->id}/where-used")->assertNotFound();
    $this->getJson("/api/v1/manufacturing/work-centers/{$records['work_center']->id}")->assertNotFound();
    $this->getJson("/api/v1/manufacturing/work-centers/{$records['work_center']->id}/capacity?from=2027-01-01&to=2027-01-02")->assertNotFound();
    $this->putJson("/api/v1/manufacturing/work-centers/{$records['work_center']->id}/calendar", ['days' => [['weekday' => 1, 'available_hours' => '8']]])->assertNotFound();
    $this->getJson("/api/v1/manufacturing/boms/{$records['bom']->id}")->assertNotFound();
    $this->getJson("/api/v1/manufacturing/boms/{$records['bom']->id}/explode")->assertNotFound();
    $this->postJson("/api/v1/manufacturing/boms/{$records['bom']->id}/activate")->assertNotFound();
    $this->getJson("/api/v1/manufacturing/routings/{$records['routing']->id}")->assertNotFound();
    $this->getJson("/api/v1/manufacturing/engineering-changes/{$records['eco']->id}")->assertNotFound();
    $this->postJson("/api/v1/manufacturing/engineering-changes/{$records['eco']->id}/approve")->assertNotFound();
    $this->getJson("/api/v1/manufacturing/production-orders/{$records['order']->id}")->assertNotFound();
    $this->postJson("/api/v1/manufacturing/production-orders/{$records['order']->id}/release")->assertNotFound();
    $this->postJson("/api/v1/manufacturing/production-orders/{$records['order']->id}/cancel", ['reason' => 'x'])->assertNotFound();
    $this->getJson("/api/v1/manufacturing/production-orders/{$records['order']->id}/cost-rollup")->assertNotFound();
    $this->getJson("/api/v1/manufacturing/production-orders/{$records['order']->id}/transactions")->assertNotFound();
    $this->getJson("/api/v1/manufacturing/mrp/runs/{$records['run']->id}")->assertNotFound();
    $this->postJson("/api/v1/manufacturing/mrp/planned-orders/{$records['planned']->id}/procurement-link", ['procurement_document_type' => 'purchase_order', 'procurement_document_id' => (string) Str::uuid7()])->assertNotFound();
    $this->deleteJson("/api/v1/manufacturing/mrp/forecasts/{$records['forecast']->id}")->assertNotFound();
    $this->postJson("/api/v1/manufacturing/quality/non-conformances/{$records['nc']->id}/resolve", ['disposition' => 'scrap', 'corrective_action' => 'x'])->assertNotFound();
    $this->postJson("/api/v1/manufacturing/shop-floor/executions/{$records['job']->id}/downtime", ['downtime_minutes' => '1', 'reason' => 'x'])->assertNotFound();
    $this->postJson('/api/v1/manufacturing/production-orders', ['mfg_item_id' => $records['item']->id, 'target_quantity' => '1', 'planned_start_date' => now()->toDateString(), 'planned_end_date' => now()->toDateString()])
        ->assertUnprocessable()->assertJsonValidationErrors(['mfg_item_id']);
});

test('ledger accounts are configured by owners and administrators and used by the next postings', function (): void {
    $this->getJson('/api/v1/manufacturing/settings/accounting')->assertOk()
        ->assertJsonPath('data.wip_account_code', '1210')
        ->assertJsonPath('data.raw_material_account_code', '1200')
        ->assertJsonPath('data.subcontract_accrual_account_code', '2010');

    mfgActAs($this, $this->production);
    $this->putJson('/api/v1/manufacturing/settings/accounting', ['wip_account_code' => '1299'])->assertForbidden();

    mfgActAs($this, $this->owner);
    $this->putJson('/api/v1/manufacturing/settings/accounting', ['wip_account_code' => 'not valid!'])->assertUnprocessable()->assertJsonValidationErrors(['wip_account_code']);
    $this->putJson('/api/v1/manufacturing/settings/accounting', ['wip_account_code' => '1299', 'labor_absorbed_account_code' => '7810'])
        ->assertOk()->assertJsonPath('data.wip_account_code', '1299')->assertJsonPath('data.labor_absorbed_account_code', '7810')->assertJsonPath('data.overhead_absorbed_account_code', '5820');

    $product = mfgProductSetUp($this);
    $orderId = $this->postJson('/api/v1/manufacturing/production-orders', ['mfg_item_id' => $product['fg']->id, 'target_quantity' => '1', 'planned_start_date' => now()->toDateString(), 'planned_end_date' => now()->toDateString()])->json('data.id');
    $lines = collect($this->postJson("/api/v1/manufacturing/production-orders/{$orderId}/release")->json('data.lines'))->keyBy('mfg_item_id');
    $this->postJson("/api/v1/manufacturing/production-orders/{$orderId}/issues", ['lines' => [['line_id' => $lines[$product['steel']->id]['id'], 'quantity' => '2']]])->assertCreated();

    expect(mfgLedgerBalance((string) $this->tenant->id, '1299'))->toBe('20.00')
        ->and(mfgLedgerBalance((string) $this->tenant->id, '1210'))->toBe('0.00');

    $this->putJson('/api/v1/manufacturing/settings/accounting', ['wip_account_code' => null])->assertOk()->assertJsonPath('data.wip_account_code', '1210');
});

test('Manufacturing multilingual seeders run idempotently', function (): void {
    $this->seed(ManufacturingReferenceSeeder::class);
    $this->seed(ManufacturingTestingSeeder::class);
    $this->seed(ManufacturingDemoDataSeeder::class);

    $this->seed(ManufacturingReferenceSeeder::class);
    $this->seed(ManufacturingTestingSeeder::class);
    $this->seed(ManufacturingDemoDataSeeder::class);

    expect(MfgItem::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBeGreaterThan(0)
        ->and(MfgProductionOrder::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBeLessThanOrEqual(2);
});

test('all 25 manufacturing factories instantiate valid persisted models with relational integrity', function (): void {
    $tenantId = $this->tenant->id;
    $factories = [
        MfgItem::class, MfgBom::class, MfgBomComponent::class, MfgEngineeringChangeOrder::class, MfgWorkCenter::class, MfgMachineCenter::class, MfgRouting::class, MfgOperation::class,
        MfgProductionVersion::class, MfgMrpRun::class, MfgMrpPlannedOrder::class, MfgMrpShortage::class, MfgMrpPegging::class, MfgProductionOrder::class, MfgProductionOrderLine::class,
        MfgProductionOrderOperation::class, MfgShopFloorExecution::class, MfgLaborTimeLog::class, MfgMachineLog::class, MfgQualityPlan::class, MfgQualityCharacteristic::class,
        MfgQualityInspection::class, MfgQualityNonConformance::class, MfgProductionCost::class, MfgWorkCenterCapacity::class,
    ];

    expect(count($factories))->toBe(25);

    foreach ($factories as $class) {
        $model = $class::factory()->create(['tenant_id' => $tenantId]);
        expect($model)->toBeInstanceOf($class)->and($model->exists)->toBeTrue();
    }
});
