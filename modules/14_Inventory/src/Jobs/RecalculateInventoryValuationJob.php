<?php

declare(strict_types=1);

namespace Modules\Inventory\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Contracts\StockValuationServiceInterface;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockQuant;
use Modules\Inventory\Models\Warehouse;
use Throwable;

/**
 * Captures the periodic valuation snapshot of every active item in every active warehouse. Two runs for the same tenant
 * would write the snapshots twice, so the job is unique per tenant and method and is not retried halfway through.
 */
final class RecalculateInventoryValuationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    /**
     * A retry would capture again the snapshots the failed run already wrote.
     */
    public int $tries = 1;

    /**
     * Released by the end of the run; this only bounds a lock left behind by a worker that died.
     */
    public int $uniqueFor = 3600;

    public function __construct(
        public readonly ?string $tenantId = null,
        public readonly string $valuationMethod = 'FIFO'
    ) {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return ($this->tenantId ?? 'all').':'.$this->valuationMethod;
    }

    /**
     * One snapshot per item and warehouse of the tenant where the item has stock, valued with the method of the item's
     * category (StockValuationService::generateDailySnapshot). The `valuationMethod` of the job is kept for the unique
     * id of queued jobs only: the method is the category's, not the job's.
     */
    public function handle(StockValuationServiceInterface $service): void
    {
        $warehouses = Warehouse::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->when($this->tenantId !== null, fn ($query) => $query->where('tenant_id', $this->tenantId))
            ->get();

        StockItem::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->when($this->tenantId !== null, fn ($query) => $query->where('tenant_id', $this->tenantId))
            ->chunkById(100, function ($items) use ($service, $warehouses): void {
                foreach ($items as $item) {
                    foreach ($warehouses->where('tenant_id', $item->tenant_id) as $warehouse) {
                        $hasStock = StockQuant::withoutGlobalScopes()
                            ->whereNull('deleted_at')
                            ->where('stock_item_id', $item->id)
                            ->where('quantity_on_hand', '>', 0)
                            ->whereHas('bin.rack.aisle.zone', static fn ($zone) => $zone->withoutGlobalScopes()->where('warehouse_id', $warehouse->id))
                            ->exists();

                        if ($hasStock) {
                            $service->generateDailySnapshot($item, (string) $warehouse->id);
                        }
                    }
                }
            });
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Inventory valuation recalculation failed.', [
            'tenant_id' => $this->tenantId,
            'valuation_method' => $this->valuationMethod,
            'error' => $exception?->getMessage(),
        ]);
    }
}
