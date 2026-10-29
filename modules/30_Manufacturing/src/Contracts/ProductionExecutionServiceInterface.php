<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Contracts;

use Modules\Manufacturing\Models\MfgProductionOrder;

interface ProductionExecutionServiceInterface
{
    public function releaseOrder(string $productionOrderId): MfgProductionOrder;

    public function startOrder(string $productionOrderId): MfgProductionOrder;

    public function recordPartialCompletion(string $productionOrderId, float $completedQuantity, float $scrappedQuantity): MfgProductionOrder;

    public function completeOrder(string $productionOrderId): MfgProductionOrder;

    public function closeOrder(string $productionOrderId): MfgProductionOrder;

    public function cancelOrder(string $productionOrderId, string $reason): MfgProductionOrder;
}
