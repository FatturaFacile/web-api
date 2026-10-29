<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Actions;

use Modules\ServiceManagement\Contracts\WorkOrderAndFieldServiceInterface;
use Modules\ServiceManagement\DTOs\CompleteWorkOrderDTO;
use Modules\ServiceManagement\Models\WorkOrder;

final readonly class CompleteWorkOrderAction
{
    public function __construct(
        private WorkOrderAndFieldServiceInterface $workOrderService
    ) {}

    public function execute(CompleteWorkOrderDTO $dto, ?string $actorUserId = null): WorkOrder
    {
        return $this->workOrderService->completeWorkOrder($dto, $actorUserId);
    }
}
