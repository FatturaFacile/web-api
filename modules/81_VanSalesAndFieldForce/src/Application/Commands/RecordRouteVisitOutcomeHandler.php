<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Application\Commands;

use Modules\VanSalesAndFieldForce\Models\VanVisitOutcome;
use Modules\VanSalesAndFieldForce\Services\InFieldVisitAndSyncService;

final readonly class RecordRouteVisitOutcomeHandler
{
    public function __construct(private InFieldVisitAndSyncService $service) {}

    public function handle(RecordRouteVisitOutcomeCommand $command): VanVisitOutcome
    {
        return $this->service->recordVisitOutcome($command->tenantId, $command->payload);
    }
}
