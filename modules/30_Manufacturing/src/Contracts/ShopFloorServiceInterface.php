<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Contracts;

use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use Modules\Manufacturing\Models\MfgShopFloorExecution;

/**
 * Shop floor job cards: start on an operation, report downtime, complete with quantities (which confirms the
 * operation with the elapsed time).
 */
interface ShopFloorServiceInterface
{
    /**
     * Starts a job card on an operation of an open order.
     */
    public function startExecution(MfgProductionOrderOperation $operation, ?string $operatorId, ?string $machineId, ?string $actorUserId): MfgShopFloorExecution;

    /**
     * Completes a job card: the elapsed time less downtime is the run time of the operation confirmation.
     */
    public function completeExecution(MfgShopFloorExecution $execution, string $goodUnits, string $scrapUnits, ?string $scrapReason, string $setupMinutes, ?string $laborMinutes, bool $isFinal, ?string $postingDate, ?string $actorUserId): MfgShopFloorExecution;

    /**
     * Adds downtime to an active job card.
     */
    public function recordDowntime(MfgShopFloorExecution $execution, string $downtimeMinutes, string $reason): MfgShopFloorExecution;
}
