<?php

declare(strict_types=1);

namespace Modules\Procurement\Actions;

use Modules\Procurement\Contracts\ProcurementServiceInterface;
use Modules\Procurement\DTOs\CreatePurchaseOrderDTO;
use Modules\Procurement\Models\PurchaseOrder;

final class CreatePurchaseOrderAction
{
    public function __construct(
        private readonly ProcurementServiceInterface $procurementService
    ) {}

    public function execute(CreatePurchaseOrderDTO $dto): PurchaseOrder
    {
        return $this->procurementService->createPurchaseOrder($dto);
    }
}
