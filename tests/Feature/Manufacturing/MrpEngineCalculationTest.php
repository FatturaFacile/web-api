<?php

declare(strict_types=1);

namespace Tests\Feature\Manufacturing;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Manufacturing\Contracts\BillOfMaterialsServiceInterface;
use Modules\Manufacturing\Contracts\MrpServiceInterface;
use Modules\Manufacturing\DTOs\CreateBomDTO;
use Modules\Manufacturing\DTOs\RunMrpDTO;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgMrpPlannedOrder;
use Modules\Manufacturing\Models\MfgMrpRun;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($this->tenant);

    $this->mrpService = app(MrpServiceInterface::class);
    $this->bomService = app(BillOfMaterialsServiceInterface::class);
});

test('MRP engine executes Net Requirements formula and generates planned orders and shortages', function () {
    // 1. Raw Material with safety stock requirement
    $rawSheet = MfgItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'item_type' => 'raw_material',
        'item_code' => 'RM-ALU-SHEET',
        'safety_stock' => 50.0,
        'min_lot_size' => 20.0,
        'lead_time_days' => 5,
    ]);

    // 2. Finished Good with safety stock
    $box = MfgItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'item_type' => 'finished_good',
        'item_code' => 'FG-ALU-BOX',
        'safety_stock' => 15.0,
        'min_lot_size' => 5.0,
        'lead_time_days' => 3,
    ]);

    // Link BOM
    $this->bomService->createBom(new CreateBomDTO(
        tenantId: $this->tenant->id,
        mfgItemId: $box->id,
        name: 'Aluminium Box BOM',
        components: [
            ['component_item_id' => $rawSheet->id, 'quantity_per' => 2.0],
        ]
    ));

    // Run MRP
    $run = $this->mrpService->executeMrpRun(new RunMrpDTO(
        tenantId: $this->tenant->id,
        horizonStartDate: now()->toDateString(),
        horizonEndDate: now()->addMonths(1)->toDateString(),
        runType: 'regenerative',
        itemIds: [$rawSheet->id, $box->id]
    ));

    expect($run)->toBeInstanceOf(MfgMrpRun::class)
        ->and($run->status)->toBe('completed')
        ->and($run->total_planned_orders)->toBeGreaterThanOrEqual(2)
        ->and($run->total_shortages)->toBeGreaterThanOrEqual(2);

    // Verify Make order for Finished Good
    $makeOrder = MfgMrpPlannedOrder::where('mrp_run_id', $run->id)
        ->where('mfg_item_id', $box->id)
        ->first();

    expect($makeOrder)->not->toBeNull()
        ->and($makeOrder->order_type)->toBe('make')
        ->and((float) $makeOrder->quantity)->toBe(15.0);

    // Verify Buy order for Raw Material
    $buyOrder = MfgMrpPlannedOrder::where('mrp_run_id', $run->id)
        ->where('mfg_item_id', $rawSheet->id)
        ->first();

    expect($buyOrder)->not->toBeNull()
        ->and($buyOrder->order_type)->toBe('buy')
        ->and((float) $buyOrder->quantity)->toBe(50.0);
});

test('Planned orders can be firmed and converted into released production orders', function () {
    $item = MfgItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'item_type' => 'finished_good',
    ]);

    $raw = MfgItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'item_type' => 'raw_material',
    ]);

    $this->bomService->createBom(new CreateBomDTO(
        tenantId: $this->tenant->id,
        mfgItemId: $item->id,
        name: 'Conversion BOM',
        components: [['component_item_id' => $raw->id, 'quantity_per' => 1.0]]
    ));

    $run = $this->mrpService->executeMrpRun(new RunMrpDTO(
        tenantId: $this->tenant->id,
        horizonStartDate: now()->toDateString(),
        horizonEndDate: now()->addDays(30)->toDateString(),
        itemIds: [$item->id]
    ));

    $plannedOrder = MfgMrpPlannedOrder::where('mrp_run_id', $run->id)->where('mfg_item_id', $item->id)->first();
    expect($plannedOrder)->not->toBeNull();

    // 1. Firm
    $this->mrpService->firmPlannedOrder($plannedOrder->id);
    $plannedOrder->refresh();
    expect($plannedOrder->status)->toBe('firmed');

    // 2. Convert to Production Order
    $convertedId = $this->mrpService->convertPlannedOrder($plannedOrder->id);
    $plannedOrder->refresh();

    expect($plannedOrder->status)->toBe('released')
        ->and($plannedOrder->converted_order_id)->toBe($convertedId);
});
