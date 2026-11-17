<?php

declare(strict_types=1);

namespace Modules\Inventory\Console;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Contracts\InventoryCostingServiceInterface;
use Modules\Inventory\Models\StockItem;

/**
 * Converts on-hand stock not yet covered by cost layers into one opening-balance layer per item
 * at the current moving average (or standard) cost. Idempotent: covered stock is skipped.
 */
final class BackfillInventoryCostLayersCommand extends Command
{
    protected $signature = 'inventory:backfill-cost-layers {--tenant= : Limit to one tenant id}';

    protected $description = 'Create opening-balance inventory cost layers for on-hand stock without layers (FatturaFacile#144)';

    public function handle(InventoryCostingServiceInterface $costing, TenantContext $tenantContext): int
    {
        $tenants = Tenant::query()
            ->when($this->option('tenant') !== null, fn ($query) => $query->whereKey((string) $this->option('tenant')))
            ->get();

        $created = 0;
        foreach ($tenants as $tenant) {
            $tenantContext->runWithTenant($tenant, function () use ($costing, &$created): void {
                StockItem::query()->orderBy('id')->chunkById(200, function ($items) use ($costing, &$created): void {
                    foreach ($items as $item) {
                        DB::transaction(function () use ($costing, $item, &$created): void {
                            $locked = StockItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
                            if ($costing->ensureOpeningLayers($locked) !== null) {
                                $created++;
                            }
                        });
                    }
                });
            });
        }

        $this->info("Opening cost layers created: {$created}");

        return self::SUCCESS;
    }
}
