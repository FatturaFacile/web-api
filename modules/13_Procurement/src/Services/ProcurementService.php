<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use App\Enums\PurchaseOrderStatus;
use App\Models\Contact;
use App\Models\PassiveInvoice;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\StockItem;
use Modules\Inventory\Models\StockLot;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Models\WarehouseBin;
use Modules\Inventory\Services\PurchaseOrderLineStockItemResolver;
use Modules\Inventory\Services\SupplierReceiptStockService;
use Modules\Procurement\Contracts\InventoryReceivingBridgeInterface;
use Modules\Procurement\Contracts\ProcurementServiceInterface;
use Modules\Procurement\Contracts\PurchaseBudgetCommitmentInterface;
use Modules\Procurement\DTOs\CreatePurchaseOrderDTO;
use Modules\Procurement\DTOs\CreatePurchaseRequisitionDTO;
use Modules\Procurement\DTOs\CreateSupplierDTO;
use Modules\Procurement\DTOs\ProcessGoodsReceiptDTO;
use Modules\Procurement\DTOs\ProcessPurchaseReturnDTO;
use Modules\Procurement\Enums\PurchaseRequisitionStatus;
use Modules\Procurement\Enums\SupplierQualificationStatus;
use Modules\Procurement\Events\GoodsReceivedEvent;
use Modules\Procurement\Events\PurchaseOrderCancelledEvent;
use Modules\Procurement\Events\PurchaseOrderCreatedEvent;
use Modules\Procurement\Events\PurchaseRequisitionCreatedEvent;
use Modules\Procurement\Events\PurchaseReturnCreatedEvent;
use Modules\Procurement\Events\SupplierApprovedEvent;
use Modules\Procurement\Events\SupplierCreatedEvent;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderLine;
use Modules\Procurement\Models\PurchaseReceipt;
use Modules\Procurement\Models\PurchaseReceiptLine;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\PurchaseRequisitionLine;
use Modules\Procurement\Models\PurchaseReturn;
use Modules\Procurement\Models\PurchaseReturnLine;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierCatalog;
use Modules\Procurement\Models\SupplierContract;
use Modules\Procurement\Models\SupplierContractLine;
use Modules\Procurement\Models\SupplierDocument;
use Modules\Procurement\Support\Decimal;

/**
 * Core purchasing documents: suppliers, requisitions, purchase orders, goods receipts and returns to supplier. Numbers
 * come from the gapless series of the company, amounts are computed with bcmath, every write runs in a transaction with
 * row locks, stock moves only through the inventory module and the ledger only through its posting service.
 */
final class ProcurementService implements ProcurementServiceInterface
{
    public function __construct(
        private readonly ProcurementAuditLedgerService $auditLedger,
        private readonly InventoryReceivingBridgeInterface $inventoryBridge,
        private readonly PurchaseBudgetCommitmentInterface $budgetService,
        private readonly EventDispatcher $dispatcher,
        private readonly ProcurementNumberingService $numbering,
        private readonly ProcurementSettingsService $settings,
        private readonly SupplierPriceService $prices,
        private readonly ProcurementApprovalService $approvals,
        private readonly SupplierReceiptStockService $stock,
        private readonly PurchaseOrderLineStockItemResolver $stockItems,
        private readonly ProcurementLedgerService $ledger,
    ) {}

    /**
     * Creates a supplier on its contact: the supplier takes the id of the contact (created when not given), so purchase
     * orders and supplier invoices name the same party.
     *
     * @throws ProcurementRuleViolation when the contact already is a supplier
     */
    public function createSupplier(CreateSupplierDTO $dto): Supplier
    {
        $tenantId = $dto->tenantId ?? (string) app(TenantContext::class)->getTenantId();

        return DB::transaction(function () use ($dto, $tenantId): Supplier {
            $contactId = $dto->contactId;

            if ($contactId !== null) {
                if (Supplier::withTrashed()->whereKey($contactId)->exists()) {
                    throw new ProcurementRuleViolation('This contact is already a supplier.', 'contact_id');
                }
            } else {
                $contact = new Contact(['type' => 'supplier', 'company_name' => $dto->companyName, 'vat_number' => $dto->vatNumber, 'fiscal_code' => $dto->fiscalCode]);
                $contact->forceFill(['tenant_id' => $tenantId]);
                $contact->save();
                $contactId = (string) $contact->id;
            }

            $supplier = new Supplier;
            $supplier->forceFill([
                'id' => $contactId,
                'tenant_id' => $tenantId,
                'company_id' => $dto->companyId,
                'contact_id' => $contactId,
                'supplier_category_id' => $dto->categoryId,
                'supplier_code' => $dto->supplierCode,
                'company_name' => $dto->companyName,
                'vat_number' => $dto->vatNumber,
                'fiscal_code' => $dto->fiscalCode,
                'currency' => $dto->currency,
                'payment_terms_code' => $dto->paymentTermsCode,
                'incoterms' => $dto->incoterms,
                'status' => $dto->isApprovedVendor ? 'approved' : $dto->status,
                'is_approved_vendor' => $dto->isApprovedVendor,
                'credit_limit' => Decimal::of($dto->creditLimit, 2),
                'rating' => $dto->rating,
                'metadata' => $dto->metadata,
                'created_by' => $dto->creatorUserId,
            ])->save();

            $this->auditLedger->record(
                action: 'SUPPLIER_CREATED',
                model: $supplier,
                payloadBefore: null,
                payloadAfter: ['code' => $supplier->supplier_code, 'name' => $supplier->company_name]
            );

            $this->dispatcher->publishAndDispatch(new SupplierCreatedEvent(
                supplierId: $supplier->id,
                supplierCode: $supplier->supplier_code,
                companyName: $supplier->company_name,
                tenantId: $tenantId,
                companyId: (string) ($supplier->company_id ?? $tenantId)
            ));

            if ($supplier->is_approved_vendor) {
                $this->dispatcher->publishAndDispatch(new SupplierApprovedEvent(
                    supplierId: $supplier->id,
                    supplierCode: $supplier->supplier_code,
                    tenantId: $tenantId,
                    companyId: (string) ($supplier->company_id ?? $tenantId)
                ));
            }

            return $supplier;
        });
    }

    /**
     * Creates a draft requisition with a gapless number.
     */
    public function createRequisition(CreatePurchaseRequisitionDTO $dto): PurchaseRequisition
    {
        $tenantId = $dto->tenantId ?? (string) app(TenantContext::class)->getTenantId();

        return DB::transaction(function () use ($dto, $tenantId): PurchaseRequisition {
            $number = $this->numbering->next(ProcurementNumberingService::Requisition, $tenantId);

            $requisition = PurchaseRequisition::create([
                'tenant_id' => $tenantId,
                'company_id' => $dto->companyId,
                'branch_id' => $dto->branchId,
                'department_id' => $dto->departmentId,
                'cost_center_id' => $dto->costCenterId,
                'project_id' => $dto->projectId,
                'requisition_number' => $number,
                'title' => $dto->title,
                'requestor_user_id' => $dto->requestorUserId,
                'urgency' => $dto->urgency,
                'required_by_date' => $dto->requiredByDate,
                'status' => 'draft',
                'currency' => $dto->currency,
                'notes' => $dto->notes,
                'created_by' => $dto->requestorUserId,
            ]);

            $total = $this->writeRequisitionLines($requisition, $dto->lines);
            $requisition->update(['total_estimated_amount' => $total]);

            $this->auditLedger->record(action: 'REQUISITION_CREATED', model: $requisition, payloadBefore: null, payloadAfter: ['number' => $number, 'total' => $total]);

            $this->dispatcher->publishAndDispatch(new PurchaseRequisitionCreatedEvent(
                requisitionId: $requisition->id,
                requisitionNumber: $number,
                totalEstimatedAmount: (float) $total,
                requestorUserId: $requisition->requestor_user_id,
                tenantId: $tenantId,
                companyId: (string) ($requisition->company_id ?? $tenantId)
            ));

            return $requisition->fresh('lines') ?? $requisition;
        });
    }

    /**
     * Replaces the lines of a draft requisition and returns the new estimated total.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function writeRequisitionLines(PurchaseRequisition $requisition, array $lines): string
    {
        PurchaseRequisitionLine::query()->where('purchase_requisition_id', $requisition->id)->delete();
        $total = '0.00';

        foreach (array_values($lines) as $index => $line) {
            $quantity = Decimal::of($line['quantity_requested'] ?? $line['quantity'] ?? '1', 4);
            $price = Decimal::of($line['estimated_unit_price'] ?? '0', 4);
            $lineTotal = Decimal::mul($quantity, $price, 2);
            $total = Decimal::add($total, $lineTotal, 2);

            PurchaseRequisitionLine::create([
                'tenant_id' => $requisition->tenant_id,
                'purchase_requisition_id' => $requisition->id,
                'line_number' => $index + 1,
                'supplier_catalog_id' => $line['supplier_catalog_id'] ?? null,
                'description' => $line['description'],
                'quantity_requested' => $quantity,
                'uom' => $line['uom'] ?? 'PCE',
                'estimated_unit_price' => $price,
                'estimated_total_price' => $lineTotal,
                'suggested_supplier_id' => $line['suggested_supplier_id'] ?? null,
            ]);
        }

        return $total;
    }

    /**
     * Updates a draft or rejected requisition (back to draft); given lines replace the current ones.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ProcurementRuleViolation when the requisition is not editable
     */
    public function updateRequisition(PurchaseRequisition $requisition, array $data): PurchaseRequisition
    {
        return DB::transaction(function () use ($requisition, $data): PurchaseRequisition {
            $locked = PurchaseRequisition::query()->whereKey($requisition->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [PurchaseRequisitionStatus::Draft->value, PurchaseRequisitionStatus::Rejected->value], true) || $locked->is_locked) {
                throw new ProcurementRuleViolation("A requisition in status {$locked->status} cannot be edited.", 'status');
            }

            $locked->fill([...array_intersect_key($data, array_flip(['title', 'required_by_date', 'urgency', 'project_id', 'cost_center_id', 'department_id', 'branch_id', 'currency', 'notes'])), 'status' => PurchaseRequisitionStatus::Draft->value])->save();

            if (isset($data['lines']) && is_array($data['lines'])) {
                $locked->update(['total_estimated_amount' => $this->writeRequisitionLines($locked, $data['lines'])]);
            }

            return $locked;
        });
    }

    /**
     * Cancels a requisition not yet ordered.
     *
     * @throws ProcurementRuleViolation when the requisition was already ordered
     */
    public function cancelRequisition(PurchaseRequisition $requisition): PurchaseRequisition
    {
        return DB::transaction(function () use ($requisition): PurchaseRequisition {
            $locked = PurchaseRequisition::query()->whereKey($requisition->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === PurchaseRequisitionStatus::Cancelled->value || ! PurchaseRequisitionStatus::allows($locked->status, PurchaseRequisitionStatus::Cancelled)) {
                throw new ProcurementRuleViolation("A requisition in status {$locked->status} cannot be cancelled.", 'status');
            }

            $locked->update(['status' => PurchaseRequisitionStatus::Cancelled->value, 'is_locked' => false]);

            return $locked;
        });
    }

    /**
     * Submits the requisition and starts its approval workflow.
     */
    public function submitRequisitionForApproval(PurchaseRequisition $requisition): PurchaseRequisition
    {
        /** @var PurchaseRequisition */
        return $this->approvals->submit($requisition, $this->actor());
    }

    /**
     * Creates a draft purchase order. Prices come from the request, else from the framework agreement line, else from
     * the valid supplier price list; the supplier must be qualified and active.
     *
     * @throws ProcurementRuleViolation when the supplier, the agreement or a price does not allow the order
     */
    public function createPurchaseOrder(CreatePurchaseOrderDTO $dto): PurchaseOrder
    {
        $tenantId = $dto->tenantId ?? (string) app(TenantContext::class)->getTenantId();

        return DB::transaction(function () use ($dto, $tenantId): PurchaseOrder {
            $supplier = Supplier::query()->whereKey($dto->supplierId)->lockForUpdate()->first();

            if ($supplier === null) {
                throw new ProcurementRuleViolation('The supplier does not exist in the active company.', 'supplier_id');
            }

            $this->assertSupplierCanOrder($supplier);

            $contract = null;

            if ($dto->contractId !== null) {
                $contract = SupplierContract::query()->whereKey($dto->contractId)->lockForUpdate()->first();
                $this->assertContractUsable($contract, $supplier, $dto->orderDate);
            }

            $order = PurchaseOrder::create([
                'tenant_id' => $tenantId,
                'company_id' => $dto->companyId,
                'branch_id' => $dto->branchId,
                'department_id' => $dto->departmentId,
                'cost_center_id' => $dto->costCenterId,
                'project_id' => $dto->projectId,
                'supplier_id' => $supplier->id,
                'supplier_contract_id' => $contract?->id,
                'purchase_requisition_id' => $dto->requisitionId,
                'po_number' => $number = $this->numbering->next(ProcurementNumberingService::PurchaseOrder, $tenantId, $dto->orderDate),
                'order_number' => $number,
                'order_type' => $contract !== null && $contract->contract_type !== 'fixed_price' ? 'blanket_release' : $dto->orderType,
                'status' => PurchaseOrderStatus::Draft->value,
                'date' => $dto->orderDate,
                'expected_delivery_date' => $dto->expectedDeliveryDate,
                'delivery_due_date' => $dto->expectedDeliveryDate,
                'currency' => $dto->currency,
                'exchange_rate' => Decimal::of($dto->exchangeRate, 6),
                'payment_terms_code' => $dto->paymentTermsCode ?? $supplier->payment_terms_code,
                'incoterms' => $dto->incoterms ?? $supplier->incoterms,
                'shipping_address' => $dto->shippingAddress,
                'notes' => $dto->notes,
                'created_by' => $dto->creatorUserId,
            ]);

            $totals = $this->writeOrderLines($order, $supplier, $contract, $dto->lines);

            $this->auditLedger->record(action: 'PURCHASE_ORDER_CREATED', model: $order, payloadBefore: null, payloadAfter: ['po_number' => $number, 'total' => $totals['gross']]);

            $this->dispatcher->publishAndDispatch(new PurchaseOrderCreatedEvent(
                purchaseOrderId: $order->id,
                poNumber: $number,
                supplierId: (string) $order->supplier_id,
                totalAmount: (float) $totals['gross'],
                tenantId: $tenantId,
                companyId: (string) ($order->company_id ?? $tenantId),
                currency: (string) $order->currency,
            ));

            return $order->fresh(['lines', 'supplier']) ?? $order;
        });
    }

    /**
     * Replaces the lines of a draft order (framework agreement consumption included) and updates its totals.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{net: string, tax: string, gross: string}
     *
     * @throws ProcurementRuleViolation when a line cannot be priced or exceeds the agreement
     */
    public function writeOrderLines(PurchaseOrder $order, Supplier $supplier, ?SupplierContract $contract, array $lines): array
    {
        $this->releaseContractConsumption($order);
        PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->forceDelete();

        $orderDate = $order->order_date?->toDateString() ?? now()->toDateString();
        $net = '0.00';
        $tax = '0.00';

        foreach (array_values($lines) as $index => $line) {
            $quantity = Decimal::of($line['quantity_ordered'], 4);
            $catalog = null;
            $contractLine = null;
            $listPrice = null;

            if (! empty($line['supplier_catalog_id'])) {
                $catalog = SupplierCatalog::query()->whereKey($line['supplier_catalog_id'])->where('supplier_id', $supplier->id)->first();

                if ($catalog === null) {
                    throw new ProcurementRuleViolation('The catalogue entry of line '.($index + 1).' does not belong to the supplier.', "lines.{$index}.supplier_catalog_id");
                }

                $listPrice = $this->prices->priceFor($catalog, $quantity, $orderDate, (string) $order->currency)?->unit_price;
            }

            if (! empty($line['supplier_contract_line_id'])) {
                $contractLine = $this->contractLineFor($contract, (string) $line['supplier_contract_line_id'], $quantity, $orderDate, $index);
                $catalog ??= $contractLine->catalog;
            }

            $price = match (true) {
                isset($line['unit_price']) && $line['unit_price'] !== '' => Decimal::of($line['unit_price'], 4),
                $contractLine !== null => Decimal::of($contractLine->unit_price, 4),
                $listPrice !== null => Decimal::of($listPrice, 4),
                default => throw new ProcurementRuleViolation('Line '.($index + 1).' has no price and no valid supplier price list covers it.', "lines.{$index}.unit_price"),
            };

            $taxRate = Decimal::of($line['tax_rate'] ?? $contractLine->tax_rate ?? '22', 2);
            $lineNet = Decimal::mul($quantity, $price, 2);
            $lineTax = Decimal::round(bcdiv(bcmul($lineNet, $taxRate, 8), '100', 8), 2);

            PurchaseOrderLine::create([
                'tenant_id' => $order->tenant_id,
                'purchase_order_id' => $order->id,
                'line_number' => $index + 1,
                'supplier_catalog_id' => $catalog?->id,
                'supplier_contract_line_id' => $contractLine?->id,
                'description' => $line['description'] ?? $contractLine->description ?? $catalog->name ?? 'Item',
                'quantity_ordered' => $quantity,
                'uom' => $line['uom'] ?? $contractLine->uom ?? $catalog->uom ?? 'PCE',
                'unit_price' => $price,
                'list_unit_price' => $listPrice !== null ? Decimal::of($listPrice, 4) : null,
                'unit_weight' => isset($line['unit_weight']) ? Decimal::of($line['unit_weight'], 4) : null,
                'expected_delivery_date' => $line['expected_delivery_date'] ?? null,
                'tax_rate' => $taxRate,
                'line_net_amount' => $lineNet,
                'line_tax_amount' => $lineTax,
                'line_total_amount' => Decimal::add($lineNet, $lineTax, 2),
            ]);

            if ($contractLine !== null) {
                $contractLine->update(['released_quantity' => Decimal::add((string) $contractLine->released_quantity, $quantity, 4)]);
            }

            $net = Decimal::add($net, $lineNet, 2);
            $tax = Decimal::add($tax, $lineTax, 2);
        }

        if ($contract !== null) {
            $consumed = Decimal::add((string) $contract->consumed_amount, $net, 2);

            if (Decimal::isPositive((string) $contract->total_committed_amount) && Decimal::cmp($consumed, (string) $contract->total_committed_amount, 2) > 0) {
                throw new ProcurementRuleViolation('The order exceeds the remaining amount of the framework agreement.', 'supplier_contract_id');
            }

            $contract->update(['consumed_amount' => $consumed]);
        }

        $gross = Decimal::add($net, $tax, 2);
        $order->update([
            'net_amount' => $net,
            'tax_amount' => $tax,
            'total_amount' => $gross,
            'total_net' => $net,
            'total_tax' => $tax,
            'total_gross' => $gross,
        ]);

        return ['net' => $net, 'tax' => $tax, 'gross' => $gross];
    }

    /**
     * Submits the order and starts its approval workflow.
     */
    public function submitPurchaseOrderForApproval(PurchaseOrder $order): PurchaseOrder
    {
        /** @var PurchaseOrder */
        return $this->approvals->submit($order, $this->actor());
    }

    /**
     * Receives goods against an approved or sent order: over-receipt beyond the tolerance is refused, a line closes once
     * the received quantity reaches the order less the under-receipt tolerance. Stocked items are booked at the order
     * unit cost (in the accounting currency) into the receiving bin, or into quarantine when an inspection is required.
     *
     * @throws ProcurementRuleViolation when the order is not receivable or a quantity is not acceptable
     */
    public function processGoodsReceipt(ProcessGoodsReceiptDTO $dto): PurchaseReceipt
    {
        $tenantId = $dto->tenantId ?? (string) app(TenantContext::class)->getTenantId();

        return DB::transaction(function () use ($dto, $tenantId): PurchaseReceipt {
            $order = PurchaseOrder::query()->whereKey($dto->purchaseOrderId)->lockForUpdate()->firstOrFail();

            if (! in_array((string) $order->status, PurchaseOrderStatus::receivableValues(), true)) {
                throw new ProcurementRuleViolation("Goods cannot be received on an order in status {$order->status}.", 'status');
            }

            $supplier = Supplier::query()->find($order->supplier_id);
            $overTolerance = $this->settings->overReceiptTolerance($tenantId, $supplier);
            $underTolerance = Decimal::of($this->settings->forTenant($tenantId)->under_receipt_tolerance_percent, 2);
            $inspection = $dto->inspectionRequired;
            $warehouse = $this->stock->warehouseFor($tenantId, $dto->warehouseId);

            if ($dto->warehouseId !== null && $warehouse === null) {
                throw new ProcurementRuleViolation('The warehouse does not exist in the active company.', 'warehouse_id');
            }

            $receipt = PurchaseReceipt::create([
                'tenant_id' => $tenantId,
                'company_id' => $dto->companyId ?? $order->company_id,
                'purchase_order_id' => $order->id,
                'receipt_number' => $this->numbering->next(ProcurementNumberingService::GoodsReceipt, $tenantId, $dto->receivedDate),
                'supplier_delivery_note_ref' => $dto->supplierDeliveryNoteRef,
                'received_date' => $dto->receivedDate,
                'received_by_user_id' => $dto->receivedByUserId,
                'warehouse_id' => $warehouse?->id,
                'inspection_status' => $inspection ? 'pending' : $dto->inspectionStatus,
                'inspection_required' => $inspection,
                'notes' => $dto->notes,
            ]);

            $eventLines = [];
            $bookedLineIds = [];

            foreach (array_values($dto->lines) as $index => $line) {
                $orderLine = PurchaseOrderLine::query()->whereKey($line['purchase_order_line_id'])->where('purchase_order_id', $order->id)->lockForUpdate()->first();

                if ($orderLine === null) {
                    throw new ProcurementRuleViolation('Line '.($index + 1).' does not belong to the purchase order.', "lines.{$index}.purchase_order_line_id");
                }

                $received = Decimal::of($line['quantity_received'], 4);
                $rejected = Decimal::of($line['quantity_rejected'] ?? '0', 4);
                $accepted = isset($line['quantity_accepted']) ? Decimal::of($line['quantity_accepted'], 4) : Decimal::sub($received, $rejected, 4);

                if (Decimal::cmp(Decimal::add($accepted, $rejected, 4), $received, 4) !== 0) {
                    throw new ProcurementRuleViolation('Line '.($index + 1).': accepted and rejected quantities must add up to the received quantity.', "lines.{$index}.quantity_accepted");
                }

                $ordered = Decimal::sub((string) $orderLine->quantity_ordered, (string) $orderLine->quantity_cancelled, 4);
                $newReceived = Decimal::add((string) $orderLine->quantity_received, $accepted, 4);

                if (Decimal::cmp($newReceived, Decimal::plusPercent($ordered, $overTolerance, 4), 4) > 0) {
                    throw new ProcurementRuleViolation("Line {$orderLine->line_number}: the received quantity exceeds the ordered quantity beyond the {$overTolerance}% tolerance.", "lines.{$index}.quantity_accepted");
                }

                $unitCost = Decimal::mul((string) $orderLine->unit_price, Decimal::of($order->exchange_rate ?? '1', 6), 4);
                $serials = array_values(array_filter(array_map('strval', (array) ($line['serial_numbers'] ?? (isset($line['serial_number']) ? [$line['serial_number']] : []))), static fn (string $serial): bool => $serial !== ''));

                $receiptLine = PurchaseReceiptLine::create([
                    'tenant_id' => $tenantId,
                    'purchase_receipt_id' => $receipt->id,
                    'purchase_order_line_id' => $orderLine->id,
                    'quantity_received' => $received,
                    'quantity_accepted' => $accepted,
                    'quantity_rejected' => $rejected,
                    'unit_cost' => $unitCost,
                    'inspection_status' => $inspection ? 'pending' : 'not_required',
                    'lot_number' => $line['lot_number'] ?? null,
                    'serial_number' => $serials[0] ?? null,
                    'serial_numbers' => $serials !== [] ? $serials : null,
                    'warehouse_location_id' => $line['warehouse_location_id'] ?? null,
                    'rejection_reason' => $line['rejection_reason'] ?? null,
                ]);

                $booked = $this->bookReceiptLine($receiptLine, $orderLine, $warehouse, $line['warehouse_bin_id'] ?? null, $accepted, $unitCost, $serials, (string) $order->supplier_id, $inspection, $index);

                $closeAt = Decimal::sub($ordered, Decimal::mul($ordered, bcdiv($underTolerance, '100', 8), 4), 4);
                $orderLine->update([
                    'quantity_received' => $newReceived,
                    'is_closed' => Decimal::cmp($newReceived, $closeAt, 4) >= 0,
                ]);

                $eventLines[] = [
                    'purchase_order_line_id' => (string) $orderLine->id,
                    'quantity_received' => (float) $received,
                    'quantity_accepted' => (float) $accepted,
                    'unit_cost' => (float) $unitCost,
                    'lot_number' => $line['lot_number'] ?? null,
                ];

                if ($booked) {
                    $bookedLineIds[] = (string) $orderLine->id;
                }
            }

            $this->refreshReceivingStatus($order);
            $this->inventoryBridge->recordStockReceipt($receipt);

            $this->auditLedger->record(action: 'GOODS_RECEIPT_PROCESSED', model: $receipt, payloadBefore: null, payloadAfter: ['receipt_number' => $receipt->receipt_number, 'lines_count' => count($dto->lines)]);

            $this->dispatcher->publishAndDispatch(new GoodsReceivedEvent(
                receiptId: $receipt->id,
                receiptNumber: $receipt->receipt_number,
                purchaseOrderId: $order->id,
                supplierDeliveryNoteRef: (string) $receipt->supplier_delivery_note_ref,
                receivedLines: $eventLines,
                tenantId: $tenantId,
                companyId: (string) ($order->company_id ?? $tenantId),
                stockBookedLineIds: $bookedLineIds,
            ));

            return $receipt->fresh('lines') ?? $receipt;
        });
    }

    /**
     * Returns received goods to the supplier: stock leaves the bin it sits in (quarantine first for goods not released
     * by the inspection), the GR/IR accrual is reversed and, for a credit return, a credit note is requested. A
     * replacement return reopens the quantity on the order instead.
     *
     * @throws ProcurementRuleViolation when a quantity exceeds what can still be returned
     */
    public function processPurchaseReturn(ProcessPurchaseReturnDTO $dto): PurchaseReturn
    {
        $tenantId = $dto->tenantId ?? (string) app(TenantContext::class)->getTenantId();

        return DB::transaction(function () use ($dto, $tenantId): PurchaseReturn {
            $order = PurchaseOrder::query()->whereKey($dto->purchaseOrderId)->lockForUpdate()->firstOrFail();

            if ($dto->supplierId !== '' && $dto->supplierId !== (string) $order->supplier_id) {
                throw new ProcurementRuleViolation('Goods go back to the supplier of the order.', 'supplier_id');
            }

            $isCredit = $dto->returnType === 'credit';

            $return = PurchaseReturn::create([
                'tenant_id' => $tenantId,
                'company_id' => $dto->companyId ?? $order->company_id,
                'purchase_order_id' => $order->id,
                'purchase_receipt_id' => $dto->purchaseReceiptId,
                'supplier_id' => $order->supplier_id,
                'return_number' => $this->numbering->next(ProcurementNumberingService::PurchaseReturn, $tenantId, $dto->returnDate),
                'return_date' => $dto->returnDate,
                'returned_by_user_id' => $dto->returnedByUserId,
                'status' => $isCredit ? 'credit_requested' : 'replacement_requested',
                'credit_note_requested_at' => $isCredit ? now() : null,
                'reason' => $dto->reason,
                'notes' => $dto->notes,
            ]);

            $documentTotal = '0.00';
            $stockValue = '0.00';

            foreach (array_values($dto->lines) as $index => $line) {
                $receiptLine = $this->returnableReceiptLine($order, $line, $dto->purchaseReceiptId, $index);
                $orderLine = PurchaseOrderLine::query()->whereKey($receiptLine->purchase_order_line_id)->lockForUpdate()->firstOrFail();
                $quantity = Decimal::of($line['quantity_returned'], 4);
                $returnable = Decimal::sub((string) $receiptLine->quantity_accepted, (string) $receiptLine->quantity_returned, 4);

                if (Decimal::cmp($quantity, $returnable, 4) > 0) {
                    throw new ProcurementRuleViolation("Line {$orderLine->line_number}: at most {$returnable} can still be returned from this receipt.", "lines.{$index}.quantity_returned");
                }

                $unitCost = Decimal::of($receiptLine->unit_cost ?? $orderLine->unit_price, 4);
                $lineTotal = Decimal::mul($quantity, (string) $orderLine->unit_price, 2);

                PurchaseReturnLine::create([
                    'tenant_id' => $tenantId,
                    'purchase_return_id' => $return->id,
                    'purchase_order_line_id' => $orderLine->id,
                    'purchase_receipt_line_id' => $receiptLine->id,
                    'quantity_returned' => $quantity,
                    'unit_price' => Decimal::of($orderLine->unit_price, 4),
                    'line_total' => $lineTotal,
                    'reason' => $line['reason'] ?? null,
                ]);

                $this->issueReturnedStock($receiptLine, $orderLine, $quantity, $unitCost, (string) $return->id);

                $receiptLine->update(['quantity_returned' => Decimal::add((string) $receiptLine->quantity_returned, $quantity, 4)]);
                $orderLine->update($isCredit
                    ? ['quantity_returned' => Decimal::add((string) $orderLine->quantity_returned, $quantity, 4)]
                    : ['quantity_received' => Decimal::sub((string) $orderLine->quantity_received, $quantity, 4), 'is_closed' => false]);

                $documentTotal = Decimal::add($documentTotal, $lineTotal, 2);
                $stockValue = Decimal::add($stockValue, Decimal::mul($quantity, $unitCost, 2), 2);
            }

            if (! $isCredit) {
                $this->refreshReceivingStatus($order);
            }

            $entry = $this->ledger->postSupplierReturn($tenantId, $return->company_id, (string) $return->id, (string) $return->return_number, $dto->returnDate, $stockValue);
            $return->update(['total_amount' => $documentTotal, 'journal_entry_id' => $entry?->id]);

            $this->inventoryBridge->recordStockReturn($return);
            $this->auditLedger->record(action: 'PURCHASE_RETURN_CREATED', model: $return, payloadBefore: null, payloadAfter: ['return_number' => $return->return_number, 'total' => $documentTotal]);

            $this->dispatcher->publishAndDispatch(new PurchaseReturnCreatedEvent(
                returnId: $return->id,
                returnNumber: (string) $return->return_number,
                purchaseOrderId: $order->id,
                supplierId: (string) $return->supplier_id,
                totalAmount: (float) $documentTotal,
                tenantId: $tenantId,
                companyId: (string) ($return->company_id ?? $tenantId)
            ));

            return $return->fresh('lines') ?? $return;
        });
    }

    /**
     * Links the credit note of the supplier (a received TD04 document, or only its reference) to a return waiting for
     * credit; the return becomes credited.
     *
     * @throws ProcurementRuleViolation when the return is not waiting for credit or the document does not fit
     */
    public function recordReturnCreditNote(PurchaseReturn $return, ?string $passiveInvoiceId, ?string $reference): PurchaseReturn
    {
        if (($passiveInvoiceId === null || $passiveInvoiceId === '') && ($reference === null || $reference === '')) {
            throw new ProcurementRuleViolation('Give the credit note document or its reference.', 'passive_invoice_id');
        }

        return DB::transaction(function () use ($return, $passiveInvoiceId, $reference): PurchaseReturn {
            $locked = PurchaseReturn::query()->whereKey($return->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'credit_requested') {
                throw new ProcurementRuleViolation("A return in status {$locked->status} is not waiting for a credit note.", 'status');
            }

            $creditNote = $passiveInvoiceId !== null && $passiveInvoiceId !== '' ? PassiveInvoice::query()->findOrFail($passiveInvoiceId) : null;

            if ($creditNote !== null && ($creditNote->document_type !== 'TD04' || (string) $creditNote->supplier_id !== (string) $locked->supplier_id)) {
                throw new ProcurementRuleViolation('The document is not a credit note of the supplier of the return.', 'passive_invoice_id');
            }

            $locked->update([
                'status' => 'credited',
                'credit_note_passive_invoice_id' => $creditNote?->id,
                'credit_note_reference' => $reference ?? $creditNote?->invoice_number,
            ]);

            $this->auditLedger->record(action: 'PURCHASE_RETURN_CREDITED', model: $locked, payloadBefore: ['status' => 'credit_requested'], payloadAfter: ['status' => 'credited']);

            return $locked;
        });
    }

    /**
     * Cancels an order before any receipt: releases the budget commitment and the framework agreement consumption.
     *
     * @throws ProcurementRuleViolation when goods were already received
     */
    public function cancelPurchaseOrder(PurchaseOrder $order, string $reason): void
    {
        DB::transaction(function () use ($order, $reason): void {
            $locked = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $from = (string) $locked->status;

            if (! PurchaseOrderStatus::allows($from, PurchaseOrderStatus::Cancelled) || $locked->lines()->where('quantity_received', '>', 0)->exists()) {
                throw new ProcurementRuleViolation("An order in status {$from} cannot be cancelled.", 'status');
            }

            $this->releaseContractConsumption($locked);
            $locked->update(['status' => PurchaseOrderStatus::Cancelled->value, 'is_locked' => false]);

            if ($locked->is_budget_encumbered) {
                $this->budgetService->releaseBudget($locked);
            }

            $this->auditLedger->record(action: 'PURCHASE_ORDER_CANCELLED', model: $locked, payloadBefore: ['status' => $from], payloadAfter: ['status' => 'cancelled', 'reason' => $reason]);

            $this->dispatcher->publishAndDispatch(new PurchaseOrderCancelledEvent(
                purchaseOrderId: $locked->id,
                poNumber: (string) ($locked->po_number ?? $locked->order_number),
                reason: $reason,
                tenantId: (string) $locked->tenant_id,
                companyId: (string) ($locked->company_id ?? $locked->tenant_id),
                cancelledAmount: (float) $locked->total_amount
            ));
        });
    }

    /**
     * Sets the order received or partially received from its lines.
     */
    public function refreshReceivingStatus(PurchaseOrder $order): void
    {
        $open = PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->where('is_closed', false)->exists();
        $any = PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->where('quantity_received', '>', 0)->exists();
        $target = ! $open ? PurchaseOrderStatus::Received : ($any ? PurchaseOrderStatus::PartiallyReceived : PurchaseOrderStatus::from((string) $order->status));

        PurchaseOrderStatus::assertTransition((string) $order->status, $target);
        $order->update(['status' => $target->value]);
    }

    /**
     * @throws ProcurementRuleViolation when the supplier cannot receive orders
     */
    public function assertSupplierCanOrder(Supplier $supplier): void
    {
        if (in_array($supplier->status, ['suspended', 'blocked', 'inactive'], true) || $supplier->is_locked) {
            throw new ProcurementRuleViolation("The supplier is {$supplier->status}: no order can be issued.", 'supplier_id');
        }

        $qualification = SupplierQualificationStatus::tryFrom((string) $supplier->qualification_status) ?? SupplierQualificationStatus::Qualified;

        if (! $qualification->canOrder()) {
            throw new ProcurementRuleViolation("The supplier qualification is {$qualification->value}: no order can be issued.", 'supplier_id');
        }

        $expired = SupplierDocument::query()->where('supplier_id', $supplier->id)->where('is_mandatory', true)->whereNotNull('expires_at')->where('expires_at', '<', now()->toDateString())->exists();

        if ($expired) {
            throw new ProcurementRuleViolation('A mandatory qualification document of the supplier is expired: no order can be issued.', 'supplier_id');
        }

        if (! Contact::query()->whereKey($supplier->id)->exists()) {
            throw new ProcurementRuleViolation('The supplier has no contact record with the same id: link it to a contact first.', 'supplier_id');
        }
    }

    /**
     * Books the accepted quantity of a receipt line on the stock item of its catalogue entry; a line without a stock
     * item (service, non-stock purchase) moves nothing. Returns whether stock was booked.
     *
     * @param  list<string>  $serials
     */
    private function bookReceiptLine(PurchaseReceiptLine $receiptLine, PurchaseOrderLine $orderLine, ?Warehouse $warehouse, ?string $binId, string $accepted, string $unitCost, array $serials, string $supplierId, bool $inspection, int $index): bool
    {
        if (! Decimal::isPositive($accepted)) {
            return false;
        }

        $item = $this->stockItems->resolve((string) $orderLine->id, (string) $orderLine->tenant_id);

        if ($item === null) {
            return false;
        }

        if ($warehouse === null) {
            throw new ProcurementRuleViolation('The company has no active warehouse to receive stocked items into.', 'warehouse_id');
        }

        $bin = match (true) {
            $inspection => $this->stock->quarantineBin($warehouse),
            $binId !== null => $this->binOfWarehouse($binId, (string) $warehouse->id, $index),
            default => $this->stock->receivingBin($warehouse),
        };

        $booked = $this->stock->receive($item, $bin, $accepted, $unitCost, $receiptLine->lot_number, $serials, (string) $receiptLine->purchase_receipt_id, $supplierId, $inspection);
        $receiptLine->update(['warehouse_bin_id' => $booked['warehouse_bin_id']]);

        return true;
    }

    private function binOfWarehouse(string $binId, string $warehouseId, int $index): WarehouseBin
    {
        $bin = WarehouseBin::query()->whereKey($binId)->whereHas('rack.aisle.zone', static fn ($zone) => $zone->where('warehouse_id', $warehouseId))->first();

        if ($bin === null) {
            throw new ProcurementRuleViolation('The bin of line '.($index + 1).' is not a location of the receiving warehouse.', "lines.{$index}.warehouse_bin_id");
        }

        return $bin;
    }

    /**
     * Takes the returned quantity out of stock: from the receipt bin while goods sit in quarantine, then from the
     * receiving bin of the warehouse.
     */
    private function issueReturnedStock(PurchaseReceiptLine $receiptLine, PurchaseOrderLine $orderLine, string $quantity, string $unitCost, string $returnId): void
    {
        $item = $this->stockItems->resolve((string) $orderLine->id, (string) $orderLine->tenant_id);

        if ($item === null || $receiptLine->warehouse_bin_id === null) {
            return;
        }

        $receiptBin = WarehouseBin::query()->findOrFail($receiptLine->warehouse_bin_id);
        $lotId = $this->lotIdOf($item, $receiptLine->lot_number);
        $remaining = $quantity;

        if ($receiptLine->inspection_status !== 'not_required') {
            $inQuarantine = Decimal::sub(Decimal::sub((string) $receiptLine->quantity_accepted, (string) $receiptLine->quantity_released, 4), (string) $receiptLine->quantity_returned, 4);
            $fromQuarantine = Decimal::min($inQuarantine, $remaining, 4);

            if (Decimal::isPositive($fromQuarantine)) {
                $this->stock->returnToSupplier($item, $receiptBin, $fromQuarantine, $unitCost, $lotId, $returnId);
                $remaining = Decimal::sub($remaining, $fromQuarantine, 4);
            }

            $receiptBin = $this->stock->receivingBin(Warehouse::query()->findOrFail($receiptLine->receipt?->warehouse_id));
        }

        if (Decimal::isPositive($remaining)) {
            $this->stock->returnToSupplier($item, $receiptBin, $remaining, $unitCost, $lotId, $returnId);
        }
    }

    private function lotIdOf(StockItem $item, ?string $lotNumber): ?string
    {
        if ($lotNumber === null || $lotNumber === '') {
            return null;
        }

        $id = StockLot::query()->where('stock_item_id', $item->id)->where('lot_number', $lotNumber)->value('id');

        return $id !== null ? (string) $id : null;
    }

    /**
     * The receipt line a return line takes goods from: the one named, else the latest receipt of the order line.
     *
     * @param  array<string, mixed>  $line
     */
    private function returnableReceiptLine(PurchaseOrder $order, array $line, ?string $receiptId, int $index): PurchaseReceiptLine
    {
        $query = PurchaseReceiptLine::query()
            ->whereHas('receipt', static fn ($receipt) => $receipt->where('purchase_order_id', $order->id))
            ->lockForUpdate();

        if (! empty($line['purchase_receipt_line_id'])) {
            $receiptLine = $query->whereKey($line['purchase_receipt_line_id'])->first();
        } else {
            $receiptLine = $query
                ->where('purchase_order_line_id', $line['purchase_order_line_id'] ?? '')
                ->when($receiptId !== null, static fn ($q) => $q->where('purchase_receipt_id', $receiptId))
                ->whereColumn('quantity_returned', '<', 'quantity_accepted')
                ->orderByDesc('created_at')
                ->first();
        }

        if ($receiptLine === null) {
            throw new ProcurementRuleViolation('Line '.($index + 1).' names no received goods of this order.', "lines.{$index}.purchase_receipt_line_id");
        }

        return $receiptLine;
    }

    /**
     * @throws ProcurementRuleViolation when the agreement does not apply
     */
    private function assertContractUsable(?SupplierContract $contract, Supplier $supplier, string $orderDate): void
    {
        if ($contract === null || (string) $contract->supplier_id !== (string) $supplier->id) {
            throw new ProcurementRuleViolation('The agreement does not belong to the supplier.', 'supplier_contract_id');
        }

        if ($contract->status !== 'active') {
            throw new ProcurementRuleViolation("The agreement is {$contract->status}: no call-off can be ordered.", 'supplier_contract_id');
        }

        if ($contract->starts_at->toDateString() > $orderDate || $contract->ends_at->toDateString() < $orderDate) {
            throw new ProcurementRuleViolation('The order date is outside the validity of the agreement.', 'supplier_contract_id');
        }
    }

    /**
     * @throws ProcurementRuleViolation when the agreement line does not cover the quantity on the date
     */
    private function contractLineFor(?SupplierContract $contract, string $contractLineId, string $quantity, string $orderDate, int $index): SupplierContractLine
    {
        $field = "lines.{$index}.supplier_contract_line_id";
        $contractLine = $contract === null ? null : SupplierContractLine::query()->whereKey($contractLineId)->where('supplier_contract_id', $contract->id)->lockForUpdate()->first();

        if ($contractLine === null) {
            throw new ProcurementRuleViolation('The agreement line does not belong to the agreement of the order.', $field);
        }

        if (! $contractLine->isPriceValidOn($orderDate)) {
            throw new ProcurementRuleViolation('The agreed price of line '.($index + 1).' is not valid on the order date.', $field);
        }

        if ($contractLine->committed_quantity !== null) {
            $remaining = Decimal::sub((string) $contractLine->committed_quantity, (string) $contractLine->released_quantity, 4);

            if (Decimal::cmp($quantity, $remaining, 4) > 0) {
                throw new ProcurementRuleViolation('Line '.($index + 1)." exceeds the remaining quantity of the agreement ({$remaining}).", $field);
            }
        }

        return $contractLine;
    }

    /**
     * Gives back to the agreement what the lines of the order consumed.
     */
    private function releaseContractConsumption(PurchaseOrder $order): void
    {
        if ($order->supplier_contract_id === null) {
            return;
        }

        $lines = PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->get();

        if ($lines->isEmpty()) {
            return;
        }

        $contract = SupplierContract::query()->whereKey($order->supplier_contract_id)->lockForUpdate()->first();
        $net = '0.00';

        foreach ($lines as $line) {
            $net = Decimal::add($net, (string) $line->line_net_amount, 2);

            if ($line->supplier_contract_line_id !== null) {
                $contractLine = SupplierContractLine::query()->whereKey($line->supplier_contract_line_id)->lockForUpdate()->first();
                $contractLine?->update(['released_quantity' => Decimal::max('0', Decimal::sub((string) $contractLine->released_quantity, (string) $line->quantity_ordered, 4), 4)]);
            }
        }

        $contract?->update(['consumed_amount' => Decimal::max('0', Decimal::sub((string) $contract->consumed_amount, $net, 2), 2)]);
    }

    private function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
