<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Manufacturing\Contracts\ProductionCostingServiceInterface;

final class PostProductionCostVarianceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $productionOrderId
    ) {}

    public function handle(ProductionCostingServiceInterface $costingService): void
    {
        $costingService->postProductionWipAccounting($this->productionOrderId);
    }
}
