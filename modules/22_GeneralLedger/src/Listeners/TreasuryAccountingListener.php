<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Listeners;

use App\Actions\Purchases\RecordSupplierPayment;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\PayrollLedgerSetting;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;
use Modules\GeneralLedger\Services\LedgerAccountResolver;
use Modules\Treasury\Events\CollectionReceivedEvent;
use Modules\Treasury\Events\PaymentExecutedEvent;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\PaymentBatch;
use Modules\Treasury\Models\PaymentLine;
use Ramsey\Uuid\Uuid;

/**
 * Posts what the bank confirmed. PaymentExecutedEvent exists only after a pain.002 or a manual confirmation, and names
 * the lines that were really executed; a rejected line has no entry.
 */
final readonly class TreasuryAccountingListener
{
    public function __construct(
        private DoubleEntryPostingServiceInterface $postingService,
        private FiscalPeriodResolver $periods,
        private LedgerAccountResolver $accounts,
        private RecordSupplierPayment $supplierPayments,
    ) {}

    public function handlePaymentExecuted(PaymentExecutedEvent $event): void
    {
        $batch = PaymentBatch::withoutGlobalScopes()->where('tenant_id', $event->tenantId)->whereKey($event->paymentBatchId)->firstOrFail();
        $date = CarbonImmutable::parse($event->valueDate ?? $event->getOccurredAt())->toDateString();
        $iban = BankAccount::withoutGlobalScopes()->whereKey($batch->bank_account_id)->value('iban');
        $lines = PaymentLine::withoutGlobalScopes()->where('payment_batch_id', $batch->id)->whereIn('id', $event->executedLineIds)->get();

        foreach ($lines as $line) {
            $this->postLine($line, $batch, $date, $iban, $event);
        }
    }

    /**
     * A collection of an invoice becomes a payment of that invoice (see the Invoicing collection listener), and the
     * payment ledger posts it against the receivable of the invoice: posting it here too would collect it twice. Only a
     * collection without an invoice is posted here, once per receipt.
     */
    public function handleCollectionReceived(CollectionReceivedEvent $event): void
    {
        $settlesInvoice = $event->invoiceId !== null
            && Invoice::withoutGlobalScopes()->where('tenant_id', $event->tenantId)->whereKey($event->invoiceId)->exists();

        if ($settlesInvoice) {
            return;
        }

        $sourceId = Uuid::uuid5(Uuid::NAMESPACE_OID, $event->getEventId())->toString();
        $alreadyPosted = JournalEntry::withoutGlobalScopes()->where('tenant_id', $event->tenantId)
            ->where('source_document_type', 'treasury_collection')
            ->where('source_document_id', $sourceId)
            ->exists();

        if ($alreadyPosted) {
            return;
        }

        $date = CarbonImmutable::parse($event->collectionDate)->toDateString();
        $period = $this->periods->forDate($event->tenantId, $date);
        $amount = number_format($event->collectedAmount, 2, '.', '');

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->tenantId,
            'fiscal_period_id' => $period->id,
            'entry_number' => 'COLL-'.strtoupper(Str::random(10)),
            'source_document_type' => 'treasury_collection',
            'source_document_id' => $sourceId,
            'posting_date' => $date,
            'document_date' => $date,
            'journal_type' => 'standard',
            'description' => "Treasury collection {$event->collectionId}",
            'lines' => [
                ['account_id' => $this->accounts->bank($event->tenantId)->id, 'debit_amount' => $amount, 'credit_amount' => '0', 'description' => 'Collection received'],
                ['account_id' => $this->accounts->receivables($event->tenantId)->id, 'debit_amount' => '0', 'credit_amount' => $amount, 'partner_type' => 'customer', 'partner_id' => $event->customerId, 'description' => 'AR Settlement'],
            ],
        ]);
    }

    private function postLine(PaymentLine $line, PaymentBatch $batch, string $date, ?string $iban, PaymentExecutedEvent $event): void
    {
        $tenantId = $event->tenantId;
        $invoice = $line->invoice_id === null ? null : PassiveInvoice::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($line->invoice_id)->first();

        if ($invoice !== null) {
            $this->supplierPayments->execute($invoice, [
                'amount' => (string) $line->amount,
                'paid_at' => $date,
                'reference' => $event->bankTransmissionReference,
                'notes' => "Payment batch {$batch->batch_reference}",
                'ledger_options' => ['iban' => $iban, 'bank_balance_managed' => true],
            ]);

            return;
        }

        $alreadyPosted = JournalEntry::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->where('source_document_type', 'treasury_payment_line')
            ->where('source_document_id', $line->id)
            ->exists();

        if ($alreadyPosted) {
            return;
        }

        $period = $this->periods->forDate($tenantId, $date);
        $amount = (string) $line->amount;

        $this->postingService->createAndPost([
            'tenant_id' => $tenantId,
            'company_id' => $tenantId,
            'fiscal_period_id' => $period->id,
            'entry_number' => 'TRY-'.strtoupper(Str::random(10)),
            'source_document_type' => 'treasury_payment_line',
            'source_document_id' => (string) $line->id,
            'document_number' => $batch->batch_reference,
            'posting_date' => $date,
            'document_date' => $date,
            'journal_type' => 'standard',
            'description' => "Payment batch {$batch->batch_reference}: {$line->recipient_name}",
            'bank_balance_managed' => true,
            'lines' => [
                $this->debitLine($line, $tenantId, $amount),
                ['account_id' => $this->accounts->bank($tenantId, (string) $line->currency, $iban)->id, 'debit_amount' => '0', 'credit_amount' => $amount, 'currency' => $line->currency, 'description' => "Payment to {$line->recipient_name}"],
            ],
        ]);
    }

    /**
     * What a payment line extinguishes. A recipient the ledger cannot place is refused, so the confirmation fails
     * instead of leaving the bank out of step with the books.
     *
     * @return array<string, mixed>
     */
    private function debitLine(PaymentLine $line, string $tenantId, string $amount): array
    {
        $base = ['debit_amount' => $amount, 'credit_amount' => '0', 'currency' => $line->currency, 'description' => "Payment to {$line->recipient_name}"];

        if ($line->recipient_type === 'supplier' && $line->recipient_id !== null) {
            return $base + ['account_id' => $this->accounts->payables($tenantId)->id, 'partner_type' => 'supplier', 'partner_id' => $line->recipient_id];
        }

        if ($line->recipient_type === 'employee' || $line->payroll_payment_id !== null) {
            $accountId = PayrollLedgerSetting::withoutGlobalScopes()->where('tenant_id', $tenantId)->value('employee_payable_account_id');

            if ($accountId !== null && ChartOfAccount::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($accountId)->exists()) {
                return $base + ['account_id' => $accountId];
            }

            throw new InvalidArgumentException('Payroll payment: configure the employee payable account before confirming it.');
        }

        throw new InvalidArgumentException("Payment to {$line->recipient_name} cannot be posted: it has no supplier or employee to settle.");
    }
}
