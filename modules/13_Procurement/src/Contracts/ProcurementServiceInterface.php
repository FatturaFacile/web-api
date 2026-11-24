<?php

declare(strict_types=1);

namespace Modules\Procurement\Contracts;

use Modules\Procurement\DTOs\CreatePurchaseOrderDTO;
use Modules\Procurement\DTOs\CreatePurchaseRequisitionDTO;
use Modules\Procurement\DTOs\CreateSupplierDTO;
use Modules\Procurement\DTOs\ProcessGoodsReceiptDTO;
use Modules\Procurement\DTOs\ProcessPurchaseReturnDTO;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseReceipt;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\PurchaseReturn;
use Modules\Procurement\Models\Supplier;

interface ProcurementServiceInterface
{
    public function createSupplier(CreateSupplierDTO $dto): Supplier;

    public function createRequisition(CreatePurchaseRequisitionDTO $dto): PurchaseRequisition;

    public function submitRequisitionForApproval(PurchaseRequisition $requisition): PurchaseRequisition;

    public function createPurchaseOrder(CreatePurchaseOrderDTO $dto): PurchaseOrder;

    public function submitPurchaseOrderForApproval(PurchaseOrder $order): PurchaseOrder;

    public function processGoodsReceipt(ProcessGoodsReceiptDTO $dto): PurchaseReceipt;

    public function processPurchaseReturn(ProcessPurchaseReturnDTO $dto): PurchaseReturn;

    public function cancelPurchaseOrder(PurchaseOrder $order, string $reason): void;
}
