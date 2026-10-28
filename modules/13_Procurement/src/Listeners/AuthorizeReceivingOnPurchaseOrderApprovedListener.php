<?php

declare(strict_types=1);

namespace Modules\Procurement\Listeners;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\Procurement\Events\PurchaseOrderApprovedEvent;
use Modules\Procurement\Events\PurchaseOrderCommittedEvent;
use Modules\Procurement\Models\PurchaseOrder;

final readonly class AuthorizeReceivingOnPurchaseOrderApprovedListener implements ShouldQueue
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private ?AuditLedgerInterface $auditLedger = null
    ) {}

    public function handle(PurchaseOrderApprovedEvent $event): void
    {
        $order = PurchaseOrder::find($event->purchaseOrderId);
        if ($order === null) {
            return;
        }

        $beforeState = ['status' => $order->status, 'is_locked' => $order->is_locked];

        // Ensure unlocked and ready for warehouse dock receipt
        $order->update([
            'status' => 'approved',
            'is_locked' => false,
            'is_budget_encumbered' => true,
        ]);

        $this->auditLedger?->record(
            action: 'PURCHASE_ORDER_RECEIVING_AUTHORIZED',
            model: $order,
            payloadBefore: $beforeState,
            payloadAfter: ['status' => 'approved', 'is_budget_encumbered' => true],
            moduleCode: 'PROCUREMENT'
        );

        // Dispatch Accounting Commitment Event
        $this->eventDispatcher->dispatch(new PurchaseOrderCommittedEvent(
            purchaseOrderId: (string) $order->id,
            poNumber: (string) ($order->po_number ?? $order->order_number),
            supplierId: (string) $order->supplier_id,
            committedAmount: (float) $order->total_amount,
            currency: (string) $order->currency,
            tenantId: (string) $order->tenant_id,
            companyId: (string) ($order->company_id ?? '')
        ));
    }
}
