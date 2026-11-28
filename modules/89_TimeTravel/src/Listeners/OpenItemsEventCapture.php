<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Listeners;

use App\Models\PassiveInvoice;
use Modules\GeneralLedger\Events\PassiveInvoicePostedEvent;
use Modules\Invoicing\Events\CreditNoteIssuedEvent;
use Modules\Invoicing\Events\CustomerCreditLimitUpdatedEvent;
use Modules\Invoicing\Events\InvoicePaidEvent;
use Modules\Invoicing\Events\InvoicePostedEvent;
use Modules\TimeTravel\Domain\Enums\AggregateType;
use Modules\TimeTravel\Models\BiTemporalEvent;
use Modules\TimeTravel\Support\Amount;
use Modules\Treasury\Events\CollectionReceivedEvent;
use Modules\Treasury\Events\PaymentExecutedEvent;
use Modules\Treasury\Models\PaymentLine;

/**
 * Open items and aging (receivables and payables): invoices issued and received, credit notes, payments reconciled and credit limit
 * changes. The valid time is the document or payment date; the recording time is when the system learned it.
 */
final class OpenItemsEventCapture
{
    use CapturesDomainEvents;

    public function onInvoicePosted(InvoicePostedEvent $event): void
    {
        if (! $this->enabled($event->tenantId) || $this->recorder->exists($event->tenantId, AggregateType::OpenItems, $event->invoiceId, ['InvoiceIssued'])) {
            return;
        }

        $this->record($event->tenantId, AggregateType::OpenItems, $event->invoiceId, 'InvoiceIssued', $this->validAtOf($event->date), [
            'document_id' => $event->invoiceId,
            'document_number' => $event->invoiceNumber,
            'partner_id' => $event->customerId,
            'partner_type' => 'customer',
            'issue_date' => substr($event->date, 0, 10),
            'due_date' => $event->dueDate !== null ? substr($event->dueDate, 0, 10) : null,
            'currency' => $event->currency,
            'gross' => Amount::of($event->totalGross),
            'gl_journal_entry_id' => $event->glJournalEntryId,
        ], 'invoicing.invoice.posted');
    }

    public function onCreditNoteIssued(CreditNoteIssuedEvent $event): void
    {
        if (! $this->enabled($event->tenantId)) {
            return;
        }

        $this->record($event->tenantId, AggregateType::OpenItems, $event->creditNoteId, 'CreditNoteIssued', $this->validAtOf($event->getOccurredAt()), [
            'credit_note_id' => $event->creditNoteId,
            'credit_note_number' => $event->creditNoteNumber,
            'original_invoice_id' => $event->originalInvoiceId,
            'partner_id' => $event->customerId,
            'partner_type' => 'customer',
            'credited_amount' => Amount::of($event->creditedAmount),
        ], 'invoicing.credit_note.issued');
    }

    public function onCollectionReceived(CollectionReceivedEvent $event): void
    {
        if (! $this->enabled($event->tenantId) || $event->invoiceId === null || $event->invoiceId === '') {
            return;
        }

        $this->record($event->tenantId, AggregateType::OpenItems, $event->invoiceId, 'PaymentReconciled', $this->validAtOf($event->collectionDate), [
            'document_id' => $event->invoiceId,
            'partner_id' => $event->customerId,
            'partner_type' => 'customer',
            'paid_amount' => Amount::of($event->collectedAmount),
            'paid_at' => substr($event->collectionDate, 0, 10),
            'collection_id' => $event->collectionId,
        ], 'treasury.collection.received');
    }

    /**
     * An invoice settled without a treasury collection (a payment recorded on the invoice): the part of the amount paid that no
     * reconciliation event covers yet is reconciled now, so a collection is never counted twice.
     */
    public function onInvoicePaid(InvoicePaidEvent $event): void
    {
        if (! $this->enabled($event->tenantId)) {
            return;
        }

        $recorded = '0';

        $reconciliations = BiTemporalEvent::withoutGlobalScopes()
            ->where('tenant_id', $event->tenantId)
            ->where('stream_id', AggregateType::OpenItems->streamId())
            ->where('aggregate_id', $event->invoiceId)
            ->where('event_type', 'PaymentReconciled')
            ->get(['payload']);

        foreach ($reconciliations as $reconciliation) {
            $recorded = Amount::add($recorded, $reconciliation->payload['paid_amount'] ?? 0);
        }

        $remainder = Amount::sub($event->totalPaid, $recorded);

        if (Amount::cmp($remainder, 0) <= 0) {
            return;
        }

        $this->record($event->tenantId, AggregateType::OpenItems, $event->invoiceId, 'PaymentReconciled', $this->validAtOf($event->paidAt), [
            'document_id' => $event->invoiceId,
            'partner_id' => $event->customerId,
            'partner_type' => 'customer',
            'paid_amount' => $remainder,
            'paid_at' => substr($event->paidAt, 0, 10),
        ], 'invoicing.invoice.paid');
    }

    public function onCreditLimitUpdated(CustomerCreditLimitUpdatedEvent $event): void
    {
        if (! $this->enabled($event->tenantId)) {
            return;
        }

        $this->record($event->tenantId, AggregateType::OpenItems, $event->customerId, 'CreditLimitAdjusted', $this->validAtOf($event->getOccurredAt()), [
            'credit_profile_id' => $event->creditProfileId,
            'customer_id' => $event->customerId,
            'old_limit' => Amount::of($event->oldLimit),
            'new_limit' => Amount::of($event->newLimit),
        ], 'invoicing.credit_limit.updated', $event->updatedBy);
    }

    public function onPassiveInvoicePosted(PassiveInvoicePostedEvent $event): void
    {
        if (! $this->enabled($event->tenantId) || $this->recorder->exists($event->tenantId, AggregateType::OpenItems, $event->invoiceId, ['InvoiceReceived'])) {
            return;
        }

        $invoice = PassiveInvoice::withoutGlobalScopes()->where('tenant_id', $event->tenantId)->whereKey($event->invoiceId)->first();

        if ($invoice === null) {
            return;
        }

        $gross = Amount::of(abs((float) $invoice->total_amount));
        $date = substr((string) $invoice->date, 0, 10);

        $this->record($event->tenantId, AggregateType::OpenItems, $event->invoiceId, 'InvoiceReceived', $this->validAtOf($date), [
            'document_id' => $event->invoiceId,
            'document_number' => (string) $invoice->invoice_number,
            'partner_id' => (string) $invoice->supplier_id,
            'partner_type' => 'supplier',
            'issue_date' => $date,
            'due_date' => $invoice->due_date !== null ? substr((string) $invoice->due_date, 0, 10) : null,
            'currency' => (string) $invoice->currency,
            // A supplier credit note (TD04) is a receivable from the supplier: a negative open item.
            'gross' => $invoice->document_type === 'TD04' ? Amount::neg($gross) : $gross,
        ], 'general_ledger.passive_invoice.posted');
    }

    public function onPaymentExecuted(PaymentExecutedEvent $event): void
    {
        if (! $this->enabled($event->tenantId) || $event->executedLineIds === []) {
            return;
        }

        $lines = PaymentLine::withoutGlobalScopes()
            ->where('tenant_id', $event->tenantId)
            ->where('payment_batch_id', $event->paymentBatchId)
            ->whereIn('id', $event->executedLineIds)
            ->whereNotNull('invoice_id')
            ->get();

        foreach ($lines as $line) {
            $this->record($event->tenantId, AggregateType::OpenItems, (string) $line->invoice_id, 'PaymentReconciled', $this->validAtOf((string) ($event->valueDate ?? $event->getOccurredAt())), [
                'document_id' => (string) $line->invoice_id,
                'partner_id' => $line->recipient_id !== null ? (string) $line->recipient_id : '',
                'partner_type' => 'supplier',
                'paid_amount' => Amount::of($line->amount),
                'paid_at' => substr((string) ($event->valueDate ?? $event->getOccurredAt()), 0, 10),
                'payment_line_id' => (string) $line->id,
            ], 'treasury.payment.executed');
        }
    }
}
