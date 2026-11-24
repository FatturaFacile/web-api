<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\WarehouseDefaultBinResolver;

uses(RefreshDatabase::class);

/**
 * @return array{tenant: Tenant, item: StockItem, warehouse: Warehouse}
 */
function gelatoStockSetUp(float $onHand): array
{
    $tenant = Tenant::create([
        'name' => 'Gelateria '.Str::random(5),
        'legal_name' => 'Gelateria SA',
        'vat_number' => str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT),
        'fiscal_code' => str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT),
        'country' => 'CH',
        'default_currency' => 'CHF',
    ]);
    app(TenantContext::class)->setTenant($tenant);

    $user = User::create(['name' => 'owner', 'email' => Str::uuid().'@example.com', 'password' => 'secret123', 'locale' => 'en']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => 'owner', 'permissions' => json_encode([])]);
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', (string) $tenant->id);

    $warehouse = Warehouse::factory()->create(['tenant_id' => $tenant->id, 'is_primary' => true]);
    $item = StockItem::factory()->create(['tenant_id' => $tenant->id, 'uom' => 'KGM']);
    $bin = app(WarehouseDefaultBinResolver::class)->resolve((string) $warehouse->id);
    app(DoubleEntryMovementServiceInterface::class)->executeTransfer(new StockTransferDTO(
        stockItemId: (string) $item->id,
        sourceBinId: null,
        destinationBinId: (string) $bin->id,
        quantity: $onHand,
        movementType: 'receipt',
        customUnitCost: 2.0,
    ));

    return ['tenant' => $tenant, 'item' => $item, 'warehouse' => $warehouse];
}

function gelatoLinkedIngredient(StockItem $item): string
{
    return (string) test()->postJson('/api/v1/gelato/ingredients', [
        'code' => 'MILK_'.Str::upper(Str::random(4)),
        'name' => ['it' => 'Latte', 'de' => 'Milch', 'fr' => 'Lait', 'en' => 'Milk'],
        'category' => 'DAIRY',
        'stock_item_id' => (string) $item->id,
        'stock_unit_grams' => 1000,
    ])->assertCreated()->assertJsonPath('data.stock_item_id', (string) $item->id)->json('data.id');
}

function gelatoOnHand(StockItem $item): float
{
    return (float) StockQuant::query()->where('stock_item_id', $item->id)->sum('quantity_on_hand');
}

it('deducts the raw materials of a lot from inventory through the stock movement service', function (): void {
    $stock = gelatoStockSetUp(10.0);
    $milk = gelatoLinkedIngredient($stock['item']);

    $lot = $this->postJson('/api/v1/gelato/lots', [
        'kind' => 'base',
        'consumptions' => [['ingredient_id' => $milk, 'grams' => 3500]],
    ])->assertCreated()->json('data.id');

    expect(gelatoOnHand($stock['item']))->toBe(6.5);

    $movement = StockMovement::query()->where('origin_document_id', $lot)->firstOrFail();
    expect($movement->origin_document_type)->toBe('gelato_lot')
        ->and((string) $movement->movement_type)->toBe('production_issue');
});

it('refuses a lot that consumes more raw material than the stock holds and records nothing', function (): void {
    $stock = gelatoStockSetUp(1.0);
    $milk = gelatoLinkedIngredient($stock['item']);

    $this->postJson('/api/v1/gelato/lots', [
        'kind' => 'base',
        'consumptions' => [['ingredient_id' => $milk, 'grams' => 5000]],
    ])->assertStatus(422);

    expect(gelatoOnHand($stock['item']))->toBe(1.0)
        ->and(StockMovement::query()->where('origin_document_type', 'gelato_lot')->count())->toBe(0);
});

it('does not touch inventory for an ingredient that is not linked to a stock item', function (): void {
    $stock = gelatoStockSetUp(4.0);
    $unlinked = (string) $this->postJson('/api/v1/gelato/ingredients', [
        'code' => 'SUGAR_UNLINKED',
        'name' => ['it' => 'Zucchero', 'de' => 'Zucker', 'fr' => 'Sucre', 'en' => 'Sugar'],
        'category' => 'SUGAR',
    ])->assertCreated()->json('data.id');

    $this->postJson('/api/v1/gelato/lots', ['kind' => 'base', 'consumptions' => [['ingredient_id' => $unlinked, 'grams' => 900]]])->assertCreated();

    expect(gelatoOnHand($stock['item']))->toBe(4.0);
});

it('rejects an ingredient linked to a stock item of another company', function (): void {
    gelatoStockSetUp(1.0);
    // The active tenant context always wins over an explicit tenant_id, so the foreign item is created in its own context.
    $otherTenant = Tenant::create(['name' => 'Altra', 'legal_name' => 'Altra SA', 'country' => 'CH', 'default_currency' => 'CHF']);
    $foreign = app(TenantContext::class)->runWithTenant($otherTenant, fn (): StockItem => StockItem::factory()->create(['tenant_id' => $otherTenant->id]));

    $this->postJson('/api/v1/gelato/ingredients', [
        'code' => 'FOREIGN_LINK',
        'name' => ['it' => 'x', 'de' => 'x', 'fr' => 'x', 'en' => 'x'],
        'category' => 'DAIRY',
        'stock_item_id' => (string) $foreign->id,
    ])->assertStatus(422)->assertJsonValidationErrors('stock_item_id');
});
