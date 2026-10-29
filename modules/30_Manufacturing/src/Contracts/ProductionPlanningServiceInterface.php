<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Contracts;

use Modules\Manufacturing\DTOs\CreateProductionOrderDTO;
use Modules\Manufacturing\Models\MfgProductionOrder;

interface ProductionPlanningServiceInterface
{
    public function planOrder(CreateProductionOrderDTO $dto): MfgProductionOrder;

    public function scheduleOrder(string $productionOrderId, string $startDate, string $endDate): MfgProductionOrder;

    public function checkCapacityAvailability(string $workCenterId, string $date, float $requiredHours): bool;
}
