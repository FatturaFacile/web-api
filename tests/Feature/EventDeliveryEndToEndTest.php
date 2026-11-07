<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\EventBus\Contracts\EventBusInterface;
use Enterprise\EventBus\Models\TransactionalOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\Inventory\Jobs\ExpireStockLotsJob;
use Modules\Inventory\Models\InventoryAdjustment;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockLot;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderLine;
use Modules\Procurement\Models\Supplier;
use Modules\Projects\DTOs\RecordJobCostDTO;
use Modules\Projects\Models\Project;
use Modules\Projects\Services\JobCostingAccumulationService;
use Modules\Workflow\Actions\SeedDefaultWorkflowDefinitionsAction;
use Modules\Workflow\Models\WorkflowInstance;

uses(RefreshDatabase::class);

/**
 * Domain events published by the services now reach their class-based listeners in-process: these tests go through the
 * HTTP action or the service and assert the state the listeners produce (journal entries, approvals, quarantine).
 */
beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Eventi Srl', 'legal_name' => 'Eventi Srl', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->setTenant($this->tenant);
    app(OpenFiscalYearAction::class)->execute((string) $this->tenant->id, (int) now()->year);

    $this->admin = User::create(['name' => 'Admin', 'email' => Str::random(10).'@example.it', 'password' => bcrypt('secret123'), 'tenant_id' => $this->tenant->id]);
    $this->admin->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($this->admin);
    $this->withHeader('X-Tenant-Id', $this->tenant->id);

    $this->warehouse = Warehouse::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->tenant->id]);
    $this->bin = WarehouseBin::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->item = StockItem::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->tenant->id, 'standard_cost' => 100.0, 'current_moving_average_cost' => 100.0]);
    StockQuant::create([
        'tenant_id' => $this->tenant->id, 'company_id' => $this->tenant->id, 'warehouse_bin_id' => $this->bin->id,
        'stock_item_id' => $this->item->id, 'quantity_on_hand' => 50.0, 'quantity_reserved' => 0.0,
    ]);

    $this->ledgerEntries = fn (string $sourceType) => JournalEntry::withoutGlobalScopes()
        ->where('tenant_id', $this->tenant->id)->where('source_document_type', $sourceType)->with('lines')->get();
});

test('a stock adjustment within the threshold posts its cost impact to the general ledger', function (): void {
    $id = $this->postJson('/api/v1/inventory/adjustments', [
        'warehouse_id' => $this->warehouse->id,
        'reason' => 'damage_loss',
        'lines' => [['stock_item_id' => $this->item->id, 'warehouse_bin_id' => $this->bin->id, 'quantity_delta' => -2, 'unit_cost' => 100]],
    ])->assertCreated()->json('id') ?? InventoryAdjustment::query()->latest('created_at')->value('id');

    $entries = ($this->ledgerEntries)('inventory_adjustment');
    expect($entries)->toHaveCount(1)
        ->and((string) $entries->first()->source_document_id)->toBe((string) $id)
        ->and((float) $entries->first()->total_amount)->toBe(200.0)
        ->and($entries->first()->journal_number)->toBe(1)
        ->and(TransactionalOutbox::query()->where('event_type', 'inventory.adjustment_posted')->count())->toBe(1);
});

test('a stock adjustment above the threshold moves stock and posts only once its default approval is granted', function (): void {
    app(SeedDefaultWorkflowDefinitionsAction::class)->execute((string) $this->tenant->id, ['INVENTORY_WRITE_OFF_APPROVAL']);

    $this->postJson('/api/v1/inventory/adjustments', [
        'warehouse_id' => $this->warehouse->id,
        'reason' => 'obsolete_write_off',
        'lines' => [['stock_item_id' => $this->item->id, 'warehouse_bin_id' => $this->bin->id, 'quantity_delta' => -10, 'unit_cost' => 100]],
    ])->assertCreated();

    $adjustment = InventoryAdjustment::query()->latest('created_at')->firstOrFail();
    expect($adjustment->status)->toBe('pending_approval')->and(($this->ledgerEntries)('inventory_adjustment'))->toHaveCount(0);

    $step = WorkflowInstance::query()->where('approvable_id', $adjustment->id)->firstOrFail()->steps()->firstOrFail();
    $this->postJson("/api/v1/workflows/steps/{$step->id}/decide", ['decision' => 'approved'])->assertOk();

    expect($adjustment->fresh()->status)->toBe('approved')
        ->and((float) StockQuant::query()->where('stock_item_id', $this->item->id)->where('warehouse_bin_id', $this->bin->id)->value('quantity_on_hand'))->toBe(40.0)
        ->and((float) ($this->ledgerEntries)('inventory_adjustment')->sole()->total_amount)->toBe(1000.0);
});

test('a goods receipt over HTTP posts the GR/IR accrual and is recorded once in the outbox', function (): void {
    $supplier = Supplier::factory()->approved()->create(['tenant_id' => $this->tenant->id]);
    // Segregation of duties: the receiver is not the creator of the order.
    $order = PurchaseOrder::factory()->approved()->create(['tenant_id' => $this->tenant->id, 'supplier_id' => $supplier->id, 'created_by' => null]);
    $line = PurchaseOrderLine::factory()->create(['tenant_id' => $this->tenant->id, 'purchase_order_id' => $order->id, 'quantity_ordered' => 10, 'unit_price' => 12.5]);

    $this->postJson("/api/v1/procurement/purchase-orders/{$order->id}/goods-receipt", [
        'supplier_delivery_note_ref' => 'DDT-77',
        'received_date' => now()->toDateString(),
        'lines' => [['purchase_order_line_id' => $line->id, 'quantity_received' => 4, 'quantity_accepted' => 4]],
    ])->assertCreated();

    $entry = ($this->ledgerEntries)('goods_receipt')->sole();
    expect((float) $entry->total_amount)->toBe(50.0)
        ->and(TransactionalOutbox::query()->where('event_type', 'procurement.goods_received')->orWhere('event_type', 'like', '%goods_received%')->count())->toBe(1);
});

test('a project cost recorded through the costing service posts a WIP journal entry', function (): void {
    $project = Project::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->tenant->id, 'status' => 'active']);

    $costEntry = app(JobCostingAccumulationService::class)->recordCost(new RecordJobCostDTO(
        projectId: (string) $project->id,
        costCategory: 'material',
        costSourceType: 'manual',
        costSourceId: (string) Str::uuid7(),
        quantity: 3.0,
        unitCost: 20.0,
        totalCost: 60.0,
        incurredDate: now()->toDateString(),
    ));

    $entry = ($this->ledgerEntries)('project_cost_entry')->sole();
    expect((string) $entry->source_document_id)->toBe((string) $costEntry->id)
        ->and((float) $entry->total_amount)->toBe(60.0);
});

test('suspending a supplier over HTTP cancels and locks its draft purchase orders', function (): void {
    $supplier = Supplier::factory()->approved()->create(['tenant_id' => $this->tenant->id]);
    $draft = PurchaseOrder::factory()->draft()->create(['tenant_id' => $this->tenant->id, 'supplier_id' => $supplier->id]);

    $this->putJson("/api/v1/procurement/suppliers/{$supplier->id}", ['status' => 'suspended', 'suspension_reason' => 'Fraudulent billing'])->assertOk();

    $draft->refresh();
    expect($draft->status)->toBe('cancelled')->and($draft->is_locked)->toBeTrue();
});

test('the lot expiry job quarantines expired lots once and leaves valid lots alone', function (): void {
    $expired = StockLot::create(['tenant_id' => $this->tenant->id, 'company_id' => $this->tenant->id, 'stock_item_id' => $this->item->id, 'lot_number' => 'LOT-OLD', 'expiration_date' => now()->subDay()->toDateString(), 'initial_quantity' => 5, 'current_quantity' => 5, 'status' => 'active']);
    $valid = StockLot::create(['tenant_id' => $this->tenant->id, 'company_id' => $this->tenant->id, 'stock_item_id' => $this->item->id, 'lot_number' => 'LOT-NEW', 'expiration_date' => now()->addMonth()->toDateString(), 'initial_quantity' => 5, 'current_quantity' => 5, 'status' => 'active']);

    (new ExpireStockLotsJob((string) $this->tenant->id))->handle(app(EventBusInterface::class));
    (new ExpireStockLotsJob((string) $this->tenant->id))->handle(app(EventBusInterface::class));

    expect($expired->fresh()->is_quarantined)->toBeTrue()
        ->and($valid->fresh()->is_quarantined)->toBeFalse()
        ->and(TransactionalOutbox::query()->where('aggregate_id', $expired->id)->count())->toBe(1);
});
