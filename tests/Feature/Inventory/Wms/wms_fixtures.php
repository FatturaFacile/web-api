<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Support\Quantity;

require_once __DIR__.'/../../Logistics/fixtures.php';

/**
 * Runs the callback inside another company, then gives the active company back: records made there must answer 404.
 *
 * @template T
 *
 * @param  callable(Tenant): T  $callback
 * @return T
 */
function wmsInOtherTenant(callable $callback): mixed
{
    $current = app(TenantContext::class)->getTenant();
    $other = Tenant::create(['name' => 'Altra Logistica', 'legal_name' => 'Altra Logistica SA', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->setTenant($other);

    try {
        return $callback($other);
    } finally {
        app(TenantContext::class)->setTenant($current);
    }
}

/**
 * Stock booked through the movement service (not a raw quant), so ledger and quant agree.
 */
function wmsReceive(WarehouseBin $bin, StockItem $item, string $quantity, ?string $lotId = null, ?float $unitCost = null): void
{
    app(DoubleEntryMovementServiceInterface::class)->executeTransfer(new StockTransferDTO(
        stockItemId: (string) $item->id,
        sourceBinId: null,
        destinationBinId: (string) $bin->id,
        quantity: (float) $quantity,
        movementType: 'supplier_receipt',
        lotId: $lotId,
        customUnitCost: $unitCost,
    ));
}

function wmsOnHand(WarehouseBin $bin, StockItem $item): string
{
    return Quantity::of((string) StockQuant::query()->where('warehouse_bin_id', $bin->id)->where('stock_item_id', $item->id)->sum('quantity_on_hand'));
}

/**
 * A tracked catalog product with its stock item.
 *
 * @return array{product: Product, item: StockItem}
 */
function wmsProduct(string $sku, string $cost = '5'): array
{
    $product = Product::create(['sku' => $sku, 'name' => 'Prodotto '.$sku, 'cost_price' => $cost, 'sale_price' => '10', 'tax_rate' => 22, 'track_inventory' => true]);
    $item = logisticsItem(['product_id' => $product->id, 'sku' => $sku, 'tracking_type' => 'none', 'current_moving_average_cost' => $cost]);

    return ['product' => $product, 'item' => $item];
}

/**
 * A confirmed sales order with one line per [product, quantity].
 *
 * @param  list<array{0: Product, 1: string}>  $lines
 */
function wmsSalesOrder(array $lines, string $status = 'confirmed'): SalesOrder
{
    $contact = Contact::factory()->create(['tenant_id' => app(TenantContext::class)->getTenantId()]);
    $order = SalesOrder::create(['contact_id' => $contact->id, 'order_number' => 'SO-'.uniqid(), 'date' => now()->toDateString(), 'currency' => 'EUR', 'status' => $status]);

    foreach ($lines as [$product, $quantity]) {
        $order->items()->create(['product_id' => $product->id, 'description' => $product->name, 'quantity' => $quantity, 'unit_price' => 10, 'tax_rate' => 22, 'line_total' => 10]);
    }

    return $order;
}
