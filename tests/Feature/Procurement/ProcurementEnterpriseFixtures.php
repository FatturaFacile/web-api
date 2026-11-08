<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\WarehouseLocationService;

/**
 * A company with an open fiscal calendar for the current year.
 */
function procTenant(string $name): Tenant
{
    $tenant = Tenant::create(['name' => $name, 'legal_name' => $name.' SpA', 'country' => 'IT', 'default_currency' => 'EUR']);
    fiscalCalendar($tenant, (int) now()->year);

    return $tenant;
}

/**
 * A member of the company with the role.
 */
function procMember(Tenant $tenant, string $role): User
{
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode([])]);

    return $user;
}

/**
 * Signs the user in for the next requests on the company.
 */
function procActAs(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->clear();
    app(TenantContext::class)->setTenant($tenant);
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', (string) $tenant->id);
}

/**
 * A supplier through the API; returns its id (also the id of its contact).
 */
function procSupplier(string $name = 'Fornitore Alfa'): string
{
    return (string) test()->postJson('/api/v1/procurement/suppliers', [
        'supplier_code' => 'SUP-'.strtoupper(Str::random(6)),
        'company_name' => $name,
        'vat_number' => 'IT'.random_int(10000000000, 99999999999),
    ])->assertCreated()->json('id');
}

/**
 * A stocked item, a warehouse with a receiving bin, and a catalogue entry of the supplier for the item.
 *
 * @return array{item: StockItem, warehouse: Warehouse, catalog_id: string}
 */
function procStockedCatalog(Tenant $tenant, string $supplierId, string $sku, string $trackingType = 'none'): array
{
    $item = StockItem::factory()->create(['tenant_id' => $tenant->id, 'sku' => $sku, 'tracking_type' => $trackingType, 'current_moving_average_cost' => 0, 'standard_cost' => 0]);
    $warehouse = Warehouse::query()->where('tenant_id', $tenant->id)->first();

    if ($warehouse === null) {
        $warehouse = Warehouse::create(['tenant_id' => $tenant->id, 'code' => 'WH-'.Str::random(5), 'name' => 'Centrale', 'warehouse_type' => 'standard', 'is_active' => true]);
        app(WarehouseLocationService::class)->addBin($warehouse, ['zone_code' => 'RCV', 'zone_type' => 'receiving', 'aisle_code' => 'A', 'rack_code' => 'R', 'bin_code' => 'B1', 'barcode' => 'RCV-'.Str::random(8)]);
    }

    $catalogId = (string) test()->postJson('/api/v1/procurement/catalogs', [
        'supplier_id' => $supplierId,
        'supplier_part_number' => 'P-'.$sku,
        'internal_sku' => $sku,
        'name' => 'Item '.$sku,
        'uom' => 'PCE',
    ])->assertCreated()->json('data.id');

    return ['item' => $item, 'warehouse' => $warehouse, 'catalog_id' => $catalogId];
}

/**
 * A draft purchase order through the API.
 *
 * @param  list<array<string, mixed>>  $lines
 * @param  array<string, mixed>  $extra
 */
function procOrder(string $supplierId, array $lines, array $extra = []): string
{
    return (string) test()->postJson('/api/v1/procurement/purchase-orders', [
        'supplier_id' => $supplierId,
        'order_date' => now()->toDateString(),
        'expected_delivery_date' => now()->addDays(10)->toDateString(),
        'lines' => $lines,
        ...$extra,
    ])->assertCreated()->json('id');
}
