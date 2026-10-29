<?php

declare(strict_types=1);

namespace Tests\Feature\Manufacturing;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Manufacturing\Contracts\BillOfMaterialsServiceInterface;
use Modules\Manufacturing\Contracts\RoutingServiceInterface;
use Modules\Manufacturing\DTOs\CreateBomDTO;
use Modules\Manufacturing\DTOs\CreateEcoDTO;
use Modules\Manufacturing\DTOs\CreateRoutingDTO;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgWorkCenter;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($this->tenant);

    $this->bomService = app(BillOfMaterialsServiceInterface::class);
    $this->routingService = app(RoutingServiceInterface::class);
});

test('BOM service creates single-level and multi-level bills of material with phantom and scrap calculations', function () {
    // 1. Create Raw Materials
    $rawSteel = MfgItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'item_type' => 'raw_material',
        'item_code' => 'RM-ST-01',
        'standard_cost' => 15.00,
    ]);

    $rawCoil = MfgItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'item_type' => 'raw_material',
        'item_code' => 'RM-COIL-01',
        'standard_cost' => 5.00,
    ]);

    // 2. Create Subassembly (Motor)
    $motorSub = MfgItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'item_type' => 'subassembly',
        'item_code' => 'SA-MOTOR-01',
    ]);

    $subBom = $this->bomService->createBom(new CreateBomDTO(
        tenantId: $this->tenant->id,
        mfgItemId: $motorSub->id,
        name: 'Motor Subassembly BOM',
        components: [
            ['component_item_id' => $rawCoil->id, 'quantity_per' => 3.0, 'scrap_percentage' => 5.0],
        ]
    ));

    expect($subBom)->toBeInstanceOf(MfgBom::class)
        ->and($subBom->components)->toHaveCount(1);

    // 3. Create Finished Good (Pump)
    $pump = MfgItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'item_type' => 'finished_good',
        'item_code' => 'FG-PUMP-01',
    ]);

    $pumpBom = $this->bomService->createBom(new CreateBomDTO(
        tenantId: $this->tenant->id,
        mfgItemId: $pump->id,
        name: 'Pump Main BOM',
        components: [
            ['component_item_id' => $motorSub->id, 'quantity_per' => 1.0],
            ['component_item_id' => $rawSteel->id, 'quantity_per' => 2.0, 'scrap_percentage' => 10.0],
        ]
    ));

    expect($pumpBom)->toBeInstanceOf(MfgBom::class)
        ->and($pumpBom->components)->toHaveCount(2);

    // 4. Recursive Multi-level Explosion for 10 finished pumps
    $tree = $this->bomService->explodeBom($pumpBom->id, 10.0);

    expect(count($tree))->toBeGreaterThanOrEqual(3);

    // Steel raw material: 10 units * 2 per unit * (1 + 10% scrap) = 22.0
    $steelLine = collect($tree)->firstWhere('component_item_id', $rawSteel->id);
    expect($steelLine)->not->toBeNull()
        ->and((float) $steelLine['effective_quantity'])->toBe(22.0);

    // Motor subassembly line: 10 units * 1 = 10.0
    $motorLine = collect($tree)->firstWhere('component_item_id', $motorSub->id);
    expect($motorLine)->not->toBeNull()
        ->and((float) $motorLine['effective_quantity'])->toBe(10.0);

    // Child coil from exploded subassembly: 10 units * 3 per unit * (1 + 5% scrap) = 31.5
    $coilLine = collect($tree)->firstWhere('component_item_id', $rawCoil->id);
    expect($coilLine)->not->toBeNull()
        ->and((float) $coilLine['effective_quantity'])->toBe(31.5);
});

test('BOM revision and Engineering Change Order lifecycle updates revisions and versions', function () {
    $item = MfgItem::factory()->create(['tenant_id' => $this->tenant->id]);
    $raw = MfgItem::factory()->create(['tenant_id' => $this->tenant->id, 'item_type' => 'raw_material']);

    $bom = $this->bomService->createBom(new CreateBomDTO(
        tenantId: $this->tenant->id,
        mfgItemId: $item->id,
        name: 'Initial BOM',
        revision: 'A',
        components: [['component_item_id' => $raw->id, 'quantity_per' => 1.0]]
    ));

    expect($bom->revision)->toBe('A')
        ->and($bom->version)->toBe(1);

    // 1. Direct Revision
    $revised = $this->bomService->reviseBom($bom->id, 'B', 'Engineering upgrade on material specification');
    expect($revised->revision)->toBe('B')
        ->and($revised->version)->toBe(2);

    // 2. Engineering Change Order (ECO)
    $eco = $this->bomService->createEco(new CreateEcoDTO(
        tenantId: $this->tenant->id,
        title: 'Upgrade to Rev C',
        reason: 'Tolerance precision improvement',
        changePayload: ['bom_id' => $bom->id, 'new_revision' => 'C']
    ));

    expect($eco->status)->toBe('draft');

    $approverId = (string) Str::uuid7();
    $approvedEco = $this->bomService->approveEco($eco->id, $approverId);
    expect($approvedEco->status)->toBe('approved');

    $implementedEco = $this->bomService->implementEco($eco->id);
    expect($implementedEco->status)->toBe('implemented');

    $bom->refresh();
    expect($bom->revision)->toBe('C')
        ->and($bom->version)->toBe(3);
});

test('Routing service configures operations and calculates standard setup, run, and labor costs', function () {
    $item = MfgItem::factory()->create(['tenant_id' => $this->tenant->id]);
    $wc1 = MfgWorkCenter::factory()->create([
        'tenant_id' => $this->tenant->id,
        'hourly_cost_rate' => 50.00,
        'hourly_overhead_rate' => 20.00,
    ]);

    $routing = $this->routingService->createRouting(new CreateRoutingDTO(
        tenantId: $this->tenant->id,
        mfgItemId: $item->id,
        name: 'Standard Precision Routing',
        operations: [
            [
                'operation_code' => 'OP-10-MILL',
                'name' => 'CNC Milling',
                'work_center_id' => $wc1->id,
                'setup_time_minutes' => 30.0,
                'run_time_minutes_per_unit' => 12.0,
                'labor_time_minutes_per_unit' => 12.0,
                'standard_labor_rate' => 30.0,
                'standard_machine_rate' => 60.0,
            ],
            [
                'operation_code' => 'OP-20-INSPECT',
                'name' => 'In-Line Deburring and Inspection',
                'work_center_id' => $wc1->id,
                'setup_time_minutes' => 10.0,
                'run_time_minutes_per_unit' => 3.0,
                'labor_time_minutes_per_unit' => 3.0,
                'standard_labor_rate' => 25.0,
                'standard_machine_rate' => 30.0,
            ],
        ]
    ));

    expect($routing->operations)->toHaveCount(2);

    // Calculate times for 10 units:
    // Setup = 30 + 10 = 40 min
    // Run = (12 * 10) + (3 * 10) = 150 min
    // Labor = (12 * 10) + (3 * 10) = 150 min
    // Total cycle = 40 + 150 = 190 min
    $times = $this->routingService->calculateTotalStandardTimes($routing->id, 10.0);
    expect($times['total_setup_minutes'])->toBe(40.0)
        ->and($times['total_run_minutes'])->toBe(150.0)
        ->and($times['total_cycle_minutes'])->toBe(190.0);

    // Calculate costs
    $costs = $this->routingService->calculateStandardOperationsCost($routing->id, 10.0);
    expect($costs['labor_cost'])->toBeGreaterThan(0.0)
        ->and($costs['machine_cost'])->toBeGreaterThan(0.0)
        ->and($costs['total_cost'])->toBeGreaterThan(0.0);
});
