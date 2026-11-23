<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use App\Enums\PurchaseOrderStatus;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Procurement\Enums\PurchaseOrderDispatchChannel;
use Modules\Procurement\Events\PurchaseOrderMilestoneEvent;
use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseOrderDispatch;

/**
 * Transmits an approved purchase order to the supplier by PDF, email, EDI or Peppol and keeps the dispatch log. The
 * first transmission moves the order from approved to sent; later ones (a re-send) only add a log entry. The event
 * published on the bus is what the communication and e-document network modules deliver.
 */
final readonly class PurchaseOrderDispatchService
{
    public function __construct(
        private PurchaseOrderLifecycleService $lifecycle,
        private ProcurementAuditLedgerService $auditLedger,
        private EventDispatcher $dispatcher,
    ) {}

    /**
     * @throws ProcurementRuleViolation when the order is not approved or the channel needs a recipient
     */
    public function dispatch(PurchaseOrder $order, PurchaseOrderDispatchChannel $channel, ?string $recipient = null, ?string $actorId = null): PurchaseOrderDispatch
    {
        $status = (string) $order->status;

        if (! in_array($status, [PurchaseOrderStatus::Approved->value, PurchaseOrderStatus::Sent->value, PurchaseOrderStatus::Confirmed->value, PurchaseOrderStatus::PartiallyReceived->value], true)) {
            throw new ProcurementRuleViolation("An order in status {$status} cannot be sent to the supplier.", 'status');
        }

        if ($channel !== PurchaseOrderDispatchChannel::Pdf && ($recipient === null || trim($recipient) === '')) {
            throw new ProcurementRuleViolation('A recipient (email address or network identifier) is required for this channel.', 'recipient');
        }

        return DB::transaction(function () use ($order, $channel, $recipient, $actorId, $status): PurchaseOrderDispatch {
            $current = $status === PurchaseOrderStatus::Approved->value ? $this->lifecycle->markSent($order) : $order;
            $number = (string) ($current->po_number ?? $current->order_number);

            $dispatch = PurchaseOrderDispatch::create([
                'tenant_id' => $current->tenant_id,
                'purchase_order_id' => $current->id,
                'channel' => $channel->value,
                'status' => 'sent',
                'recipient' => $recipient,
                'message_reference' => strtoupper($channel->value).'-'.$number.'-'.Str::upper(Str::random(8)),
                'dispatched_at' => now(),
                'dispatched_by' => $actorId,
                'metadata' => ['po_status' => (string) $current->status],
            ]);

            $current->update(['dispatch_channel' => $channel->value]);
            $this->auditLedger->record(action: 'PURCHASE_ORDER_DISPATCHED', model: $current, payloadBefore: ['status' => $status], payloadAfter: ['channel' => $channel->value, 'reference' => $dispatch->message_reference]);

            $this->dispatcher->publishAndDispatch(new PurchaseOrderMilestoneEvent(
                name: PurchaseOrderMilestoneEvent::Dispatched,
                purchaseOrderId: (string) $current->id,
                poNumber: $number,
                tenantId: (string) $current->tenant_id,
                companyId: (string) ($current->company_id ?? $current->tenant_id),
                data: ['channel' => $channel->value, 'recipient' => $recipient, 'message_reference' => $dispatch->message_reference],
            ));

            return $dispatch;
        });
    }
}
