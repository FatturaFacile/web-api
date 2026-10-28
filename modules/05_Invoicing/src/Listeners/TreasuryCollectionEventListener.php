<?php

declare(strict_types=1);

namespace Modules\Invoicing\Listeners;

use Modules\Invoicing\Contracts\CustomerLedgerServiceInterface;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Treasury\Events\CollectionReceivedEvent;

final readonly class TreasuryCollectionEventListener
{
    public function __construct(
        private CustomerLedgerServiceInterface $ledgerService
    ) {}

    public function handle(CollectionReceivedEvent $event): void
    {
        $this->ledgerService->recordPaymentEntry(
            customerId: $event->customerId,
            amount: $event->collectedAmount,
            paymentReference: 'COLL-'.$event->collectionId,
            tenantId: $event->tenantId,
            invoiceId: $event->invoiceId
        );

        if ($event->invoiceId !== null) {
            $invoice = SalesInvoice::find($event->invoiceId);
            if ($invoice !== null) {
                $newPaid = (float) $invoice->total_paid + $event->collectedAmount;
                $isPaid = $newPaid >= (float) $invoice->total_gross;

                $invoice->update([
                    'total_paid' => $newPaid,
                    'payment_status' => $isPaid ? 'paid' : 'partially_paid',
                ]);
            }
        }
    }
}
