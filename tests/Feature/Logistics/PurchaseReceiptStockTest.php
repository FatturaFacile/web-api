<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Inventory\Listeners\ProcurementGoodsReceivedListener;
use Modules\Inventory\Models\StockQuant;
use Modules\Procurement\Events\GoodsReceivedEvent;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderLine;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierCatalog;

require_once __DIR__.'/fixtures.php';

uses(RefreshDatabase::class);

/**
 * An order line of the tenant on a catalog entry with the given internal SKU.
 */
function receiptOrderLine(string $tenantId, ?string $internalSku): PurchaseOrderLine
{
    $supplier = Supplier::factory()->create(['tenant_id' => $tenantId]);
    $catalog = SupplierCatalog::factory()->create(['tenant_id' => $tenantId, 'supplier_id' => $supplier->id, 'internal_sku' => $internalSku]);
    $order = PurchaseOrder::factory()->create(['tenant_id' => $tenantId, 'supplier_id' => $supplier->id]);

    return PurchaseOrderLine::factory()->create(['tenant_id' => $tenantId, 'purchase_order_id' => $order->id, 'supplier_catalog_id' => $catalog->id, 'unit_price' => 12.5]);
}

function receiptEvent(string $tenantId, PurchaseOrderLine $line, float $accepted): GoodsReceivedEvent
{
    return new GoodsReceivedEvent((string) Str::uuid7(), 'GRN-1', (string) $line->purchase_order_id, '', [[
        'purchase_order_line_id' => (string) $line->id, 'quantity_received' => $accepted, 'quantity_accepted' => $accepted, 'unit_cost' => 12.5, 'lot_number' => null,
    ]], $tenantId, $tenantId);
}

test('a goods receipt books the accepted quantity on the stock item of the catalog entry', function (): void {
    $tenant = logisticsTenant();
    $warehouse = logisticsWarehouse(['company_id' => $tenant->id]);
    $bin = logisticsBin($warehouse);
    $item = logisticsItem(['sku' => 'VITE-M6', 'tracking_type' => 'none']);
    logisticsItem(['sku' => 'ALTRO', 'tracking_type' => 'none']);
    $line = receiptOrderLine($tenant->id, 'VITE-M6');

    app(ProcurementGoodsReceivedListener::class)->handle(receiptEvent($tenant->id, $line, 8));

    $quant = StockQuant::query()->where('stock_item_id', $item->id)->sole();
    expect($quant->warehouse_bin_id)->toBe($bin->id)
        ->and((float) $quant->quantity_on_hand)->toBe(8.0)
        ->and(StockQuant::query()->count())->toBe(1);
});

test('a line whose catalog entry matches no stock item moves nothing, instead of guessing one', function (?string $sku): void {
    $tenant = logisticsTenant();
    logisticsBin(logisticsWarehouse(['company_id' => $tenant->id]));
    logisticsItem(['sku' => 'ALTRO', 'tracking_type' => 'none']);

    app(ProcurementGoodsReceivedListener::class)->handle(receiptEvent($tenant->id, receiptOrderLine($tenant->id, $sku), 5));

    expect(StockQuant::query()->count())->toBe(0);
})->with([
    'no internal SKU' => [null],
    'unknown SKU' => ['MANCANTE'],
]);
