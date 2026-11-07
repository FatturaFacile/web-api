<?php

declare(strict_types=1);

namespace Modules\Invoicing\Listeners;

use App\Actions\Sales\RecordPayment;
use App\Models\Invoice;
use Modules\Invoicing\Contracts\CustomerLedgerServiceInterface;
use Modules\Invoicing\Models\CustomerLedgerEntry;
use Modules\Treasury\Events\CollectionReceivedEvent;
use Ramsey\Uuid\Uuid;

/**
 * A collection confirmed by Treasury settles its invoice like any other payment: it becomes a payment row through
 * RecordPayment, which locks the invoice, refuses an over-collection and derives the paid total from the payments and
 * the applied credit notes, and the payment reaches the ledger (and the customer ledger) through the payment ledger.
 *
 * Each receipt is identified by its event id, so the same receipt delivered twice is recorded once.
 */
final readonly class TreasuryCollectionEventListener
{
    public function __construct(
        private CustomerLedgerServiceInterface $ledgerService,
        private RecordPayment $recordPayment
    ) {}

    public function handle(CollectionReceivedEvent $event): void
    {
        $reference = 'COLL-'.$event->collectionId.'/'.$this->receiptKey($event);
        $invoice = $event->invoiceId === null ? null : Invoice::withoutGlobalScopes()
            ->where('tenant_id', $event->tenantId)
            ->whereKey($event->invoiceId)
            ->first();

        if ($invoice !== null) {
            $this->recordPayment->execute($invoice, [
                'amount' => number_format($event->collectedAmount, 2, '.', ''),
                'paid_at' => $event->collectionDate,
                'reference' => $reference,
                'notes' => 'Treasury collection '.$event->collectionId,
                'unique_reference' => true,
            ]);

            return;
        }

        // A collection without an invoice only moves the running balance of the customer, once per receipt.
        $alreadyRecorded = CustomerLedgerEntry::withoutGlobalScopes()
            ->where('tenant_id', $event->tenantId)
            ->where('entry_type', 'payment')
            ->where('document_reference', $reference)
            ->exists();

        if ($alreadyRecorded) {
            return;
        }

        $this->ledgerService->recordPaymentEntry(
            customerId: $event->customerId,
            amount: $event->collectedAmount,
            paymentReference: $reference,
            tenantId: $event->tenantId,
            invoiceId: null
        );
    }

    /**
     * The identity of one receipt: the event id Treasury assigns, or a key derived from what the receipt states.
     */
    private function receiptKey(CollectionReceivedEvent $event): string
    {
        if ($event->eventId !== '') {
            return $event->eventId;
        }

        return Uuid::uuid5(Uuid::NAMESPACE_OID, implode('|', [$event->collectionId, number_format($event->collectedAmount, 2, '.', ''), $event->collectionDate]))->toString();
    }
}
