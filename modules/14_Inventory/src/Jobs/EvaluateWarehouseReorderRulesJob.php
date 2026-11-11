<?php

declare(strict_types=1);

namespace Modules\Inventory\Jobs;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Inventory\Contracts\InventoryReplenishmentServiceInterface;
use Modules\Inventory\Models\StockReorderRule;

final class EvaluateWarehouseReorderRulesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly ?string $tenantId = null
    ) {
        $this->afterCommit();
    }

    public function handle(InventoryReplenishmentServiceInterface $service): void
    {
        $query = StockReorderRule::query();

        if ($this->tenantId !== null) {
            $query->where('tenant_id', $this->tenantId);
        }

        $query->chunkById(100, function ($rules) use ($service): void {
            foreach ($rules as $rule) {
                $service->evaluateReorderRule($rule);
                $service->evaluateMinimumStock($rule);
            }
        });

        $products = Product::withoutGlobalScopes()->whereNull('deleted_at')->where('track_inventory', true)->where('is_active', true)->where('min_stock', '>', 0);

        if ($this->tenantId !== null) {
            $products->where('tenant_id', $this->tenantId);
        }

        $products->chunkById(100, function ($chunk) use ($service): void {
            foreach ($chunk as $product) {
                $service->evaluateProductMinimumStock($product);
            }
        });
    }
}
