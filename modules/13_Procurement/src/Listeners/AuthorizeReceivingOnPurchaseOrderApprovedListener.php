<?php

declare(strict_types=1);

namespace Modules\Procurement\Listeners;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Modules\Procurement\Events\PurchaseOrderApprovedEvent;
use Modules\Procurement\Events\PurchaseOrderCommittedEvent;
use Modules\Procurement\Models\PurchaseOrder;

/**
 * Once an order is approved it can be received; the commitment towards the supplier is announced to accounting (a
 * management-ledger encumbrance). The status itself is set by the approval, never here.
 */
final readonly class AuthorizeReceivingOnPurchaseOrderApprovedListener implements ShouldQueueAfterCommit
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private ?AuditLedgerInterface $auditLedger = null
    ) {}

    public function handle(PurchaseOrderApprovedEvent $event): void
    {
        $order = PurchaseOrder::find($event->purchaseOrderId);
        if ($order === null || $order->status === 'cancelled') {
            return;
        }

        $this->auditLedger?->record(
            action: 'PURCHASE_ORDER_RECEIVING_AUTHORIZED',
            model: $order,
            payloadBefore: null,
            payloadAfter: ['status' => $order->status, 'is_budget_encumbered' => (bool) $order->is_budget_encumbered],
            moduleCode: 'PROCUREMENT'
        );

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
