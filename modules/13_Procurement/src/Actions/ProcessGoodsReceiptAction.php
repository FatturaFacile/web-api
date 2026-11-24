<?php

declare(strict_types=1);

namespace Modules\Procurement\Actions;

use Modules\Procurement\Contracts\ProcurementServiceInterface;
use Modules\Procurement\DTOs\ProcessGoodsReceiptDTO;
use Modules\Procurement\Models\PurchaseReceipt;

final class ProcessGoodsReceiptAction
{
    public function __construct(
        private readonly ProcurementServiceInterface $procurementService
    ) {}

    public function execute(ProcessGoodsReceiptDTO $dto): PurchaseReceipt
    {
        return $this->procurementService->processGoodsReceipt($dto);
    }
}
