<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockItemCategory;
use Modules\Inventory\Models\StockMovement;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseAisle;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Models\WarehouseRack;
use Modules\Inventory\Models\WarehouseZone;
use Modules\Procurement\Models\PurchaseReceipt;

/**
 * Shared fixtures for the inventory valuation / landed cost / inventory book tests
 * (FatturaFacile/FatturaFacile#144).
 */
final class InventoryValuationFixtures
{
    /**
     * Creates a fresh tenant and a member user, activates the tenant and authenticates the user.
     *
     * @param  list<string>  $permissions
     * @return array{tenant: Tenant, user: User}
     */
    public static function actingTenantUser(string $role = 'owner', array $permissions = []): array
    {
        $tenant = Tenant::create([
            'name' => 'Valuation Tenant '.Str::random(6),
            'legal_name' => 'Valuation Test SpA',
            'country' => 'IT',
            'default_currency' => 'EUR',
        ]);

        $user = self::memberOf($tenant, $role, $permissions);
        self::activate($tenant, $user);

        return ['tenant' => $tenant, 'user' => $user];
    }

    /**
     * @param  list<string>  $permissions
     */
    public static function memberOf(Tenant $tenant, string $role = 'owner', array $permissions = []): User
    {
        $user = User::create([
            'name' => 'Valuation User '.Str::random(4),
            'email' => 'valuation_'.Str::lower(Str::random(10)).'@inventory.test',
            'password' => bcrypt('password123'),
        ]);

        DB::table('tenant_user')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => $role,
            'permissions' => json_encode($permissions),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    public static function activate(Tenant $tenant, User $user): void
    {
        app(TenantContext::class)->setTenant($tenant);
        Sanctum::actingAs($user);
        // The active tenant of an HTTP request is the one named by the header, so a user outside it is refused.
        test()->withHeader('X-Tenant-Id', (string) $tenant->id);
    }

    /**
     * @return array{warehouse: Warehouse, bin: WarehouseBin}
     */
    public static function warehouseWithBin(string $tenantId): array
    {
        $warehouse = Warehouse::create([
            'tenant_id' => $tenantId,
            'company_id' => $tenantId,
            'code' => 'WH-'.strtoupper(Str::random(5)),
            'name' => 'Valuation Warehouse',
            'warehouse_type' => 'standard',
            'is_active' => true,
        ]);
        $zone = WarehouseZone::create(['tenant_id' => $tenantId, 'warehouse_id' => $warehouse->id, 'code' => 'Z1', 'name' => 'Storage', 'zone_type' => 'storage']);
        $aisle = WarehouseAisle::create(['tenant_id' => $tenantId, 'warehouse_zone_id' => $zone->id, 'code' => 'A1']);
        $rack = WarehouseRack::create(['tenant_id' => $tenantId, 'warehouse_aisle_id' => $aisle->id, 'code' => 'R1']);
        $bin = WarehouseBin::create([
            'tenant_id' => $tenantId,
            'warehouse_rack_id' => $rack->id,
            'code' => 'BIN-'.strtoupper(Str::random(4)),
            'barcode' => 'VAL-'.strtoupper(Str::random(10)),
            'is_locked' => false,
        ]);

        return ['warehouse' => $warehouse, 'bin' => $bin];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function item(string $tenantId, string $categoryMethod = 'fifo', array $attributes = []): StockItem
    {
        $category = StockItemCategory::create([
            'tenant_id' => $tenantId,
            'company_id' => $tenantId,
            'code' => 'CAT-'.strtoupper(Str::random(6)),
            'name' => 'Valuation Category',
            'valuation_method' => $categoryMethod,
        ]);

        return StockItem::create(array_merge([
            'tenant_id' => $tenantId,
            'company_id' => $tenantId,
            'category_id' => $category->id,
            'sku' => 'VAL-'.strtoupper(Str::random(8)),
            'name' => 'Valuation Item',
            'uom' => 'PCE',
            'tracking_type' => 'none',
            'standard_cost' => 0,
            'current_moving_average_cost' => 0,
            'weight_kg' => 1.0,
            'volume_m3' => 0.01,
            'is_active' => true,
        ], $attributes));
    }

    /**
     * Fiscal year (calendar year) with a single period covering it.
     */
    public static function fiscalYear(string $tenantId, int $year, string $yearStatus = 'open', string $periodStatus = 'open'): FiscalYear
    {
        $fiscalYear = FiscalYear::create([
            'tenant_id' => $tenantId,
            'year_code' => 'FY'.$year.'-'.Str::random(4),
            'start_date' => "{$year}-01-01",
            'end_date' => "{$year}-12-31",
            'status' => $yearStatus,
            'is_locked' => false,
        ]);

        FiscalPeriod::create([
            'tenant_id' => $tenantId,
            'fiscal_year_id' => $fiscalYear->id,
            'period_number' => 1,
            'period_name' => "FY {$year}",
            'start_date' => "{$year}-01-01",
            'end_date' => "{$year}-12-31",
            'status' => $periodStatus,
        ]);

        return $fiscalYear;
    }

    public static function purchaseReceipt(string $tenantId): PurchaseReceipt
    {
        return PurchaseReceipt::factory()->create(['tenant_id' => $tenantId]);
    }

    /**
     * Books a goods receipt movement linked to $receiptId (landed cost target).
     */
    public static function receive(StockItem $item, WarehouseBin $bin, float $quantity, float $unitCost, ?string $receiptId = null, ?string $lotId = null): StockMovement
    {
        return app(DoubleEntryMovementServiceInterface::class)->executeTransfer(new StockTransferDTO(
            stockItemId: (string) $item->id,
            sourceBinId: null,
            destinationBinId: (string) $bin->id,
            quantity: $quantity,
            movementType: 'PURCHASE_RECEIPT',
            lotId: $lotId,
            originDocumentType: $receiptId !== null ? 'purchase_receipt' : null,
            originDocumentId: $receiptId,
            customUnitCost: $unitCost,
        ));
    }

    public static function issue(StockItem $item, WarehouseBin $bin, float $quantity, ?string $lotId = null): StockMovement
    {
        return app(DoubleEntryMovementServiceInterface::class)->executeTransfer(new StockTransferDTO(
            stockItemId: (string) $item->id,
            sourceBinId: (string) $bin->id,
            destinationBinId: null,
            quantity: $quantity,
            movementType: 'sales_issue',
            lotId: $lotId,
            originDocumentType: 'sales_order',
            originDocumentId: (string) Str::uuid7(),
        ));
    }
}
