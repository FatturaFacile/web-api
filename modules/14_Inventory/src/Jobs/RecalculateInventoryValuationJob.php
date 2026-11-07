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

    public function handle(StockValuationServiceInterface $service): void
    {
        $warehouses = Warehouse::where('is_active', true)->get();

        $itemsQuery = StockItem::where('is_active', true);
        if ($this->tenantId !== null) {
            $itemsQuery->where('tenant_id', $this->tenantId);
        }

        $itemsQuery->chunkById(100, function ($items) use ($service, $warehouses): void {
            foreach ($items as $item) {
                foreach ($warehouses as $warehouse) {
                    $service->capturePeriodicSnapshot($item, $warehouse->id, $this->valuationMethod);
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
