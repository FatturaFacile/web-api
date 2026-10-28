<?php

declare(strict_types=1);

namespace Modules\Procurement\Actions;

use Modules\Procurement\Contracts\ProcurementServiceInterface;
use Modules\Procurement\Models\PurchaseRequisition;

final class SubmitRequisitionForApprovalAction
{
    public function __construct(
        private readonly ProcurementServiceInterface $procurementService
    ) {}

    public function execute(PurchaseRequisition $requisition): PurchaseRequisition
    {
        return $this->procurementService->submitRequisitionForApproval($requisition);
    }
}
