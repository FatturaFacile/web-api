<?php

declare(strict_types=1);

namespace Modules\Inventory\Services;

use App\Models\Product;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Books the stock the app kept on its own into the inventory module, once.
 *
 * Before the single ledger the app wrote `stock_movements` rows with a product and a warehouse but no stock item
 * (`in`, `out`, signed `adjustment`) and summed them for its stock levels; the module never saw them. For every tenant,
 * product and warehouse, the net quantity of the rows not migrated yet is booked into the module as an opening balance
 * dated at the last of those rows, on top of what the module already holds: a positive net comes in, a negative net
 * goes out with the negative stock policy of ProductStockLedger (what the module has is used first, the rest stays as a
 * shortfall). The rows are marked (`legacy_migrated_at`, `legacy_migration_movement_id`) in the same transaction, under
 * row locks, so a second run or a concurrent one books nothing again. Rows are never changed otherwise or deleted.
 */
final readonly class LegacyStockMovementImporter
{
    public const string MOVEMENT_TYPE = 'opening_balance';

    public function __construct(
        private ProductStockLedger $ledger,
        private TenantContext $tenantContext,
    ) {}

    /**
     * @return array{groups: int, booked: int, skipped: int}
     */
    public function import(): array
    {
        $groups = DB::table('stock_movements')
            ->whereNull('stock_item_id')
            ->whereNotNull('product_id')
            ->whereNotNull('tenant_id')
            ->whereNull('legacy_migrated_at')
            ->select(['tenant_id', 'product_id', 'warehouse_id'])
            ->distinct()
            ->get();

        $result = ['groups' => $groups->count(), 'booked' => 0, 'skipped' => 0];

        foreach ($groups as $group) {
            $tenant = Tenant::query()->find($group->tenant_id);

            if ($tenant === null) {
                $result['skipped']++;

                continue;
            }

            $booked = $this->tenantContext->runWithTenant($tenant, fn (): ?bool => $this->importGroup(
                (string) $group->tenant_id,
                (string) $group->product_id,
                $group->warehouse_id !== null ? (string) $group->warehouse_id : null,
            ));

            match ($booked) {
                true => $result['booked']++,
                null => $result['skipped']++,
                false => null,
            };
        }

        return $result;
    }

    /**
     * One tenant, product and warehouse. Returns true when a movement was booked, false when the rows net to zero (they
     * are marked all the same), null when the group cannot be booked (no product or warehouse) and stays unmigrated.
     */
    private function importGroup(string $tenantId, string $productId, ?string $warehouseId): ?bool
    {
        return DB::transaction(function () use ($tenantId, $productId, $warehouseId): ?bool {
            $rows = DB::table('stock_movements')
                ->whereNull('stock_item_id')
                ->whereNull('legacy_migrated_at')
                ->where('tenant_id', $tenantId)
                ->where('product_id', $productId)
                ->when($warehouseId !== null, static fn ($query) => $query->where('warehouse_id', $warehouseId), static fn ($query) => $query->whereNull('warehouse_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'type', 'quantity', 'unit_cost', 'created_at']);

            if ($rows->isEmpty()) {
                return false;
            }

            $product = Product::withoutGlobalScopes()->find($productId);
            $warehouseId ??= DB::table('warehouses')->where('tenant_id', $tenantId)->whereNull('deleted_at')->orderByDesc('is_primary')->orderBy('created_at')->value('id');

            if ($product === null || $warehouseId === null) {
                Log::warning('Legacy stock movements not booked into the inventory module: product or warehouse missing.', ['tenant_id' => $tenantId, 'product_id' => $productId]);

                return null;
            }

            $net = round($rows->sum(static fn ($row): float => match ($row->type) {
                'in' => (float) $row->quantity,
                'out' => -(float) $row->quantity,
                'adjustment' => (float) $row->quantity,
                default => 0.0,
            }), 4);

            $movementId = null;

            if ($net !== 0.0) {
                $context = [
                    'movement_type' => self::MOVEMENT_TYPE,
                    'app_type' => $net > 0 ? 'in' : 'out',
                    'unit_cost' => (float) $product->cost_price,
                    'notes' => 'Opening balance from the app stock movements before the single stock ledger.',
                    'executed_at' => Carbon::parse((string) $rows->max('created_at'))->toIso8601String(),
                ];

                $movement = $net > 0
                    ? $this->ledger->receive($product, (string) $warehouseId, $net, $context)
                    : $this->ledger->issue($product, (string) $warehouseId, abs($net), $context);
                $movementId = $movement->id;
            }

            DB::table('stock_movements')->whereIn('id', $rows->pluck('id'))->update([
                'legacy_migrated_at' => now(),
                'legacy_migration_movement_id' => $movementId,
            ]);

            return $movementId !== null;
        });
    }
}
