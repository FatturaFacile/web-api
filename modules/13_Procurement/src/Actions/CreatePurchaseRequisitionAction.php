<?php

declare(strict_types=1);

namespace Modules\Procurement\Actions;

use Modules\Procurement\Contracts\ProcurementServiceInterface;
use Modules\Procurement\DTOs\CreatePurchaseRequisitionDTO;
use Modules\Procurement\Models\PurchaseRequisition;

final class CreatePurchaseRequisitionAction
{
    public function __construct(
        private readonly ProcurementServiceInterface $procurementService
    ) {}

    public function execute(CreatePurchaseRequisitionDTO $dto): PurchaseRequisition
    {
        return $this->procurementService->createRequisition($dto);
    }
}
