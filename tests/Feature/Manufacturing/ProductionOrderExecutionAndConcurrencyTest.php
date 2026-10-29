<?php

declare(strict_types=1);

namespace Tests\Feature\Manufacturing;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use InvalidArgumentException;
use Modules\Manufacturing\Contracts\BillOfMaterialsServiceInterface;
use Modules\Manufacturing\Contracts\ProductionExecutionServiceInterface;
use Modules\Manufacturing\Contracts\ProductionPlanningServiceInterface;
use Modules\Manufacturing\DTOs\CreateBomDTO;
use Modules\Manufacturing\DTOs\CreateProductionOrderDTO;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgProductionOrder;

uses(DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->setTenant($this->tenant);

    $this->planningService = app(ProductionPlanningServiceInterface::class);
    $this->executionService = app(ProductionExecutionServiceInterface::class);
    $this->bomService = app(BillOfMaterialsServiceInterface::class);

    $this->item = MfgItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'item_type' => 'finished_good',
    ]);

    $this->raw = MfgItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'item_type' => 'raw_material',
        'standard_cost' => 12.00,
    ]);

    $this->bom = $this->bomService->createBom(new CreateBomDTO(
        tenantId: $this->tenant->id,
        mfgItemId: $this->item->id,
        name: 'Execution Test BOM',
        components: [
            ['component_item_id' => $this->raw->id, 'quantity_per' => 2.0],
        ]
    ));
});

test('Production order transitions through planned, released, in_progress, completed, and closed states', function () {
    // 1. Plan order
    $order = $this->planningService->planOrder(new CreateProductionOrderDTO(
        tenantId: $this->tenant->id,
        mfgItemId: $this->item->id,
        targetQuantity: 50.0,
        plannedStartDate: now()->toDateString(),
        plannedEndDate: now()->addDays(5)->toDateString()
    ));

    expect($order->status)->toBe('planned')
        ->and((float) $order->target_quantity)->toBe(50.0)
        ->and($order->lock_version)->toBe(1);

    // 2. Release order (allocates component lines)
    $order = $this->executionService->releaseOrder($order->id);

    expect($order->status)->toBe('released')
        ->and($order->lock_version)->toBe(2)
        ->and($order->lines)->toHaveCount(1)
        ->and((float) $order->lines->first()->required_quantity)->toBe(100.0);

    // 3. Start order
    $order = $this->executionService->startOrder($order->id);
    expect($order->status)->toBe('in_progress')
        ->and($order->actual_start_date)->not->toBeNull();

    // 4. Record partial completion
    $order = $this->executionService->recordPartialCompletion($order->id, 20.0, 2.0);
    expect((float) $order->completed_quantity)->toBe(20.0)
        ->and((float) $order->scrapped_quantity)->toBe(2.0)
        ->and($order->lock_version)->toBe(3);

    // 5. Complete order
    $order = $this->executionService->completeOrder($order->id);
    expect($order->status)->toBe('completed')
        ->and($order->actual_end_date)->not->toBeNull()
        ->and($order->lock_version)->toBe(4);

    // 6. Close order
    $order = $this->executionService->closeOrder($order->id);
    expect($order->status)->toBe('closed');
});

test('Invalid state transitions throw InvalidArgumentException', function () {
    $order = MfgProductionOrder::factory()->create([
        'tenant_id' => $this->tenant->id,
        'status' => 'completed',
    ]);

    expect(fn () => $order->transitionTo('released'))
        ->toThrow(InvalidArgumentException::class);
});
