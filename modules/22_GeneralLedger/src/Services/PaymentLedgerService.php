<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\Payment;
use App\Models\SupplierPayment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Enums\PostingAccountRole;
use Modules\GeneralLedger\Exceptions\FiscalPeriodException;
use Modules\GeneralLedger\Exceptions\LedgerPostingException;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\LedgerAmount;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\Invoicing\Contracts\CustomerLedgerServiceInterface;
use Modules\Treasury\Models\BankAccount;

/**
 * Posts the money movements of the sales and purchase documents, once per movement:
 *
 *   collection    Dr bank / Cr receivables      payment       Dr payables / Cr bank
 *   withheld fee  Dr bank charges / Cr receivables   fee      Dr bank charges / Cr bank
 *   adjustment    Dr rounding / Cr receivables  adjustment    Dr payables / Cr rounding
 *
 * A payment of a document in a foreign currency books the money side at the rate of the payment day (exchange-rate
 * service) and the receivable or payable at the frozen rate of the document, so the open item is closed at the value it
 * was opened with; the difference in the accounting currency is the realised exchange gain or loss, on its own line.
 *
 * A document that has not reached the ledger yet (for example an invoice older than this flow) has no item to settle:
 * its payments wait, and they are posted right after the document itself.
 */
final class PaymentLedgerService
{
    public const string SOURCE_CUSTOMER = 'customer_payment';

    public const string SOURCE_SUPPLIER = 'supplier_payment';

    /** Payment methods settled in cash, as free text and as the FatturaPA code. */
    private const array CASH_METHODS = ['cash', 'contanti', 'MP01'];

    public function __construct(
        private readonly DoubleEntryPostingServiceInterface $postingService,
        private readonly FiscalPeriodResolver $periods,
        private readonly LedgerAccountResolver $accounts,
        private readonly CustomerLedgerServiceInterface $customerLedger,
        private readonly PostingAccountResolver $postingAccounts = new PostingAccountResolver,
        private readonly AccountingExchangeRate $exchangeRates = new AccountingExchangeRate,
    ) {}

    /**
     * @param  array{iban?: ?string, bank_balance_managed?: bool}  $options
     */
    public function postCustomerPayment(Payment $payment, array $options = []): ?JournalEntry
    {
        $tenantId = (string) $payment->tenant_id;
        $existing = $this->entryOf(self::SOURCE_CUSTOMER, (string) $payment->id, $tenantId);

        if ($existing !== null) {
            return $existing;
        }

        $invoice = Invoice::withoutGlobalScopes()->whereKey($payment->invoice_id)->first();

        if ($invoice === null || $invoice->status !== 'posted' || $invoice->gl_journal_entry_id === null) {
            Log::info('Payment waits for its invoice to reach the ledger.', ['payment_id' => $payment->id, 'invoice_id' => $payment->invoice_id]);

            return null;
        }

        $options = $this->bankAccountOptions($payment, $options);
        $amount = (string) $payment->amount;
        $currency = (string) $invoice->currency;
        $debitAccount = match ($payment->type) {
            'bank_fee' => $this->accounts->bankCharges($tenantId),
            'adjustment' => $this->accounts->rounding($tenantId),
            default => in_array($payment->payment_method, self::CASH_METHODS, true)
                ? $this->accounts->cash($tenantId)
                : $this->accounts->bank($tenantId, $currency, $options['iban'] ?? null),
        };

        $documentRate = LedgerAmount::rate($invoice->exchange_rate);
        $paymentRate = $this->paymentRate($tenantId, $currency, $payment->paid_at, $documentRate);
        $lines = [
            $this->line($debitAccount, $amount, '0', $currency, $paymentRate, "Collection: Invoice {$invoice->invoice_number}"),
            $this->line($this->accounts->receivables($tenantId), '0', $amount, $currency, $documentRate, "AR Settlement: Invoice {$invoice->invoice_number}") + [
                'partner_type' => 'customer',
                'partner_id' => (string) $invoice->contact_id,
                'settles_entry_id' => (string) $invoice->gl_journal_entry_id,
            ],
        ];

        $entry = $this->post($tenantId, self::SOURCE_CUSTOMER, (string) $payment->id, $payment->paid_at, (string) $invoice->invoice_number, 'Collection', $this->withExchangeDifference($tenantId, $lines, "Exchange difference: Invoice {$invoice->invoice_number}"), $options);

        $this->customerLedger->recordPaymentEntry(
            customerId: (string) $invoice->contact_id,
            amount: (float) $amount,
            paymentReference: 'PAY-'.$payment->id,
            tenantId: $tenantId,
            invoiceId: (string) $invoice->id
        );

        return $entry;
    }

    /**
     * @param  array{iban?: ?string, bank_balance_managed?: bool}  $options
     */
    public function postSupplierPayment(SupplierPayment $payment, array $options = []): ?JournalEntry
    {
        $tenantId = (string) $payment->tenant_id;
        $existing = $this->entryOf(self::SOURCE_SUPPLIER, (string) $payment->id, $tenantId);

        if ($existing !== null) {
            return $existing;
        }

        $invoice = PassiveInvoice::withoutGlobalScopes()->whereKey($payment->passive_invoice_id)->first();

        if ($invoice === null || $invoice->gl_journal_entry_id === null) {
            Log::info('Payment waits for its supplier invoice to reach the ledger.', ['payment_id' => $payment->id, 'passive_invoice_id' => $payment->passive_invoice_id]);

            return null;
        }

        $amount = (string) $payment->amount;
        $currency = (string) $invoice->currency;
        $rate = LedgerAmount::rate($invoice->exchange_rate);
        $paymentRate = $this->paymentRate($tenantId, $currency, $payment->paid_at, $rate);
        $description = "Supplier Payment: Invoice {$invoice->invoice_number}";
        $bank = $this->accounts->bank($tenantId, $currency, $options['iban'] ?? null);
        $settlement = [
            'partner_type' => 'supplier',
            'partner_id' => (string) $invoice->supplier_id,
            'settles_entry_id' => (string) $invoice->gl_journal_entry_id,
        ];
        $payables = $this->line($this->accounts->payables($tenantId), $amount, '0', $currency, $rate, $description) + $settlement;

        $lines = match ($payment->type) {
            'bank_fee' => [
                $this->line($this->accounts->bankCharges($tenantId), $amount, '0', $currency, $paymentRate, "Bank charges: Invoice {$invoice->invoice_number}"),
                $this->line($bank, '0', $amount, $currency, $paymentRate, $description),
            ],
            'adjustment' => [$payables, $this->line($this->accounts->rounding($tenantId), '0', $amount, $currency, $paymentRate, $description)],
            default => [$payables, $this->line($bank, '0', $amount, $currency, $paymentRate, $description)],
        };

        return $this->post($tenantId, self::SOURCE_SUPPLIER, (string) $payment->id, $payment->paid_at, (string) $invoice->invoice_number, 'Supplier payment', $this->withExchangeDifference($tenantId, $lines, "Exchange difference: Invoice {$invoice->invoice_number}"), $options);
    }

    /**
     * Posts the payments that were waiting for their invoice.
     */
    public function postWaitingCustomerPayments(string $invoiceId): void
    {
        foreach (Payment::withoutGlobalScopes()->where('invoice_id', $invoiceId)->orderBy('paid_at')->get() as $payment) {
            $this->postCustomerPayment($payment);
        }
    }

    public function postWaitingSupplierPayments(string $passiveInvoiceId): void
    {
        foreach (SupplierPayment::withoutGlobalScopes()->where('passive_invoice_id', $passiveInvoiceId)->orderBy('paid_at')->get() as $payment) {
            $this->postSupplierPayment($payment);
        }
    }

    /**
     * Reverses the entry of a payment that is being undone, on its own date or, when that period is closed, today.
     *
     * @throws FiscalPeriodException when neither date falls in an open period
     */
    public function reverse(string $sourceType, string $sourceId, string $tenantId, CarbonImmutable $paidAt, bool $bankBalanceManaged = false): void
    {
        $entry = $this->entryOf($sourceType, $sourceId, $tenantId);

        if ($entry === null) {
            return;
        }

        try {
            $this->periods->forDate($tenantId, $paidAt);
            $date = $paidAt->toDateString();
        } catch (FiscalPeriodException) {
            $date = $this->periods->forDate($tenantId, now()) !== null ? now()->toDateString() : $paidAt->toDateString();
        }

        $this->postingService->reverseJournal((string) $entry->id, $date, 'Payment undone', null, $bankBalanceManaged);
    }

    /**
     * A bank charge that is not tied to a document, such as the fee of an unpaid Ri.Ba. receipt: Dr the expense account,
     * Cr the bank. It is posted once per source.
     *
     * @param  array{iban?: ?string, bank_balance_managed?: bool}  $options
     */
    public function postBankCharge(string $tenantId, string $sourceType, string $sourceId, string $date, string $amount, string $currency, ChartOfAccount $expenseAccount, string $documentNumber, array $options = []): JournalEntry
    {
        $existing = $this->entryOf($sourceType, $sourceId, $tenantId);

        if ($existing !== null) {
            return $existing;
        }

        $bank = $this->accounts->bank($tenantId, $currency, $options['iban'] ?? null);

        return $this->post($tenantId, $sourceType, $sourceId, $date, $documentNumber, 'Bank charge', [
            $this->line($expenseAccount, $amount, '0', $currency, null, "Bank charge: {$documentNumber}"),
            $this->line($bank, '0', $amount, $currency, null, "Bank charge: {$documentNumber}"),
        ], $options);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array{iban?: ?string, bank_balance_managed?: bool}  $options
     */
    private function post(string $tenantId, string $sourceType, string $sourceId, mixed $paidAt, string $documentNumber, string $label, array $lines, array $options): JournalEntry
    {
        if ($lines[0]['debit_amount'] === '0' || ! is_numeric($lines[0]['debit_amount'])) {
            throw new InvalidArgumentException("{$label} of document {$documentNumber} has no amount.");
        }

        $date = CarbonImmutable::parse($paidAt)->toDateString();
        $period = $this->periods->forDate($tenantId, $date);

        return $this->postingService->createAndPost([
            'tenant_id' => $tenantId,
            'company_id' => $tenantId,
            'fiscal_period_id' => $period->id,
            'entry_number' => 'PAY-'.strtoupper(Str::random(10)),
            'source_document_type' => $sourceType,
            'source_document_id' => $sourceId,
            'document_number' => $documentNumber,
            'posting_date' => $date,
            'document_date' => $date,
            'journal_type' => 'standard',
            'description' => "{$label}: {$documentNumber}",
            'bank_balance_managed' => (bool) ($options['bank_balance_managed'] ?? false),
            'lines' => $lines,
        ]);
    }

    /**
     * A line of a money movement. A null rate leaves the conversion to the posting service (accounting currency, or the
     * rate of the exchange-rate service on the posting date).
     *
     * @return array<string, mixed>
     */
    private function line(ChartOfAccount $account, string $debit, string $credit, string $currency, ?string $exchangeRate, string $description): array
    {
        return [
            'account_id' => $account->id,
            'debit_amount' => $debit,
            'credit_amount' => $credit,
            'currency' => $currency,
            'exchange_rate' => $exchangeRate,
            'description' => $description,
        ];
    }

    /**
     * The rate of the money side of a payment: accounting-currency units per document-currency unit on the day it was
     * paid, from the exchange-rate service. The document keeps its frozen rate, so the two sides differ by the realised
     * exchange difference. A document in the accounting currency, or a day without a published rate, uses the rate of
     * the document: the payment then carries no exchange difference.
     */
    private function paymentRate(string $tenantId, string $currency, mixed $paidAt, string $documentRate): string
    {
        if (strtoupper($currency) === TenantAccountingCurrency::of($tenantId)) {
            return $documentRate;
        }

        return $this->exchangeRates->rateOn($tenantId, $currency, CarbonImmutable::parse($paidAt)) ?? $documentRate;
    }

    /**
     * Adds the realised exchange difference of a payment: the lines are converted as the posting converts them (amount
     * × rate at 4 decimals) and what keeps them from balancing in the accounting currency goes, in the accounting
     * currency, to the realised exchange gain or loss account (the unrealised one when the tenant has not set a
     * realised one). A payment without a difference gets no line.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     *
     * @throws LedgerPostingException when there is a difference and no exchange gain/loss account is set
     */
    private function withExchangeDifference(string $tenantId, array $lines, string $description): array
    {
        $accounting = TenantAccountingCurrency::of($tenantId);
        $difference = '0';

        foreach ($lines as $line) {
            $rate = strtoupper((string) $line['currency']) === $accounting ? '1' : (string) ($line['exchange_rate'] ?? '1');
            $difference = Decimal::add($difference, LedgerAmount::of(Decimal::mul((string) $line['debit_amount'], $rate)));
            $difference = Decimal::sub($difference, LedgerAmount::of(Decimal::mul((string) $line['credit_amount'], $rate)));
        }

        if (Decimal::isZero($difference)) {
            return $lines;
        }

        $isGain = Decimal::isPositive($difference);
        $account = $isGain
            ? ($this->postingAccounts->account($tenantId, PostingAccountRole::FxRealisedGain) ?? $this->postingAccounts->require($tenantId, PostingAccountRole::FxUnrealisedGain))
            : ($this->postingAccounts->account($tenantId, PostingAccountRole::FxRealisedLoss) ?? $this->postingAccounts->require($tenantId, PostingAccountRole::FxUnrealisedLoss));
        $amount = LedgerAmount::of(Decimal::abs($difference));

        $lines[] = $this->line($account, $isGain ? '0' : $amount, $isGain ? $amount : '0', $accounting, '1.000000', $description);

        return $lines;
    }

    /**
     * A collection that names the Treasury bank account it reached posts to the ledger account of that bank, and its
     * balance was already moved by the flow that recorded it: the posting does not announce it again, also when it is
     * posted later because it waited for its invoice.
     *
     * @param  array{iban?: ?string, bank_balance_managed?: bool}  $options
     * @return array{iban?: ?string, bank_balance_managed?: bool}
     */
    private function bankAccountOptions(Payment $payment, array $options): array
    {
        if ($payment->bank_account_id === null) {
            return $options;
        }

        $iban = BankAccount::withoutGlobalScopes()->where('tenant_id', $payment->tenant_id)->whereKey($payment->bank_account_id)->value('iban');

        return ['iban' => $options['iban'] ?? $iban, 'bank_balance_managed' => true];
    }

    private function entryOf(string $sourceType, string $sourceId, string $tenantId): ?JournalEntry
    {
        return JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('source_document_type', $sourceType)
            ->where('source_document_id', $sourceId)
            ->where('status', 'posted')
            ->where('journal_type', '!=', 'reversing')
            ->first();
    }
}
