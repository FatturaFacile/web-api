<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\Inventory\Contracts\DoubleEntryMovementServiceInterface;
use Modules\Inventory\DTOs\StockTransferDTO;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockItemCategory;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\WarehouseDefaultBinResolver;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Models\MfgWorkCenter;

/**
 * Two companies, an open fiscal calendar for the current year, a warehouse and users of the first company: owner,
 * production manager, member (writer) and accountant (reader of manufacturing).
 */
function mfgSetUp(object $test): void
{
    $test->tenant = Tenant::create(['name' => 'Officina Alfa', 'legal_name' => 'Officina Alfa SpA', 'country' => 'IT', 'default_currency' => 'EUR']);
    $test->otherTenant = Tenant::create(['name' => 'Officina Beta', 'legal_name' => 'Officina Beta SpA', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->setTenant($test->tenant);
    fiscalCalendar($test->tenant, (int) now()->year);

    $test->warehouse = Warehouse::factory()->create(['tenant_id' => $test->tenant->id, 'is_primary' => true]);
    $test->owner = mfgUser($test, 'owner');
    $test->production = mfgUser($test, 'production');
    $test->member = mfgUser($test, 'member');
    $test->reader = mfgUser($test, 'accountant');
    mfgActAs($test, $test->owner);
}

/**
 * A user who is a member of the company with the given role.
 */
function mfgUser(object $test, string $role, ?Tenant $tenant = null): User
{
    $tenant ??= $test->tenant;
    $user = User::create(['name' => ucfirst($role).' '.Str::random(4), 'email' => Str::uuid().'@example.test', 'password' => 'secret123']);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role, 'permissions' => json_encode([])]);

    return $user;
}

/**
 * Signs the user in on the company for the next requests.
 */
function mfgActAs(object $test, User $user, ?Tenant $tenant = null): void
{
    $tenant ??= $test->tenant;
    Sanctum::actingAs($user);
    $test->withHeader('X-Tenant-Id', $tenant->id);
    app(TenantContext::class)->setTenant($tenant);
}

/**
 * A manufacturing item linked to a new stock item of the company.
 *
 * @param  array<string, mixed>  $attributes
 */
function mfgItem(object $test, string $code, string $type, string $standardCost = '0', array $attributes = [], string $tracking = 'none', ?Tenant $tenant = null): MfgItem
{
    $tenant ??= $test->tenant;

    return app(TenantContext::class)->runWithTenant($tenant, static function () use ($tenant, $code, $type, $standardCost, $attributes, $tracking): MfgItem {
        $category = StockItemCategory::factory()->create(['tenant_id' => $tenant->id]);
        $stockItem = StockItem::factory()->create([
            'tenant_id' => $tenant->id,
            'category_id' => $category->id,
            'sku' => $code.'-'.strtoupper(Str::random(4)),
            'tracking_type' => $tracking,
            'standard_cost' => $standardCost,
            'current_moving_average_cost' => $standardCost,
        ]);

        return MfgItem::create([
            'tenant_id' => $tenant->id,
            'item_code' => $code,
            'name' => $code,
            'item_type' => $type,
            'stock_item_id' => $stockItem->id,
            'uom' => 'PCS',
            'standard_cost' => $standardCost,
            'current_cost' => $standardCost,
            'safety_stock' => '0',
            'min_lot_size' => '0',
            'lead_time_days' => 2,
            'is_active' => true,
            ...$attributes,
        ]);
    });
}

/**
 * A work centre with labour and overhead rates per hour.
 */
function mfgWorkCenter(object $test, string $code, string $laborRate, string $overheadRate, ?Tenant $tenant = null): MfgWorkCenter
{
    $tenant ??= $test->tenant;

    return app(TenantContext::class)->runWithTenant($tenant, static fn (): MfgWorkCenter => MfgWorkCenter::create([
        'tenant_id' => $tenant->id,
        'work_center_code' => $code,
        'name' => $code,
        'capacity_hours_per_day' => '8',
        'efficiency_rate' => '100',
        'availability_rate' => '100',
        'hourly_cost_rate' => $laborRate,
        'hourly_overhead_rate' => $overheadRate,
        'status' => 'active',
    ]));
}

/**
 * Receives stock of the item into the default bin of the warehouse through the inventory module at a unit cost.
 */
function mfgStock(MfgItem $item, Warehouse $warehouse, string $quantity, string $unitCost, ?string $lotId = null): void
{
    $bin = app(WarehouseDefaultBinResolver::class)->resolve((string) $warehouse->id);
    app(DoubleEntryMovementServiceInterface::class)->executeTransfer(new StockTransferDTO(
        stockItemId: (string) $item->stock_item_id,
        sourceBinId: null,
        destinationBinId: (string) $bin->id,
        quantity: (float) $quantity,
        movementType: 'receipt',
        lotId: $lotId,
        customUnitCost: (float) $unitCost,
    ));
}

/**
 * On hand and reserved quantity of the item in the warehouse, from the inventory quants.
 *
 * @return array{on_hand: string, reserved: string}
 */
function mfgQuantities(MfgItem $item, Warehouse $warehouse): array
{
    $binIds = app(WarehouseDefaultBinResolver::class)->bins((string) $warehouse->id)->pluck('id')->all();
    $quants = StockQuant::withoutGlobalScopes()->where('stock_item_id', $item->stock_item_id)->whereIn('warehouse_bin_id', $binIds)->get();

    return [
        'on_hand' => bcadd((string) $quants->sum('quantity_on_hand'), '0', 4),
        'reserved' => bcadd((string) $quants->sum('quantity_reserved'), '0', 4),
    ];
}

/**
 * Debit minus credit of the company's journal lines on an account code.
 */
function mfgLedgerBalance(string $tenantId, string $accountCode): string
{
    $lines = JournalLine::withoutGlobalScopes()
        ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
        ->where('journal_lines.tenant_id', $tenantId)
        ->where('chart_of_accounts.account_code', $accountCode)
        ->whereNull('journal_lines.deleted_at')
        ->get(['journal_lines.debit_amount', 'journal_lines.credit_amount']);

    $balance = '0';
    foreach ($lines as $line) {
        $balance = bcadd($balance, bcsub((string) $line->getAttribute('debit_amount'), (string) $line->getAttribute('credit_amount'), 4), 4);
    }

    return bcadd($balance, '0', 2);
}

/**
 * A finished good made of two components (one backflushed on operation 10) on a two-operation routing, with released
 * BOM, stock of the components and the item standard rolled up through the API.
 *
 * @return array{fg: MfgItem, steel: MfgItem, seal: MfgItem, wc: MfgWorkCenter, bom_id: string, routing_id: string}
 */
function mfgProductSetUp(object $test): array
{
    $fg = mfgItem($test, 'FG-PUMP', 'finished_good');
    $steel = mfgItem($test, 'RM-STEEL', 'raw_material', '10.00');
    $seal = mfgItem($test, 'RM-SEAL', 'raw_material', '2.00');
    $wc = mfgWorkCenter($test, 'WC-ASSY', '60.00', '30.00');

    mfgStock($steel, $test->warehouse, '100', '10.00');
    mfgStock($seal, $test->warehouse, '100', '2.00');

    $bomId = (string) $test->postJson('/api/v1/manufacturing/boms', [
        'mfg_item_id' => $fg->id,
        'name' => 'Pump BOM',
        'components' => [
            ['component_item_id' => $steel->id, 'quantity_per' => '2'],
            ['component_item_id' => $seal->id, 'quantity_per' => '1', 'issue_method' => 'backflush', 'operation_sequence' => 10],
        ],
    ])->assertCreated()->json('data.id');
    $test->postJson("/api/v1/manufacturing/boms/{$bomId}/activate")->assertOk();

    $routingId = (string) $test->postJson('/api/v1/manufacturing/routings', [
        'mfg_item_id' => $fg->id,
        'name' => 'Pump routing',
        'operations' => [
            ['operation_code' => 'OP10', 'name' => 'Machining', 'work_center_id' => $wc->id, 'setup_time_minutes' => '30', 'run_time_minutes_per_unit' => '6'],
            ['operation_code' => 'OP20', 'name' => 'Assembly', 'work_center_id' => $wc->id, 'setup_time_minutes' => '0', 'run_time_minutes_per_unit' => '3'],
        ],
    ])->assertCreated()->json('data.id');

    return ['fg' => $fg->refresh(), 'steel' => $steel, 'seal' => $seal, 'wc' => $wc, 'bom_id' => $bomId, 'routing_id' => $routingId];
}
