<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Listeners;

use Enterprise\EventBus\Models\TransactionalInbox;
use Illuminate\Support\Str;
use Modules\Invoicing\Events\InvoicePostedEvent;
use Modules\SubscriptionBilling\Models\InvoiceSchedule;

final readonly class SalesInvoiceSubscriptionListener
{
    public function handle(InvoicePostedEvent $event): void
    {
        $messageId = $event->getEventId();
        if (TransactionalInbox::where('message_id', $messageId)->exists()) {
            return;
        }

        TransactionalInbox::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $event->tenantId,
            'message_id' => $messageId,
            'event_type' => $event->getEventName(),
            'payload_json' => $event->toPayload(),
            'received_at' => now(),
            'status' => 'processed',
            'processed_at' => now(),
        ]);

        // Reconcile matching scheduled invoice
        InvoiceSchedule::where('tenant_id', $event->tenantId)
            ->where(function ($q) use ($event) {
                $q->where('invoice_id', $event->invoiceId)
                    ->orWhere('id', $event->invoiceId);
            })
            ->update([
                'status' => 'invoiced',
            ]);
    }
}
