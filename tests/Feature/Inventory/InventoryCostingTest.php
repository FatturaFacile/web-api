<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\Inventory\Contracts\InventoryCostingServiceInterface;
use Modules\Inventory\Contracts\StockValuationServiceInterface;
use Modules\Inventory\DTOs\OutboundCostingDTO;
use Modules\Inventory\Events\PurchasePriceVarianceRecordedEvent;
use Modules\Inventory\Models\InventoryCostLayer;
use Modules\Inventory\Models\InventoryCostLayerConsumption;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\StockQuant;
use Modules\Procurement\Events\GoodsReceivedEvent;
use Modules\Procurement\Models\PurchaseOrderLine;
use Modules\Procurement\Models\SupplierCatalog;
use Tests\Support\InventoryValuationFixtures as Fx;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ['tenant' => $this->tenant, 'user' => $this->user] = Fx::actingTenantUser();
    $this->tenantId = (string) $this->tenant->id;
    ['warehouse' => $this->warehouse, 'bin' => $this->bin] = Fx::warehouseWithBin($this->tenantId);
    $this->costing = app(InventoryCostingServiceInterface::class);
});

function costingTestOnHand(string $itemId): float
{
    return (float) StockQuant::query()->where('stock_item_id', $itemId)->sum('quantity_on_hand');
}

function costingTestOpenLayerQuantity(string $itemId): float
{
    return (float) InventoryCostLayer::query()->where('stock_item_id', $itemId)->sum('remaining_quantity');
}

test('WAC receipt recomputes the moving average and records a cost layer', function (): void {
    $item = Fx::item($this->tenantId, 'weighted_average');

    Fx::receive($item, $this->bin, 10, 5.00);
    Fx::receive($item, $this->bin, 10, 7.00);

    $item->refresh();
    expect((string) $item->current_moving_average_cost)->toBe('6.0000');

    $layers = InventoryCostLayer::query()->where('stock_item_id', $item->id)->orderBy('layer_sequence')->get();
    expect($layers)->toHaveCount(2)
        ->and((string) $layers[1]->original_quantity)->toBe('10.0000')
        ->and((string) $layers[1]->unit_cost)->toBe('7.0000');

    // Issue under WAC is valued at the current average.
    $issue = Fx::issue($item, $this->bin, 5);
    expect((string) $issue->total_valuation)->toBe('30.00')
        ->and((string) $issue->unit_cost)->toBe('6.0000');
});

test('FIFO issue depletes layers in receipt order and COGS equals the depleted layers', function (): void {
    $item = Fx::item($this->tenantId, 'fifo');

    Fx::receive($item, $this->bin, 10, 5.00);
    Fx::receive($item, $this->bin, 10, 7.00);

    $issue = Fx::issue($item, $this->bin, 15);

    expect((string) $issue->total_valuation)->toBe('85.00'); // 10*5 + 5*7

    $layers = InventoryCostLayer::query()->where('stock_item_id', $item->id)->orderBy('layer_sequence')->get();
    expect((string) $layers[0]->remaining_quantity)->toBe('0.0000')
        ->and((string) $layers[1]->remaining_quantity)->toBe('5.0000');

    $consumptions = InventoryCostLayerConsumption::query()->where('issue_movement_id', $issue->id)->get();
    expect($consumptions)->toHaveCount(2)
        ->and(round((float) $consumptions->sum('cogs_amount'), 2))->toBe(85.0);

    // Layer remaining quantities sum to on-hand.
    expect(costingTestOpenLayerQuantity((string) $item->id))->toBe(costingTestOnHand((string) $item->id))
        ->and(costingTestOnHand((string) $item->id))->toBe(5.0);
});

test('FIFO issue beyond the remaining cost layers is rejected (negative stock not allowed)', function (): void {
    $item = Fx::item($this->tenantId, 'fifo');
    Fx::receive($item, $this->bin, 10, 5.00);

    $this->costing->consumeOutbound($item, new OutboundCostingDTO(
        movementId: (string) Str::uuid7(),
        quantity: '15',
        onHandBefore: '10',
    ));
})->throws(ValidationException::class);

test('pre-existing stock without layers is backfilled as an opening layer at moving average cost', function (): void {
    $item = Fx::item($this->tenantId, 'fifo', ['current_moving_average_cost' => 4.0]);
    StockQuant::create([
        'tenant_id' => $this->tenantId,
        'warehouse_bin_id' => $this->bin->id,
        'stock_item_id' => $item->id,
        'quantity_on_hand' => 8,
        'quantity_reserved' => 0,
    ]);

    $issue = Fx::issue($item, $this->bin, 3);

    expect((string) $issue->total_valuation)->toBe('12.00');
    $opening = InventoryCostLayer::query()->where('stock_item_id', $item->id)->where('source_type', 'opening_balance')->first();
    expect($opening)->not->toBeNull()
        ->and((string) $opening->original_quantity)->toBe('8.0000')
        ->and((string) $opening->remaining_quantity)->toBe('5.0000');

    // Idempotent: nothing left uncovered.
    expect($this->costing->ensureOpeningLayers($item->refresh()))->toBeNull();
});

test('standard cost receipt keeps inventory at standard and journals the purchase price variance', function (): void {
    Fx::fiscalYear($this->tenantId, (int) now()->format('Y'));
    $item = Fx::item($this->tenantId, 'standard_cost', ['standard_cost' => 5.00]);

    $movement = Fx::receive($item, $this->bin, 10, 5.50);

    $layer = InventoryCostLayer::query()->where('source_movement_id', $movement->id)->firstOrFail();
    expect((string) $layer->unit_cost)->toBe('5.0000')
        ->and($this->costing->valueOnHand($item->refresh()))->toBe('50.00');

    $journal = JournalEntry::query()
        ->where('source_document_type', 'inventory_ppv')
        ->where('source_document_id', $movement->id)
        ->firstOrFail();

    expect($journal->isBalanced())->toBeTrue()
        ->and((float) $journal->total_amount)->toBe(5.0);

    $ppvAccount = ChartOfAccount::query()->where('tenant_id', $this->tenantId)->where('account_code', '5110')->firstOrFail();
    $inventoryAccount = ChartOfAccount::query()->where('tenant_id', $this->tenantId)->where('account_code', '1200')->firstOrFail();

    $ppvLine = $journal->lines->firstWhere('account_id', $ppvAccount->id);
    $inventoryLine = $journal->lines->firstWhere('account_id', $inventoryAccount->id);
    expect((float) $ppvLine->debit_amount)->toBe(5.0)
        ->and((float) $inventoryLine->credit_amount)->toBe(5.0);

    // Issues are costed at standard.
    $issue = Fx::issue($item, $this->bin, 4);
    expect((string) $issue->total_valuation)->toBe('20.00');
});

test('standard cost receipt at standard price raises no purchase price variance', function (): void {
    Event::fake([PurchasePriceVarianceRecordedEvent::class]);
    $item = Fx::item($this->tenantId, 'standard_cost', ['standard_cost' => 5.00]);

    Fx::receive($item, $this->bin, 10, 5.00);

    Event::assertNotDispatched(PurchasePriceVarianceRecordedEvent::class);
});

test('item method override wins over the category method', function (): void {
    $item = Fx::item($this->tenantId, 'fifo', ['valuation_method' => 'weighted_average']);

    expect($this->costing->describeMethod($item))->toBe([
        'category_method' => 'fifo',
        'item_method' => 'weighted_average',
        'effective_method' => 'weighted_average',
        'source' => 'item',
    ]);

    Fx::receive($item, $this->bin, 10, 5.00);
    Fx::receive($item, $this->bin, 10, 7.00);
    $issue = Fx::issue($item, $this->bin, 10);

    // WAC (6.00) applies, not FIFO (5.00).
    expect((string) $issue->total_valuation)->toBe('60.00');
});

test('legacy moving_average category value resolves to weighted average', function (): void {
    $item = Fx::item($this->tenantId, 'moving_average');

    expect($this->costing->describeMethod($item)['effective_method'])->toBe('weighted_average')
        ->and($this->costing->describeMethod($item)['source'])->toBe('category');
});

test('legacy FIFO valuation helper reads the open cost layers', function (): void {
    $item = Fx::item($this->tenantId, 'fifo');
    Fx::receive($item, $this->bin, 10, 40.00);
    Fx::receive($item, $this->bin, 10, 60.00);

    expect(app(StockValuationServiceInterface::class)->calculateFifoValuation($item, 15.0))->toBe(700.0);
});

test('valuation snapshot endpoint captures the effective method', function (): void {
    $item = Fx::item($this->tenantId, 'fifo');
    Fx::receive($item, $this->bin, 4, 2.50);

    $response = $this->postJson('/api/v1/inventory/valuation/snapshot', [
        'stock_item_id' => $item->id,
        'warehouse_id' => $this->warehouse->id,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('valuation_method', 'fifo');
    expect((float) $response->json('total_valuation'))->toBe(10.0);
});

test('goods receipt listener books a receipt movement linked to the receipt at the PO line price', function (): void {
    // The receipt also accrues GR/IR in the ledger, which needs an open fiscal period.
    Fx::fiscalYear($this->tenantId, (int) now()->format('Y'));
    $item = Fx::item($this->tenantId, 'fifo');
    $receipt = Fx::purchaseReceipt($this->tenantId);
    // The stock item of an order line is the one whose SKU the supplier catalog entry of the line names.
    $catalog = SupplierCatalog::factory()->create(['tenant_id' => $this->tenantId, 'internal_sku' => $item->sku]);
    $poLine = PurchaseOrderLine::factory()->create([
        'tenant_id' => $this->tenantId,
        'purchase_order_id' => $receipt->purchase_order_id,
        'supplier_catalog_id' => $catalog->id,
        'unit_price' => 12.5,
    ]);

    $orphanLine = PurchaseOrderLine::factory()->create([
        'tenant_id' => $this->tenantId,
        'purchase_order_id' => $receipt->purchase_order_id,
        'supplier_catalog_id' => null,
        'unit_price' => 12.5,
    ]);

    event(new GoodsReceivedEvent(
        receiptId: (string) $receipt->id,
        receiptNumber: (string) $receipt->receipt_number,
        purchaseOrderId: (string) $receipt->purchase_order_id,
        supplierDeliveryNoteRef: 'DDT-144',
        receivedLines: [
            ['purchase_order_line_id' => (string) $poLine->id, 'quantity_received' => 8.0, 'quantity_accepted' => 6.0, 'unit_cost' => 12.5, 'stock_item_id' => (string) $item->id],
            ['purchase_order_line_id' => (string) $orphanLine->id, 'quantity_received' => 3.0, 'quantity_accepted' => 3.0, 'unit_cost' => 12.5], // no stock item: skipped
        ],
        tenantId: $this->tenantId,
        companyId: $this->tenantId,
    ));

    $movement = StockMovement::query()->where('origin_document_id', $receipt->id)->firstOrFail();
    expect($movement->origin_document_type)->toBe('purchase_receipt')
        ->and((string) $movement->quantity)->toBe('6.0000')
        ->and((string) $movement->unit_cost)->toBe('12.5000');

    $layer = InventoryCostLayer::query()->where('receipt_id', $receipt->id)->firstOrFail();
    expect((string) $layer->stock_item_id)->toBe((string) $item->id)
        ->and((string) $layer->unit_cost)->toBe('12.5000')
        ->and(StockMovement::query()->where('origin_document_id', $receipt->id)->count())->toBe(1);
});
