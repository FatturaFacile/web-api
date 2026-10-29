<?php

declare(strict_types=1);

namespace Tests\Feature\Manufacturing;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Manufacturing\Contracts\BillOfMaterialsServiceInterface;
use Modules\Manufacturing\Contracts\ProductionCostingServiceInterface;
use Modules\Manufacturing\Contracts\ProductionExecutionServiceInterface;
use Modules\Manufacturing\Contracts\ProductionPlanningServiceInterface;
use Modules\Manufacturing\Contracts\RoutingServiceInterface;
use Modules\Manufacturing\DTOs\CreateBomDTO;
use Modules\Manufacturing\DTOs\CreateProductionOrderDTO;
use Modules\Manufacturing\DTOs\CreateRoutingDTO;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgProductionCost;
use Modules\Manufacturing\Models\MfgWorkCenter;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($this->tenant);

    $this->costingService = app(ProductionCostingServiceInterface::class);
    $this->planningService = app(ProductionPlanningServiceInterface::class);
    $this->executionService = app(ProductionExecutionServiceInterface::class);
    $this->bomService = app(BillOfMaterialsServiceInterface::class);
    $this->routingService = app(RoutingServiceInterface::class);

    $this->item = MfgItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'item_type' => 'finished_good',
    ]);

    $this->raw = MfgItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'item_type' => 'raw_material',
        'standard_cost' => 20.00,
    ]);

    $this->wc = MfgWorkCenter::factory()->create([
        'tenant_id' => $this->tenant->id,
        'hourly_cost_rate' => 40.00,
        'hourly_overhead_rate' => 15.00,
    ]);

    $this->bom = $this->bomService->createBom(new CreateBomDTO(
        tenantId: $this->tenant->id,
        mfgItemId: $this->item->id,
        name: 'Costing Test BOM',
        components: [
            ['component_item_id' => $this->raw->id, 'quantity_per' => 3.0],
        ]
    ));

    $this->routing = $this->routingService->createRouting(new CreateRoutingDTO(
        tenantId: $this->tenant->id,
        mfgItemId: $this->item->id,
        name: 'Costing Test Routing',
        operations: [
            [
                'operation_code' => 'OP-PROD-COST',
                'name' => 'Machining Step',
                'work_center_id' => $this->wc->id,
                'setup_time_minutes' => 30.0,
                'run_time_minutes_per_unit' => 6.0,
                'labor_time_minutes_per_unit' => 6.0,
                'standard_labor_rate' => 25.0,
                'standard_machine_rate' => 45.0,
            ],
        ]
    ));
});

test('Production costing calculates standard rollup, actual cost accumulation, and variance analysis', function () {
    $order = $this->planningService->planOrder(new CreateProductionOrderDTO(
        tenantId: $this->tenant->id,
        mfgItemId: $this->item->id,
        targetQuantity: 10.0,
        plannedStartDate: now()->toDateString(),
        plannedEndDate: now()->addDays(5)->toDateString()
    ));

    $order = $this->executionService->releaseOrder($order->id);

    // 1. Standard Cost Rollup:
    // Materials: 10 units * 3 qty * $20 = $600
    // Operations: Setup = 30m, Run = 60m. Total hours = 90 / 60 = 1.5h
    // Labor: 1.5h * $25 = $37.50
    // Machine: 1.5h * $45 = $67.50
    // Overhead: 1.5h * $15 = $22.50
    // Total standard cost: 600 + 37.50 + 67.50 + 22.50 = 727.50
    $rollup = $this->costingService->calculateStandardCostRollup($order->id);

    expect((float) $rollup['material_cost'])->toBe(600.0)
        ->and((float) $rollup['labor_cost'])->toBe(37.5)
        ->and((float) $rollup['machine_cost'])->toBe(67.5)
        ->and((float) $rollup['overhead_cost'])->toBe(22.5)
        ->and((float) $rollup['total_standard_cost'])->toBe(727.5);

    // 2. WIP Recognition Accounting
    $this->costingService->postProductionWipAccounting($order->id);

    expect(MfgProductionCost::where('production_order_id', $order->id)->count())
        ->toBe(4);

    // 3. Complete Order & Calculate Actual Cost and Variance
    $order = $this->executionService->startOrder($order->id);
    $order = $this->executionService->completeOrder($order->id);

    $actuals = $this->costingService->calculateActualCostAndVariance($order->id);

    expect($actuals['actual_total_cost'])->toBeGreaterThan(0.0)
        ->and($actuals['variance_type'])->toBeIn(['favorable', 'unfavorable']);
});
