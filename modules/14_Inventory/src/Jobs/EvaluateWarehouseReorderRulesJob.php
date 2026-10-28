<?php

declare(strict_types=1);

namespace Modules\Inventory\Jobs;

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

    public bool $afterCommit = true;

    public function __construct(
        public readonly ?string $tenantId = null
    ) {}

    public function handle(InventoryReplenishmentServiceInterface $service): void
    {
        $query = StockReorderRule::query();

        if ($this->tenantId !== null) {
            $query->where('tenant_id', $this->tenantId);
        }

        $query->chunkById(100, function ($rules) use ($service): void {
            foreach ($rules as $rule) {
                $service->triggerReorderIfThresholdReached($rule);
            }
        });
    }
}
