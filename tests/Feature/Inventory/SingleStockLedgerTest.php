<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Inventory\Listeners\ProcurementGoodsReceivedListener;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockMovement as ModuleStockMovement;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\StockShortfall;
use Modules\Inventory\Services\LegacyStockMovementImporter;
use Modules\Inventory\Services\ProductStockLedger;
use Modules\Procurement\Events\GoodsReceivedEvent;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderLine;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierCatalog;

/*
 * One stock truth: the app's write paths (till, delivery notes, adjustments) move the inventory module's quants, the
 * module's movements show in the app's stock levels, and the legacy app-style movements are booked into the module once.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Ledger Unico SRL', 'legal_name' => 'Ledger Unico SRL', 'country' => 'IT', 'default_currency' => 'EUR']);

    $user = User::create(['name' => 'Giulia', 'email' => 'giulia@ledger.test', 'password' => bcrypt('secret123'), 'locale' => 'en']);
    $user->tenants()->attach($this->tenant->id, ['id' => (string) Str::uuid(), 'role' => 'admin']);
    Sanctum::actingAs($user);

    $this->warehouse = Warehouse::create(['tenant_id' => $this->tenant->id, 'code' => 'MAIN', 'name' => 'Main warehouse', 'is_primary' => true]);
    $this->product = Product::create([
        'tenant_id' => $this->tenant->id, 'sku' => 'LED-1', 'name' => 'Lamp', 'cost_price' => 4, 'sale_price' => 10,
        'tax_rate' => 22, 'track_inventory' => true,
    ]);

    $this->session = null;
    $this->terminal = ledgerApi()->postJson('/api/v1/retail/pos/terminals', ['terminal_code' => 'T-01', 'name' => 'Front desk'])->assertCreated()->json('data');
});

function ledgerApi()
{
    return test()->withHeader('X-Tenant-Id', test()->tenant->id);
}

function ledgerAdjust(string $productId, float $quantity): void
{
    ledgerApi()->postJson('/api/v1/inventory/stock-adjustments', ['product_id' => $productId, 'quantity' => $quantity])->assertCreated();
}

/**
 * @return array<string, mixed>
 */
function ledgerLevel(string $productId): array
{
    return collect(ledgerApi()->getJson('/api/v1/inventory/stock-levels')->assertOk()->json('data'))->firstWhere('product_id', $productId) ?? [];
}

function ledgerOnHand(string $productId): float
{
    $item = StockItem::query()->where('product_id', $productId)->first();

    return $item === null ? 0.0 : (float) StockQuant::query()->where('stock_item_id', $item->id)->sum('quantity_on_hand');
}

function ledgerSale(int $quantity): string
{
    test()->session ??= ledgerApi()->postJson('/api/v1/retail/pos/sessions', ['pos_terminal_id' => test()->terminal['id'], 'opening_float' => 0])->assertCreated()->json('data.id');

    return ledgerApi()->postJson('/api/v1/retail/pos/sales', [
        'pos_session_id' => test()->session,
        'payment_method' => 'card',
        'items' => [['product_id' => test()->product->id, 'quantity' => $quantity]],
    ])->assertCreated()->json('data.id');
}

function ledgerReceive(Tenant $tenant, string $sku, float $quantity): void
{
    app(TenantContext::class)->setTenant($tenant);
    DB::table('warehouses')->where('id', test()->warehouse->id)->update(['company_id' => $tenant->id, 'is_active' => true]);

    $supplier = Supplier::factory()->create(['tenant_id' => $tenant->id]);
    $catalog = SupplierCatalog::factory()->create(['tenant_id' => $tenant->id, 'supplier_id' => $supplier->id, 'internal_sku' => $sku]);
    $order = PurchaseOrder::factory()->create(['tenant_id' => $tenant->id, 'supplier_id' => $supplier->id]);
    $line = PurchaseOrderLine::factory()->create(['tenant_id' => $tenant->id, 'purchase_order_id' => $order->id, 'supplier_catalog_id' => $catalog->id, 'unit_price' => 4]);

    app(ProcurementGoodsReceivedListener::class)->handle(new GoodsReceivedEvent((string) Str::uuid7(), 'GRN-1', (string) $order->id, '', [[
        'purchase_order_line_id' => (string) $line->id, 'quantity_received' => $quantity, 'quantity_accepted' => $quantity, 'unit_cost' => 4.0, 'lot_number' => null,
    ]], (string) $tenant->id, (string) $tenant->id));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function legacyMovement(array $attributes): void
{
    DB::table('stock_movements')->insert($attributes + [
        'id' => (string) Str::uuid7(),
        'tenant_id' => test()->tenant->id,
        'warehouse_id' => test()->warehouse->id,
        'unit_cost' => 4,
        'created_at' => now()->subDays(10),
        'updated_at' => now()->subDays(10),
    ]);
}

test('a till sale takes the stock out of the module quants and the app level follows', function (): void {
    ledgerAdjust($this->product->id, 10);

    $item = StockItem::query()->where('product_id', $this->product->id)->sole();
    expect($item->sku)->toBe('LED-1')->and(ledgerOnHand($this->product->id))->toBe(10.0);

    $saleId = ledgerSale(3);

    expect(ledgerOnHand($this->product->id))->toBe(7.0)
        ->and(ledgerLevel($this->product->id))->toMatchArray(['quantity' => 7, 'on_hand' => 7, 'reserved' => 0, 'available' => 7, 'shortfall' => 0, 'sku' => 'LED-1', 'name' => 'Lamp']);

    $movement = ModuleStockMovement::query()->where('reference_id', $saleId)->sole();
    expect($movement->stock_item_id)->toBe($item->id)
        ->and($movement->product_id)->toBe($this->product->id)
        ->and($movement->type)->toBe('out')
        ->and($movement->movement_type)->toBe('customer_delivery')
        ->and($movement->source_location_id)->not->toBeNull()
        ->and($movement->destination_location_id)->toBeNull()
        ->and((float) $movement->quantity)->toBe(3.0)
        ->and(DB::table('stock_movements')->whereNull('stock_item_id')->count())->toBe(0);
});

test('a goods receipt of the module raises the stock level the app shows', function (): void {
    ledgerAdjust($this->product->id, 2);

    ledgerReceive($this->tenant, 'LED-1', 5);

    expect(ledgerLevel($this->product->id)['quantity'])->toEqual(7.0)
        ->and(ledgerOnHand($this->product->id))->toBe(7.0)
        ->and(app(StockService::class)->quantityOf($this->product->id))->toBe(7.0);
});

test('a delivery note moves the quants and a till refund puts the stock back', function (): void {
    ledgerAdjust($this->product->id, 10);
    $contact = Contact::create(['tenant_id' => $this->tenant->id, 'company_name' => 'Cliente Milano SpA', 'country' => 'IT']);
    $order = ledgerApi()->postJson('/api/v1/sales/orders', [
        'contact_id' => $contact->id, 'date' => '2026-03-10', 'currency' => 'EUR', 'status' => 'confirmed',
        'items' => [['product_id' => $this->product->id, 'description' => 'Lamp', 'quantity' => 4, 'unit_price' => 10, 'tax_rate' => 22]],
    ])->assertCreated()->json('data.id');

    $noteId = ledgerApi()->postJson("/api/v1/sales/orders/{$order}/delivery-notes")->assertCreated()->json('data.id');

    expect(ledgerOnHand($this->product->id))->toBe(6.0)
        ->and(ModuleStockMovement::query()->where('reference_id', $noteId)->where('type', 'out')->count())->toBe(1);

    $saleId = ledgerSale(2);
    expect(ledgerOnHand($this->product->id))->toBe(4.0);

    ledgerApi()->postJson("/api/v1/retail/pos/sales/{$saleId}/refund")->assertOk();

    expect(ledgerOnHand($this->product->id))->toBe(6.0)
        ->and(ledgerLevel($this->product->id)['quantity'])->toEqual(6.0)
        ->and(ModuleStockMovement::query()->where('reference_id', $saleId)->where('movement_type', 'customer_return')->count())->toBe(1);
});

test('two sales of the last unit both go through: the second leaves a shortfall that the next receipt settles', function (): void {
    ledgerAdjust($this->product->id, 1);

    $lockedQuants = 0;
    DB::listen(function ($query) use (&$lockedQuants): void {
        if (str_contains($query->sql, 'from "stock_quants"') && str_contains($query->sql, 'for update')) {
            $lockedQuants++;
        }
    });

    $first = ledgerSale(1);
    $second = ledgerSale(1);

    expect($lockedQuants)->toBeGreaterThan(0)
        ->and(ledgerOnHand($this->product->id))->toBe(0.0)
        ->and(ledgerLevel($this->product->id))->toMatchArray(['quantity' => -1, 'on_hand' => 0, 'shortfall' => 1]);

    $shortfall = StockShortfall::query()->sole();
    expect($shortfall->origin_document_id)->toBe($second)
        ->and((float) $shortfall->quantity_open)->toBe(1.0)
        ->and(ModuleStockMovement::query()->where('reference_id', $first)->sum('quantity'))->toEqual(1.0)
        ->and(ModuleStockMovement::query()->where('reference_id', $second)->where('movement_type', 'customer_delivery')->sum('quantity'))->toEqual(1.0)
        ->and(ModuleStockMovement::query()->where('reference_id', $second)->where('movement_type', ProductStockLedger::COVER_MOVEMENT_TYPE)->sum('quantity'))->toEqual(1.0);

    ledgerReceive($this->tenant, 'LED-1', 5);

    expect(ledgerOnHand($this->product->id))->toBe(4.0)
        ->and(ledgerLevel($this->product->id))->toMatchArray(['quantity' => 4, 'on_hand' => 4, 'shortfall' => 0])
        ->and((float) $shortfall->refresh()->quantity_open)->toBe(0.0)
        ->and($shortfall->settled_at)->not->toBeNull()
        ->and(ModuleStockMovement::query()->where('movement_type', ProductStockLedger::SETTLEMENT_MOVEMENT_TYPE)->where('reference_id', $second)->sum('quantity'))->toEqual(1.0);
});

test('a negative adjustment beyond the stock is booked in full and shows as negative stock', function (): void {
    ledgerAdjust($this->product->id, 2);
    ledgerAdjust($this->product->id, -5);

    expect(ledgerLevel($this->product->id))->toMatchArray(['quantity' => -3, 'on_hand' => 0, 'shortfall' => 3]);

    ledgerAdjust($this->product->id, 1);

    expect(ledgerLevel($this->product->id))->toMatchArray(['quantity' => -2, 'on_hand' => 0, 'shortfall' => 2]);
});

test('the legacy app movements are booked once into the module, on top of what it already holds', function (): void {
    app(TenantContext::class)->setTenant($this->tenant);
    $short = Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'LED-2', 'name' => 'Bulb', 'cost_price' => 1, 'sale_price' => 2, 'tax_rate' => 22, 'track_inventory' => true]);
    $even = Product::create(['tenant_id' => $this->tenant->id, 'sku' => 'LED-3', 'name' => 'Socket', 'cost_price' => 1, 'sale_price' => 2, 'tax_rate' => 22, 'track_inventory' => true]);

    ledgerAdjust($this->product->id, 4);

    legacyMovement(['product_id' => $this->product->id, 'type' => 'in', 'quantity' => 10]);
    legacyMovement(['product_id' => $this->product->id, 'type' => 'out', 'quantity' => 3]);
    legacyMovement(['product_id' => $this->product->id, 'type' => 'adjustment', 'quantity' => -2]);
    legacyMovement(['product_id' => $short->id, 'type' => 'out', 'quantity' => 3]);
    legacyMovement(['product_id' => $even->id, 'type' => 'in', 'quantity' => 2]);
    legacyMovement(['product_id' => $even->id, 'type' => 'out', 'quantity' => 2]);

    $importer = app(LegacyStockMovementImporter::class);
    $first = $importer->import();

    expect($first)->toBe(['groups' => 3, 'booked' => 2, 'skipped' => 0])
        ->and(ledgerOnHand($this->product->id))->toBe(9.0)
        ->and(ledgerLevel($this->product->id)['quantity'])->toEqual(9.0)
        ->and(ledgerLevel($short->id))->toMatchArray(['quantity' => -3, 'shortfall' => 3])
        ->and(ledgerLevel($even->id))->toBe([])
        ->and(DB::table('stock_movements')->whereNull('stock_item_id')->whereNull('legacy_migrated_at')->count())->toBe(0);

    $opening = ModuleStockMovement::query()->where('movement_type', LegacyStockMovementImporter::MOVEMENT_TYPE)->where('product_id', $this->product->id)->sole();
    expect(DB::table('stock_movements')->where('legacy_migration_movement_id', $opening->id)->count())->toBe(3)
        ->and($opening->executed_at->toDateString())->toBe(now()->subDays(10)->toDateString());

    expect($importer->import())->toBe(['groups' => 0, 'booked' => 0, 'skipped' => 0])
        ->and(ledgerOnHand($this->product->id))->toBe(9.0)
        ->and(ledgerLevel($short->id)['quantity'])->toEqual(-3.0);

    legacyMovement(['product_id' => $this->product->id, 'type' => 'in', 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()]);

    expect($importer->import())->toBe(['groups' => 1, 'booked' => 1, 'skipped' => 0])
        ->and(ledgerOnHand($this->product->id))->toBe(10.0);
});
