<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Contracts;

use Modules\Manufacturing\DTOs\CreateProductionOrderDTO;
use Modules\Manufacturing\Models\MfgProductionOrder;

/**
 * Creation and rescheduling of production orders.
 */
interface ProductionPlanningServiceInterface
{
    /**
     * Creates a planned production order on the BOM (and routing) given or effective on the planned start date.
     */
    public function planOrder(CreateProductionOrderDTO $dto): MfgProductionOrder;

    /**
     * Moves the planned dates of an order that has not started.
     */
    public function scheduleOrder(MfgProductionOrder $order, string $startDate, string $endDate): MfgProductionOrder;
}
