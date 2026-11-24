<?php

declare(strict_types=1);

namespace Modules\Procurement\Actions;

use Modules\Procurement\Contracts\ProcurementServiceInterface;
use Modules\Procurement\DTOs\ProcessPurchaseReturnDTO;
use Modules\Procurement\Models\PurchaseReturn;

final class ProcessPurchaseReturnAction
{
    public function __construct(
        private readonly ProcurementServiceInterface $procurementService
    ) {}

    public function execute(ProcessPurchaseReturnDTO $dto): PurchaseReturn
    {
        return $this->procurementService->processPurchaseReturn($dto);
    }
}
