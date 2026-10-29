<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\Inventory\Actions\CreateInventoryAdjustmentAction;
use Modules\Inventory\DTOs\InventoryAdjustmentDTO;
use Modules\Inventory\Listeners\WorkflowApprovalInventoryListener;
use Modules\Inventory\Models\InventoryAdjustment;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;
use Modules\Workflow\Models\WorkflowDefinition;
use Modules\Workflow\Models\WorkflowInstance;

uses(DatabaseTransactions::class);

beforeEach(function (): void {
    Gate::before(fn () => true);

    $this->tenant = Tenant::first() ?? Tenant::create([
        'id' => (string) Str::uuid7(),
        'name' => 'Workflow Integration Tenant',
        'legal_name' => 'Workflow Enterprise IT',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ]);

    app(TenantContext::class)->setTenant($this->tenant);

    $this->user = User::first() ?? User::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'name' => 'Inventory Chief Approver',
        'email' => 'approver@inventory-workflow.test',
        'password' => bcrypt('password123'),
    ]);

    DB::table('tenant_user')->updateOrInsert(
        ['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id],
        [
            'id' => (string) Str::uuid7(),
            'role' => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]
    );

    $this->definition = WorkflowDefinition::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'code' => 'INVENTORY_WRITE_OFF_APPROVAL',
        'name' => 'Inventory Write Off Approval',
        'target_entity_type' => InventoryAdjustment::class,
        'version' => 1,
        'status' => 'published',
        'is_active' => true,
    ]);

    $this->warehouse = Warehouse::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
    ]);

    $this->bin = WarehouseBin::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $this->item = StockItem::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'standard_cost' => 100.00,
        'current_moving_average_cost' => 100.00,
    ]);
});

test('minor inventory adjustment under 500 eur auto-approves without workflow trigger', function (): void {
    // Initial stock in bin
    StockQuant::create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'warehouse_bin_id' => $this->bin->id,
        'stock_item_id' => $this->item->id,
        'quantity_on_hand' => 10.0,
        'quantity_reserved' => 0.0,
    ]);

    $action = app(CreateInventoryAdjustmentAction::class);

    // Delta of -2 units @ €100 = -€200 (<= €500 threshold)
    $dto = new InventoryAdjustmentDTO(
        warehouseId: (string) $this->warehouse->id,
        reason: 'routine_evaporation',
        lines: [
            [
                'stock_item_id' => (string) $this->item->id,
                'warehouse_bin_id' => (string) $this->bin->id,
                'quantity_delta' => -2.0,
                'unit_cost' => 100.00,
            ],
        ],
        userId: (string) $this->user->id
    );

    $adjustment = $action->execute($dto);

    expect($adjustment)->toBeInstanceOf(InventoryAdjustment::class)
        ->and($adjustment->status)->toBe('approved')
        ->and($adjustment->is_locked)->toBeFalse()
        ->and((float) $adjustment->net_valuation_change)->toBe(-200.0);
});

test('major inventory adjustment over 500 eur requires workflow approval and enters pending state', function (): void {
    // Mock WorkflowEngineInterface to inspect start() invocation
    $mockEngine = mock(WorkflowEngineInterface::class);
    $mockEngine->shouldReceive('start')
        ->once()
        ->andReturn(new WorkflowInstance);
    app()->instance(WorkflowEngineInterface::class, $mockEngine);

    $action = app(CreateInventoryAdjustmentAction::class);

    // Delta of -10 units @ €100 = -€1000 (> €500 threshold)
    $dto = new InventoryAdjustmentDTO(
        warehouseId: (string) $this->warehouse->id,
        reason: 'damaged_during_transit',
        lines: [
            [
                'stock_item_id' => (string) $this->item->id,
                'warehouse_bin_id' => (string) $this->bin->id,
                'quantity_delta' => -10.0,
                'unit_cost' => 100.00,
            ],
        ],
        userId: (string) $this->user->id
    );

    $adjustment = $action->execute($dto);

    expect($adjustment)->toBeInstanceOf(InventoryAdjustment::class)
        ->and($adjustment->status)->toBe('pending_approval')
        ->and($adjustment->is_locked)->toBeTrue()
        ->and((float) $adjustment->net_valuation_change)->toBe(-1000.0);
});

test('workflow approval event listener marks inventory adjustment as approved when workflow completes', function (): void {
    $adjustment = InventoryAdjustment::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'warehouse_id' => $this->warehouse->id,
        'adjustment_number' => 'ADJ-TEST-'.strtoupper(Str::random(4)),
        'reason' => 'annual_writeoff',
        'status' => 'pending_approval',
        'net_valuation_change' => -1500.00,
        'is_locked' => true,
        'created_by' => $this->user->id,
    ]);

    $instance = WorkflowInstance::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'workflow_definition_id' => $this->definition->id,
        'workflow_definition_version' => 1,
        'approvable_type' => InventoryAdjustment::class,
        'approvable_id' => $adjustment->id,
        'status' => 'completed',
        'initiator_user_id' => $this->user->id,
    ]);

    $listener = new WorkflowApprovalInventoryListener;
    $event = new WorkflowInstanceCompletedEvent(
        instanceId: (string) $instance->id,
        status: 'completed',
        tenantId: (string) $this->tenant->id,
        companyId: (string) $this->tenant->id
    );

    $listener->handleCompleted($event);

    $adjustment->refresh();
    expect($adjustment->status)->toBe('approved')
        ->and($adjustment->is_locked)->toBeFalse()
        ->and($adjustment->approved_at)->not->toBeNull();
});

test('workflow rejection event listener marks inventory adjustment as rejected when workflow is rejected', function (): void {
    $adjustment = InventoryAdjustment::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'warehouse_id' => $this->warehouse->id,
        'adjustment_number' => 'ADJ-TEST-'.strtoupper(Str::random(4)),
        'reason' => 'unverified_loss',
        'status' => 'pending_approval',
        'net_valuation_change' => -2500.00,
        'is_locked' => true,
        'created_by' => $this->user->id,
    ]);

    $instance = WorkflowInstance::create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->tenant->id,
        'workflow_definition_id' => $this->definition->id,
        'workflow_definition_version' => 1,
        'approvable_type' => InventoryAdjustment::class,
        'approvable_id' => $adjustment->id,
        'status' => 'rejected',
        'initiator_user_id' => $this->user->id,
    ]);

    $listener = new WorkflowApprovalInventoryListener;
    $event = new WorkflowInstanceRejectedEvent(
        instanceId: (string) $instance->id,
        tenantId: (string) $this->tenant->id,
        companyId: (string) $this->tenant->id
    );

    $listener->handleRejected($event);

    $adjustment->refresh();
    expect($adjustment->status)->toBe('rejected')
        ->and($adjustment->is_locked)->toBeFalse();
});
