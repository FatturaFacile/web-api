<?php

declare(strict_types=1);

namespace Modules\Inventory\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Inventory\Contracts\StockValuationServiceInterface;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\Warehouse;

final class RecalculateInventoryValuationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly ?string $tenantId = null,
        public readonly string $valuationMethod = 'FIFO'
    ) {
        $this->afterCommit();
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
}
