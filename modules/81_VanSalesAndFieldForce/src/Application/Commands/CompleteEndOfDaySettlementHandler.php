<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Application\Commands;

use Modules\VanSalesAndFieldForce\Models\VanEodSettlement;
use Modules\VanSalesAndFieldForce\Services\VanEndOfDaySettlementService;

final readonly class CompleteEndOfDaySettlementHandler
{
    public function __construct(private VanEndOfDaySettlementService $service) {}

    public function handle(CompleteEndOfDaySettlementCommand $command): VanEodSettlement
    {
        return $this->service->completeSettlement($command->tenantId, $command->payload, $command->userId);
    }
}
