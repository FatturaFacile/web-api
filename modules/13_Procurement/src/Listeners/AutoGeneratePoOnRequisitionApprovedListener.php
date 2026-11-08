<?php

declare(strict_types=1);

namespace Modules\Procurement\Listeners;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Procurement\DTOs\CreatePurchaseOrderDTO;
use Modules\Procurement\Enums\PurchaseRequisitionStatus;
use Modules\Procurement\Events\PurchaseRequisitionApprovedEvent;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\PurchaseRequisitionLine;
use Modules\Procurement\Services\ProcurementService;

/**
 * An approved requisition whose lines all name a suggested supplier becomes one draft purchase order per supplier, at
 * the estimated prices. A requisition with a line without supplier stays approved, to be sourced with a request for
 * quotation.
 */
final readonly class AutoGeneratePoOnRequisitionApprovedListener implements ShouldQueueAfterCommit
{
    public function __construct(
        private ProcurementService $procurement,
        private ?AuditLedgerInterface $auditLedger = null
    ) {}

    public function handle(PurchaseRequisitionApprovedEvent $event): void
    {
        $requisition = PurchaseRequisition::withoutGlobalScopes()->with('lines')->where('tenant_id', $event->tenantId)->find($event->requisitionId);

        if ($requisition === null || $requisition->status !== PurchaseRequisitionStatus::Approved->value || $requisition->lines->isEmpty()) {
            return;
        }

        if ($requisition->lines->contains(static fn (PurchaseRequisitionLine $line): bool => $line->suggested_supplier_id === null)) {
            return;
        }

        try {
            DB::transaction(function () use ($requisition): void {
                foreach ($requisition->lines->groupBy('suggested_supplier_id') as $supplierId => $lines) {
                    $order = $this->procurement->createPurchaseOrder(new CreatePurchaseOrderDTO(
                        supplierId: (string) $supplierId,
                        orderDate: now()->toDateString(),
                        expectedDeliveryDate: $requisition->required_by_date?->toDateString() ?? now()->addDays(14)->toDateString(),
                        lines: $lines->map(static fn (PurchaseRequisitionLine $line): array => [
                            'description' => (string) $line->description,
                            'quantity_ordered' => (string) $line->quantity_requested,
                            'unit_price' => (string) $line->estimated_unit_price,
                            'uom' => (string) $line->uom,
                            'supplier_catalog_id' => $line->supplier_catalog_id,
                        ])->values()->all(),
                        requisitionId: (string) $requisition->id,
                        projectId: $requisition->project_id,
                        costCenterId: $requisition->cost_center_id,
                        departmentId: $requisition->department_id,
                        branchId: $requisition->branch_id,
                        currency: (string) $requisition->currency,
                        notes: "Generated from requisition {$requisition->requisition_number}",
                        tenantId: (string) $requisition->tenant_id,
                        companyId: $requisition->company_id,
                        creatorUserId: $requisition->created_by ?? $requisition->requestor_user_id,
                    ));

                    $this->auditLedger?->record(
                        action: 'PURCHASE_REQUISITION_AUTO_CONVERTED_TO_PO',
                        model: $order,
                        payloadBefore: ['requisition_id' => $requisition->id],
                        payloadAfter: ['purchase_order_id' => $order->id, 'po_number' => $order->po_number],
                        moduleCode: 'PROCUREMENT'
                    );
                }

                $requisition->update(['status' => PurchaseRequisitionStatus::Ordered->value]);
            });
        } catch (ProcurementRuleViolation $violation) {
            // A supplier that cannot receive orders leaves the requisition approved for manual sourcing.
            Log::warning('Approved requisition not converted into purchase orders.', ['requisition_id' => $requisition->id, 'reason' => $violation->getMessage()]);
        }
    }
}
