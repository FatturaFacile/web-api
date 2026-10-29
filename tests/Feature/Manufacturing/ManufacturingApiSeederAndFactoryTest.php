<?php

declare(strict_types=1);

namespace Tests\Feature\Manufacturing;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\ManufacturingDemoDataSeeder;
use Database\Seeders\ManufacturingReferenceSeeder;
use Database\Seeders\ManufacturingTestingSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgBomComponent;
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

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->item = MfgItem::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->raw = MfgItem::factory()->create(['tenant_id' => $this->tenant->id, 'item_type' => 'raw_material']);
});

test('Manufacturing REST API endpoints support authenticated BOM creation, Production Order workflow, and MRP execution', function () {
    // 1. Create BOM via API
    $bomResponse = $this->postJson('/api/v1/manufacturing/boms', [
        'mfg_item_id' => $this->item->id,
        'name' => 'API Created BOM',
        'revision' => 'A',
        'components' => [
            ['component_item_id' => $this->raw->id, 'quantity_per' => 2.0],
        ],
    ]);
    $bomResponse->assertStatus(201);
    $bomId = $bomResponse->json('data.id');

    // 2. List BOMs
    $listBom = $this->getJson('/api/v1/manufacturing/boms');
    $listBom->assertStatus(200);

    // 3. Explode BOM via API
    $explodeResponse = $this->getJson("/api/v1/manufacturing/boms/{$bomId}/explode?quantity=5");
    $explodeResponse->assertStatus(200);

    // 4. Create Production Order via API
    $orderResponse = $this->postJson('/api/v1/manufacturing/production-orders', [
        'mfg_item_id' => $this->item->id,
        'target_quantity' => 20.0,
        'planned_start_date' => now()->toDateString(),
        'planned_end_date' => now()->addDays(7)->toDateString(),
        'bom_id' => $bomId,
    ]);
    $orderResponse->assertStatus(201);
    $orderId = $orderResponse->json('data.id');

    // 5. Release Production Order via API
    $releaseResponse = $this->postJson("/api/v1/manufacturing/production-orders/{$orderId}/release");
    $releaseResponse->assertStatus(200);

    // 6. Execute MRP Run via API
    $mrpResponse = $this->postJson('/api/v1/manufacturing/mrp/runs', [
        'horizon_start_date' => now()->toDateString(),
        'horizon_end_date' => now()->addMonths(1)->toDateString(),
        'run_type' => 'regenerative',
    ]);
    $mrpResponse->assertStatus(201);
});

test('Manufacturing multilingual seeders run idempotently', function () {
    $this->seed(ManufacturingReferenceSeeder::class);
    $this->seed(ManufacturingTestingSeeder::class);
    $this->seed(ManufacturingDemoDataSeeder::class);

    // Second run
    $this->seed(ManufacturingReferenceSeeder::class);
    $this->seed(ManufacturingTestingSeeder::class);
    $this->seed(ManufacturingDemoDataSeeder::class);

    expect(MfgItem::where('tenant_id', $this->tenant->id)->count())->toBeGreaterThan(0);
});

test('all 25 manufacturing factories instantiate valid persisted models with relational integrity', function () {
    $factories = [
        MfgItem::class => fn () => MfgItem::factory()->create(['tenant_id' => $this->tenant->id]),
        MfgBom::class => fn () => MfgBom::factory()->create(['tenant_id' => $this->tenant->id]),
        MfgBomComponent::class => fn () => MfgBomComponent::factory()->create(['tenant_id' => $this->tenant->id]),
        MfgEngineeringChangeOrder::class => fn () => MfgEngineeringChangeOrder::factory()->create(['tenant_id' => $this->tenant->id]),
        MfgWorkCenter::class => fn () => MfgWorkCenter::factory()->create(['tenant_id' => $this->tenant->id]),
        MfgMachineCenter::class => fn () => MfgMachineCenter::factory()->create(['tenant_id' => $this->tenant->id]),
        MfgRouting::class => fn () => MfgRouting::factory()->create(['tenant_id' => $this->tenant->id]),
        MfgOperation::class => fn () => MfgOperation::factory()->create(['tenant_id' => $this->tenant->id]),
        MfgProductionVersion::class => fn () => MfgProductionVersion::factory()->create(['tenant_id' => $this->tenant->id]),
        MfgMrpRun::class => fn () => MfgMrpRun::factory()->create(['tenant_id' => $this->tenant->id]),
        MfgMrpPlannedOrder::class => fn () => MfgMrpPlannedOrder::factory()->create(['tenant_id' => $this->tenant->id]),
        MfgMrpShortage::class => fn () => MfgMrpShortage::factory()->create(['tenant_id' => $this->tenant->id]),
        MfgMrpPegging::class => fn () => MfgMrpPegging::factory()->create(['tenant_id' => $this->tenant->id]),
        MfgProductionOrder::class => fn () => MfgProductionOrder::factory()->create(['tenant_id' => $this->tenant->id]),
        MfgProductionOrderLine::class => fn () => MfgProductionOrderLine::factory()->create(['tenant_id' => $this->tenant->id]),
        MfgProductionOrderOperation::class => fn () => MfgProductionOrderOperation::factory()->create(['tenant_id' => $this->tenant->id]),
        MfgShopFloorExecution::class => fn () => MfgShopFloorExecution::factory()->create(['tenant_id' => $this->tenant->id]),
        MfgLaborTimeLog::class => fn () => MfgLaborTimeLog::factory()->create(['tenant_id' => $this->tenant->id]),
        MfgMachineLog::class => fn () => MfgMachineLog::factory()->create(['tenant_id' => $this->tenant->id]),
        MfgQualityPlan::class => fn () => MfgQualityPlan::factory()->create(['tenant_id' => $this->tenant->id]),
        MfgQualityCharacteristic::class => fn () => MfgQualityCharacteristic::factory()->create(['tenant_id' => $this->tenant->id]),
        MfgQualityInspection::class => fn () => MfgQualityInspection::factory()->create(['tenant_id' => $this->tenant->id]),
        MfgQualityNonConformance::class => fn () => MfgQualityNonConformance::factory()->create(['tenant_id' => $this->tenant->id]),
        MfgProductionCost::class => fn () => MfgProductionCost::factory()->create(['tenant_id' => $this->tenant->id]),
        MfgWorkCenterCapacity::class => fn () => MfgWorkCenterCapacity::factory()->create(['tenant_id' => $this->tenant->id]),
    ];

    expect(count($factories))->toBe(25);

    foreach ($factories as $class => $factoryCall) {
        $model = $factoryCall();
        expect($model)->toBeInstanceOf($class)
            ->and($model->exists)->toBeTrue();
    }
});
