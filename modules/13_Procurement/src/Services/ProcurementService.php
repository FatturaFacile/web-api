<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use App\Services\Tenancy\TenantContext;
use Enterprise\EventBus\Contracts\DomainEventInterface;
use Enterprise\EventBus\Contracts\EventBusInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Procurement\Contracts\InventoryReceivingBridgeInterface;
use Modules\Procurement\Contracts\ProcurementServiceInterface;
use Modules\Procurement\Contracts\PurchaseBudgetCommitmentInterface;
use Modules\Procurement\DTOs\CreatePurchaseOrderDTO;
use Modules\Procurement\DTOs\CreatePurchaseRequisitionDTO;
use Modules\Procurement\DTOs\CreateSupplierDTO;
use Modules\Procurement\DTOs\ProcessGoodsReceiptDTO;
use Modules\Procurement\DTOs\ProcessPurchaseReturnDTO;
use Modules\Procurement\Events\GoodsReceivedEvent;
use Modules\Procurement\Events\PurchaseOrderCancelledEvent;
use Modules\Procurement\Events\PurchaseOrderCreatedEvent;
use Modules\Procurement\Events\PurchaseRequisitionCreatedEvent;
use Modules\Procurement\Events\PurchaseReturnCreatedEvent;
use Modules\Procurement\Events\SupplierApprovedEvent;
use Modules\Procurement\Events\SupplierCreatedEvent;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderLine;
use Modules\Procurement\Models\PurchaseReceipt;
use Modules\Procurement\Models\PurchaseReceiptLine;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\PurchaseRequisitionLine;
use Modules\Procurement\Models\PurchaseReturn;
use Modules\Procurement\Models\PurchaseReturnLine;
use Modules\Procurement\Models\Supplier;

final class ProcurementService implements ProcurementServiceInterface
{
    public function __construct(
        private readonly ProcurementAuditLedgerService $auditLedger,
        private readonly InventoryReceivingBridgeInterface $inventoryBridge,
        private readonly PurchaseBudgetCommitmentInterface $budgetService,
        private readonly ?EventBusInterface $eventBus = null
    ) {}

    public function createSupplier(CreateSupplierDTO $dto): Supplier
    {
        $tenantId = $dto->tenantId ?? app(TenantContext::class)->getTenantId();

        return DB::transaction(function () use ($dto, $tenantId): Supplier {
            $supplier = Supplier::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'company_id' => $dto->companyId,
                'contact_id' => $dto->contactId,
                'supplier_category_id' => $dto->categoryId,
                'supplier_code' => $dto->supplierCode,
                'company_name' => $dto->companyName,
                'vat_number' => $dto->vatNumber,
                'fiscal_code' => $dto->fiscalCode,
                'currency' => $dto->currency,
                'payment_terms_code' => $dto->paymentTermsCode,
                'incoterms' => $dto->incoterms,
                'status' => $dto->isApprovedVendor ? 'approved' : 'draft',
                'is_approved_vendor' => $dto->isApprovedVendor,
                'credit_limit' => $dto->creditLimit,
                'rating' => $dto->rating,
                'metadata' => $dto->metadata,
                'created_by' => $dto->creatorUserId,
            ]);

            $this->auditLedger->record(
                action: 'SUPPLIER_CREATED',
                model: $supplier,
                payloadBefore: null,
                payloadAfter: ['code' => $supplier->supplier_code, 'name' => $supplier->company_name]
            );

            $this->dispatchDomainEvent(new SupplierCreatedEvent(
                supplierId: $supplier->id,
                supplierCode: $supplier->supplier_code,
                companyName: $supplier->company_name,
                tenantId: $tenantId,
                companyId: (string) ($supplier->company_id ?? $tenantId)
            ));

            if ($supplier->is_approved_vendor) {
                $this->dispatchDomainEvent(new SupplierApprovedEvent(
                    supplierId: $supplier->id,
                    supplierCode: $supplier->supplier_code,
                    tenantId: $tenantId,
                    companyId: (string) ($supplier->company_id ?? $tenantId)
                ));
            }

            return $supplier;
        });
    }

    public function createRequisition(CreatePurchaseRequisitionDTO $dto): PurchaseRequisition
    {
        $tenantId = $dto->tenantId ?? app(TenantContext::class)->getTenantId();
        $reqNumber = $dto->requisitionNumber ?? 'PR-'.strtoupper(Str::random(8));

        return DB::transaction(function () use ($dto, $tenantId, $reqNumber): PurchaseRequisition {
            $requisition = PurchaseRequisition::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'company_id' => $dto->companyId,
                'branch_id' => $dto->branchId,
                'department_id' => $dto->departmentId,
                'cost_center_id' => $dto->costCenterId,
                'project_id' => $dto->projectId,
                'requisition_number' => $reqNumber,
                'title' => $dto->title,
                'requestor_user_id' => $dto->requestorUserId,
                'urgency' => $dto->urgency,
                'required_by_date' => $dto->requiredByDate,
                'status' => 'draft',
                'currency' => $dto->currency,
                'notes' => $dto->notes,
                'created_by' => $dto->requestorUserId,
            ]);

            $totalEstimated = 0.0;
            $lineNum = 1;

            foreach ($dto->lines as $line) {
                $qty = (float) $line['quantity_requested'];
                $price = (float) $line['estimated_unit_price'];
                $lineTotal = round($qty * $price, 2);
                $totalEstimated += $lineTotal;

                PurchaseRequisitionLine::create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'purchase_requisition_id' => $requisition->id,
                    'line_number' => $lineNum++,
                    'supplier_catalog_id' => $line['supplier_catalog_id'] ?? null,
                    'description' => $line['description'],
                    'quantity_requested' => $qty,
                    'uom' => $line['uom'],
                    'estimated_unit_price' => $price,
                    'estimated_total_price' => $lineTotal,
                    'suggested_supplier_id' => $line['suggested_supplier_id'] ?? null,
                ]);
            }

            $requisition->update(['total_estimated_amount' => $totalEstimated]);

            $this->auditLedger->record(
                action: 'REQUISITION_CREATED',
                model: $requisition,
                payloadBefore: null,
                payloadAfter: ['number' => $reqNumber, 'total' => $totalEstimated]
            );

            $this->dispatchDomainEvent(new PurchaseRequisitionCreatedEvent(
                requisitionId: $requisition->id,
                requisitionNumber: $requisition->requisition_number,
                totalEstimatedAmount: $totalEstimated,
                requestorUserId: $requisition->requestor_user_id,
                tenantId: $tenantId,
                companyId: (string) ($requisition->company_id ?? $tenantId)
            ));

            return $requisition;
        });
    }

    public function submitRequisitionForApproval(PurchaseRequisition $requisition): PurchaseRequisition
    {
        return DB::transaction(function () use ($requisition): PurchaseRequisition {
            $locked = PurchaseRequisition::where('id', $requisition->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'draft') {
                throw new InvalidArgumentException("Requisition [{$locked->requisition_number}] is not in draft status.");
            }

            $locked->update(['status' => 'pending_approval']);

            $this->auditLedger->record(
                action: 'REQUISITION_SUBMITTED_FOR_APPROVAL',
                model: $locked,
                payloadBefore: ['status' => 'draft'],
                payloadAfter: ['status' => 'pending_approval']
            );

            return $locked;
        });
    }

    public function createPurchaseOrder(CreatePurchaseOrderDTO $dto): PurchaseOrder
    {
        $tenantId = $dto->tenantId ?? app(TenantContext::class)->getTenantId();
        $poNumber = $dto->poNumber ?? 'PO-'.strtoupper(Str::random(8));

        return DB::transaction(function () use ($dto, $tenantId, $poNumber): PurchaseOrder {
            $order = PurchaseOrder::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'company_id' => $dto->companyId,
                'branch_id' => $dto->branchId,
                'department_id' => $dto->departmentId,
                'cost_center_id' => $dto->costCenterId,
                'project_id' => $dto->projectId,
                'supplier_id' => $dto->supplierId,
                'supplier_contract_id' => $dto->contractId,
                'purchase_requisition_id' => $dto->requisitionId,
                'po_number' => $poNumber,
                'order_number' => $poNumber,
                'order_type' => $dto->orderType,
                'status' => 'draft',
                'order_date' => $dto->orderDate,
                'date' => $dto->orderDate,
                'expected_delivery_date' => $dto->expectedDeliveryDate,
                'delivery_due_date' => $dto->expectedDeliveryDate,
                'currency' => $dto->currency,
                'exchange_rate' => $dto->exchangeRate,
                'payment_terms_code' => $dto->paymentTermsCode,
                'incoterms' => $dto->incoterms,
                'shipping_address' => $dto->shippingAddress,
                'notes' => $dto->notes,
                'created_by' => $dto->creatorUserId,
            ]);

            $netTotal = 0.0;
            $taxTotal = 0.0;
            $lineNum = 1;

            foreach ($dto->lines as $line) {
                $qty = (float) $line['quantity_ordered'];
                $price = (float) $line['unit_price'];
                $taxRate = (float) ($line['tax_rate'] ?? 22.0);
                $lineNet = round($qty * $price, 2);
                $lineTax = round(($lineNet * $taxRate) / 100.0, 2);
                $lineGross = $lineNet + $lineTax;

                $netTotal += $lineNet;
                $taxTotal += $lineTax;

                PurchaseOrderLine::create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'purchase_order_id' => $order->id,
                    'line_number' => $lineNum++,
                    'supplier_catalog_id' => $line['supplier_catalog_id'] ?? null,
                    'description' => $line['description'],
                    'quantity_ordered' => $qty,
                    'uom' => $line['uom'],
                    'unit_price' => $price,
                    'tax_rate' => $taxRate,
                    'line_net_amount' => $lineNet,
                    'line_tax_amount' => $lineTax,
                    'line_total_amount' => $lineGross,
                ]);
            }

            $orderTotal = $netTotal + $taxTotal;
            $order->update([
                'net_amount' => $netTotal,
                'tax_amount' => $taxTotal,
                'total_amount' => $orderTotal,
                'total_net' => $netTotal,
                'total_tax' => $taxTotal,
                'total_gross' => $orderTotal,
            ]);

            $this->auditLedger->record(
                action: 'PURCHASE_ORDER_CREATED',
                model: $order,
                payloadBefore: null,
                payloadAfter: ['po_number' => $poNumber, 'total' => $orderTotal]
            );

            $this->dispatchDomainEvent(new PurchaseOrderCreatedEvent(
                purchaseOrderId: $order->id,
                poNumber: $poNumber,
                supplierId: $order->supplier_id,
                totalAmount: $orderTotal,
                tenantId: $tenantId,
                companyId: (string) ($order->company_id ?? $tenantId)
            ));

            return $order;
        });
    }

    public function submitPurchaseOrderForApproval(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order): PurchaseOrder {
            $locked = PurchaseOrder::where('id', $order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'draft') {
                throw new InvalidArgumentException("Purchase order [{$locked->po_number}] is not in draft status.");
            }

            $locked->update(['status' => 'pending_approval']);

            $this->auditLedger->record(
                action: 'PURCHASE_ORDER_SUBMITTED_FOR_APPROVAL',
                model: $locked,
                payloadBefore: ['status' => 'draft'],
                payloadAfter: ['status' => 'pending_approval']
            );

            return $locked;
        });
    }

    public function processGoodsReceipt(ProcessGoodsReceiptDTO $dto): PurchaseReceipt
    {
        $tenantId = $dto->tenantId ?? app(TenantContext::class)->getTenantId();
        $receiptNumber = $dto->receiptNumber ?? 'GRN-'.strtoupper(Str::random(8));

        return DB::transaction(function () use ($dto, $tenantId, $receiptNumber): PurchaseReceipt {
            $order = PurchaseOrder::where('id', $dto->purchaseOrderId)
                ->lockForUpdate()
                ->with('lines')
                ->firstOrFail();

            $receipt = PurchaseReceipt::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'company_id' => $dto->companyId ?? $order->company_id,
                'purchase_order_id' => $order->id,
                'receipt_number' => $receiptNumber,
                'supplier_delivery_note_ref' => $dto->supplierDeliveryNoteRef,
                'received_date' => $dto->receivedDate,
                'received_by_user_id' => $dto->receivedByUserId,
                'warehouse_id' => $dto->warehouseId,
                'inspection_status' => $dto->inspectionStatus,
                'notes' => $dto->notes,
            ]);

            $eventLines = [];
            foreach ($dto->lines as $line) {
                $orderLine = PurchaseOrderLine::where('id', $line['purchase_order_line_id'])
                    ->where('purchase_order_id', $order->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $qtyRec = (float) $line['quantity_received'];
                $qtyAcc = (float) $line['quantity_accepted'];
                $qtyRej = (float) ($line['quantity_rejected'] ?? ($qtyRec - $qtyAcc));

                PurchaseReceiptLine::create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'purchase_receipt_id' => $receipt->id,
                    'purchase_order_line_id' => $orderLine->id,
                    'quantity_received' => $qtyRec,
                    'quantity_accepted' => $qtyAcc,
                    'quantity_rejected' => $qtyRej,
                    'lot_number' => $line['lot_number'] ?? null,
                    'serial_number' => $line['serial_number'] ?? null,
                    'warehouse_location_id' => $line['warehouse_location_id'] ?? null,
                    'rejection_reason' => $line['rejection_reason'] ?? null,
                ]);

                // Update PO line received quantity
                $newReceived = (float) $orderLine->quantity_received + $qtyAcc;
                $isClosed = $newReceived >= (float) $orderLine->quantity_ordered;
                $orderLine->update([
                    'quantity_received' => $newReceived,
                    'is_closed' => $isClosed,
                ]);

                $eventLines[] = [
                    'purchase_order_line_id' => $orderLine->id,
                    'quantity_received' => $qtyRec,
                    'quantity_accepted' => $qtyAcc,
                    'lot_number' => $line['lot_number'] ?? null,
                ];
            }

            // Update parent PO status
            $order->refresh();
            $allClosed = $order->lines()->where('is_closed', false)->count() === 0;
            $order->update([
                'status' => $allClosed ? 'fully_received' : 'partially_received',
            ]);

            // Notify inventory bridge (Module 14 readiness)
            $this->inventoryBridge->recordStockReceipt($receipt);

            $this->auditLedger->record(
                action: 'GOODS_RECEIPT_PROCESSED',
                model: $receipt,
                payloadBefore: null,
                payloadAfter: ['receipt_number' => $receiptNumber, 'lines_count' => count($dto->lines)]
            );

            $this->dispatchDomainEvent(new GoodsReceivedEvent(
                receiptId: $receipt->id,
                receiptNumber: $receipt->receipt_number,
                purchaseOrderId: $order->id,
                supplierDeliveryNoteRef: $receipt->supplier_delivery_note_ref,
                receivedLines: $eventLines,
                tenantId: $tenantId,
                companyId: (string) ($order->company_id ?? $tenantId)
            ));

            return $receipt;
        });
    }

    public function processPurchaseReturn(ProcessPurchaseReturnDTO $dto): PurchaseReturn
    {
        $tenantId = $dto->tenantId ?? app(TenantContext::class)->getTenantId();
        $returnNumber = $dto->returnNumber ?? 'PRT-'.strtoupper(Str::random(8));

        return DB::transaction(function () use ($dto, $tenantId, $returnNumber): PurchaseReturn {
            $order = PurchaseOrder::where('id', $dto->purchaseOrderId)->lockForUpdate()->firstOrFail();

            $return = PurchaseReturn::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'company_id' => $dto->companyId ?? $order->company_id,
                'purchase_order_id' => $order->id,
                'purchase_receipt_id' => $dto->purchaseReceiptId,
                'supplier_id' => $dto->supplierId,
                'return_number' => $returnNumber,
                'return_date' => $dto->returnDate,
                'returned_by_user_id' => $dto->returnedByUserId,
                'status' => 'draft',
                'reason' => $dto->reason,
                'notes' => $dto->notes,
            ]);

            $totalReturned = 0.0;

            foreach ($dto->lines as $line) {
                $qty = (float) $line['quantity_returned'];
                $price = (float) $line['unit_price'];
                $lineTotal = round($qty * $price, 2);
                $totalReturned += $lineTotal;

                PurchaseReturnLine::create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'purchase_return_id' => $return->id,
                    'purchase_order_line_id' => $line['purchase_order_line_id'],
                    'quantity_returned' => $qty,
                    'unit_price' => $price,
                    'line_total' => $lineTotal,
                    'reason' => $line['reason'] ?? null,
                ]);
            }

            $return->update(['total_amount' => $totalReturned]);

            // Notify inventory bridge (Module 14 deduction)
            $this->inventoryBridge->recordStockReturn($return);

            $this->auditLedger->record(
                action: 'PURCHASE_RETURN_CREATED',
                model: $return,
                payloadBefore: null,
                payloadAfter: ['return_number' => $returnNumber, 'total' => $totalReturned]
            );

            $this->dispatchDomainEvent(new PurchaseReturnCreatedEvent(
                returnId: $return->id,
                returnNumber: $return->return_number,
                purchaseOrderId: $order->id,
                supplierId: $return->supplier_id,
                totalAmount: $totalReturned,
                tenantId: $tenantId,
                companyId: (string) ($return->company_id ?? $tenantId)
            ));

            return $return;
        });
    }

    public function cancelPurchaseOrder(PurchaseOrder $order, string $reason): void
    {
        DB::transaction(function () use ($order, $reason): void {
            $locked = PurchaseOrder::where('id', $order->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, ['fully_received', 'matched', 'cancelled'], true)) {
                throw new InvalidArgumentException("Order [{$locked->po_number}] cannot be cancelled in status [{$locked->status}].");
            }

            $locked->update(['status' => 'cancelled']);

            // Release budget commitments if encumbered
            if ($locked->is_budget_encumbered) {
                $this->budgetService->releaseBudget($locked);
            }

            $this->auditLedger->record(
                action: 'PURCHASE_ORDER_CANCELLED',
                model: $locked,
                payloadBefore: ['status' => $order->status],
                payloadAfter: ['status' => 'cancelled', 'reason' => $reason]
            );

            $this->dispatchDomainEvent(new PurchaseOrderCancelledEvent(
                purchaseOrderId: $locked->id,
                poNumber: (string) ($locked->po_number ?? $locked->order_number),
                reason: $reason,
                tenantId: $locked->tenant_id,
                companyId: (string) ($locked->company_id ?? $locked->tenant_id)
            ));
        });
    }

    private function dispatchDomainEvent(object $event): void
    {
        if ($this->eventBus !== null && $event instanceof DomainEventInterface) {
            $this->eventBus->publish($event);
        } else {
            event($event);
        }
    }
}
