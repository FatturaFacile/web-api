<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Actions;

use Modules\Manufacturing\Contracts\ProductionExecutionServiceInterface;
use Modules\Manufacturing\Models\MfgProductionOrder;

final readonly class CompleteProductionOrderAction
{
    public function __construct(
        private ProductionExecutionServiceInterface $executionService
    ) {}

    public function execute(string $productionOrderId): MfgProductionOrder
    {
        return $this->executionService->completeOrder($productionOrderId);
    }
}
