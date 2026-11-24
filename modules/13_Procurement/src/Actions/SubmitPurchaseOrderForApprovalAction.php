<?php

declare(strict_types=1);

namespace Modules\Procurement\Actions;

use Modules\Procurement\Contracts\ProcurementServiceInterface;
use Modules\Procurement\Models\PurchaseOrder;

final class SubmitPurchaseOrderForApprovalAction
{
    public function __construct(
        private readonly ProcurementServiceInterface $procurementService
    ) {}

    public function execute(PurchaseOrder $order): PurchaseOrder
    {
        return $this->procurementService->submitPurchaseOrderForApproval($order);
    }
}
