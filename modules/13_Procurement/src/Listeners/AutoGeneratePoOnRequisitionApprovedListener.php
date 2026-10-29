<?php

declare(strict_types=1);

namespace Modules\Procurement\Listeners;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Procurement\Events\PurchaseOrderCreatedEvent;
use Modules\Procurement\Events\PurchaseRequisitionApprovedEvent;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderLine;
use Modules\Procurement\Models\PurchaseRequisition;
use Modules\Procurement\Models\Supplier;

final readonly class AutoGeneratePoOnRequisitionApprovedListener implements ShouldQueue
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private ?AuditLedgerInterface $auditLedger = null
    ) {}

    public function handle(PurchaseRequisitionApprovedEvent $event): void
    {
        $requisition = PurchaseRequisition::with('lines')->find($event->requisitionId);
        if ($requisition === null || $requisition->status !== 'approved') {
            return;
        }

        DB::transaction(function () use ($requisition): void {
            // Find preferred or default approved supplier
            $firstLine = $requisition->lines->first();
            $supplierId = $firstLine?->suggested_supplier_id;
            if (! $supplierId) {
                $supplierId = Supplier::where('is_approved_vendor', true)
                    ->where('status', 'active')
                    ->value('id');
            }

            if (! $supplierId) {
                return;
            }

            $poNumber = 'PO-'.now()->format('Ymd').'-'.strtoupper(Str::random(4));
            $order = PurchaseOrder::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $requisition->tenant_id,
                'company_id' => $requisition->company_id,
                'supplier_id' => $supplierId,
                'po_number' => $poNumber,
                'order_number' => $poNumber,
                'order_type' => 'standard',
                'order_date' => now()->toDateString(),
                'expected_delivery_date' => $requisition->required_date?->toDateString() ?? now()->addDays(14)->toDateString(),
                'status' => 'draft',
                'currency' => 'EUR',
                'exchange_rate' => 1.0,
                'net_amount' => $requisition->estimated_total ?? 0.0,
                'tax_amount' => round(((float) $requisition->estimated_total) * 0.22, 2),
                'total_amount' => round(((float) $requisition->estimated_total) * 1.22, 2),
                'is_budget_encumbered' => true,
                'budget_committed_amount' => $requisition->estimated_total ?? 0.0,
                'is_locked' => false,
                'created_by' => $requisition->requester_id,
            ]);

            foreach ($requisition->lines as $reqLine) {
                $net = ((float) $reqLine->quantity) * ((float) $reqLine->estimated_unit_price);
                PurchaseOrderLine::create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $requisition->tenant_id,
                    'purchase_order_id' => $order->id,
                    'product_id' => $reqLine->product_id,
                    'description' => $reqLine->description ?? 'Item from Requisition '.$requisition->requisition_number,
                    'quantity_ordered' => $reqLine->quantity,
                    'quantity_received' => 0.0,
                    'quantity_cancelled' => 0.0,
                    'unit_price' => $reqLine->estimated_unit_price,
                    'subtotal' => $net,
                    'tax_rate' => 22.0,
                    'tax_amount' => round($net * 0.22, 2),
                    'total' => round($net * 1.22, 2),
                ]);
            }

            $requisition->update(['status' => 'converted_to_po']);

            $this->auditLedger?->record(
                action: 'PURCHASE_REQUISITION_AUTO_CONVERTED_TO_PO',
                model: $order,
                payloadBefore: ['requisition_id' => $requisition->id],
                payloadAfter: ['purchase_order_id' => $order->id, 'po_number' => $order->po_number],
                moduleCode: 'PROCUREMENT'
            );

            $this->eventDispatcher->dispatch(new PurchaseOrderCreatedEvent(
                purchaseOrderId: (string) $order->id,
                poNumber: (string) $order->po_number,
                supplierId: (string) $order->supplier_id,
                totalAmount: (float) $order->total_amount,
                currency: (string) $order->currency,
                tenantId: (string) $order->tenant_id,
                companyId: (string) ($order->company_id ?? '')
            ));
        });
    }
}
