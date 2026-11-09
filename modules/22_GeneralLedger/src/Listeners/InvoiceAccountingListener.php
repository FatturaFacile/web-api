<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Listeners;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;
use Modules\GeneralLedger\Services\LedgerAccountResolver;
use Modules\GeneralLedger\Services\PaymentLedgerService;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\LedgerAmount;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\GeneralLedger\Services\Tax\VatLedgerReader;
use Modules\Invoicing\Events\InvoicePostedEvent;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\SDI\Enums\SelfInvoiceLedgerTreatment;
use Modules\SDI\Enums\TipoCassa;
use Modules\SDI\Enums\TipoDocumento;

final class InvoiceAccountingListener
{
    /** Half of the smallest Swiss coin is the widest cash rounding; a sum of rounded lines stays below 0.05 too. */
    private const string MAX_ROUNDING = '0.05';

    public function __construct(
        private readonly DoubleEntryPostingServiceInterface $postingService,
        private readonly FiscalPeriodResolver $periods,
        private readonly PaymentLedgerService $payments,
        private readonly LedgerAccountResolver $accounts,
        private readonly ?AuditLedgerInterface $auditLedger = null
    ) {}

    public function handle(InvoicePostedEvent $event): void
    {
        // 1. Idempotency Check: Prevent duplicate GL postings
        $existingEntry = JournalEntry::where('tenant_id', $event->tenantId)
            ->where('source_document_type', 'sales_invoice')
            ->where('source_document_id', $event->invoiceId)
            ->first();

        if ($existingEntry !== null) {
            return;
        }

        // 2. The period containing the date of the document: failing here stops the emission
        $documentDate = CarbonImmutable::parse($event->date)->toDateString();
        $period = $this->periods->forDate($event->tenantId, $documentDate);

        $invoice = SalesInvoice::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $event->tenantId)
            ->whereKey($event->invoiceId)
            ->first();

        if ($invoice !== null && TipoDocumento::ofInvoice($invoice)->isSelfInvoice()) {
            $this->postSelfInvoice($event, $invoice, (string) $period->id, $documentDate);

            return;
        }

        // 3. Resolve the posting accounts: the tenant's configured defaults, else the historical codes
        $arAccount = $this->accounts->receivables($event->tenantId);
        $defaultRevenueAccount = $this->accounts->salesRevenue($event->tenantId);
        $defaultVatAccount = $this->accounts->outputVat($event->tenantId);

        $vatTreatment = $this->vatTreatmentOf($event);

        if ($vatTreatment === VatLedgerReader::TREATMENT_SPLIT_PAYMENT) {
            $defaultVatAccount = $this->resolveAccount(
                tenantId: $event->tenantId,
                code: '2205',
                name: 'IVA split payment (Split Payment VAT)',
                type: 'liability',
                normalBalance: 'credit'
            );
        }

        $revenueByLine = [];
        foreach ($event->revenueLines as $revenueLine) {
            if (! empty($revenueLine['line_id'])) {
                $revenueByLine[(string) $revenueLine['line_id']] = LedgerAmount::of($revenueLine['amount']);
            }
        }

        $currency = ! empty($event->currency) ? $event->currency : TenantAccountingCurrency::of($event->tenantId);
        $exchangeRate = LedgerAmount::rate($event->exchangeRate);
        $totalGross = LedgerAmount::of($event->totalGross);
        $totalTax = LedgerAmount::of($event->totalTax);
        $totalNet = LedgerAmount::of($event->totalNet);

        // 4. Construct Balanced Journal Lines
        // Line 1: Debit Accounts Receivable: what the customer pays, not what it is billed
        $lines = [
            [
                'account_id' => $arAccount->id,
                'debit_amount' => $invoice !== null ? LedgerAmount::of($invoice->amountDue()) : $totalGross,
                'credit_amount' => '0',
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'partner_type' => 'customer',
                'partner_id' => $event->customerId,
                'due_date' => $event->dueDate !== null && $event->dueDate !== '' ? CarbonImmutable::parse($event->dueDate)->toDateString() : null,
                'description' => "AR Receivable: Invoice {$event->invoiceNumber}",
            ],
        ];

        // Lines 2+: Credit Revenue Lines
        if (! empty($event->revenueLines)) {
            foreach ($event->revenueLines as $revLine) {
                $revAccountId = ! empty($revLine['account_id']) ? $revLine['account_id'] : $defaultRevenueAccount->id;
                $amount = LedgerAmount::of($revLine['amount']);

                if (Decimal::isZero($amount)) {
                    continue;
                }

                $lines[] = [
                    'account_id' => $revAccountId,
                    'debit_amount' => Decimal::isNegative($amount) ? Decimal::abs($amount) : '0',
                    'credit_amount' => Decimal::isPositive($amount) ? $amount : '0',
                    'currency' => $currency,
                    'exchange_rate' => $exchangeRate,
                    'cost_center_id' => ! empty($revLine['cost_center_id']) ? (string) $revLine['cost_center_id'] : null,
                    'profit_center_id' => $revLine['profit_center_id'] ?? null,
                    'description' => $revLine['description'] ?? "Sales Revenue: Invoice {$event->invoiceNumber}",
                ];
            }
        } else {
            $lines[] = [
                'account_id' => $defaultRevenueAccount->id,
                'debit_amount' => '0',
                'credit_amount' => $totalNet,
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'description' => "Sales Revenue: Invoice {$event->invoiceNumber}",
            ];
        }

        // Lines 3+: Credit Output VAT Lines
        if (! empty($event->taxLines)) {
            foreach ($event->taxLines as $taxLine) {
                $vatAccountId = ! empty($taxLine['account_id']) && $vatTreatment !== VatLedgerReader::TREATMENT_SPLIT_PAYMENT
                    ? $taxLine['account_id']
                    : $defaultVatAccount->id;
                $amount = LedgerAmount::of($taxLine['amount']);

                if (Decimal::isZero($amount)) {
                    continue;
                }

                $lines[] = [
                    'account_id' => $vatAccountId,
                    'debit_amount' => Decimal::isNegative($amount) ? Decimal::abs($amount) : '0',
                    'credit_amount' => Decimal::isPositive($amount) ? $amount : '0',
                    'currency' => $currency,
                    'exchange_rate' => $exchangeRate,
                    'vat_tax_amount' => $amount,
                    'vat_base_amount' => isset($taxLine['base_amount']) ? LedgerAmount::of($taxLine['base_amount']) : ($revenueByLine[(string) ($taxLine['line_id'] ?? '')] ?? '0'),
                    'vat_rate_percent' => isset($taxLine['tax_rate']) ? LedgerAmount::of($taxLine['tax_rate']) : null,
                    'vat_treatment' => $vatTreatment,
                    'description' => "Output VAT: Invoice {$event->invoiceNumber}",
                ];
            }
        } elseif (Decimal::isPositive($totalTax)) {
            $lines[] = [
                'account_id' => $defaultVatAccount->id,
                'debit_amount' => '0',
                'credit_amount' => $totalTax,
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'vat_tax_amount' => $totalTax,
                'vat_base_amount' => $totalNet,
                'vat_treatment' => $vatTreatment,
                'description' => "Output VAT: Invoice {$event->invoiceNumber}",
            ];
        }

        if ($invoice !== null) {
            $lines = [...$lines, ...$this->fiscalLines($event, $invoice, (string) $defaultVatAccount->id, $currency, $exchangeRate)];
        }

        $roundingLine = $this->roundingLine($event, $lines, $currency, $exchangeRate);

        if ($roundingLine !== null) {
            $lines[] = $roundingLine;
        }

        // 5. Post Double-Entry Journal Entry
        $entryNumber = 'AR-INV-'.strtoupper(Str::random(6));
        $journalEntry = $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'entry_number' => $entryNumber,
            'source_document_type' => 'sales_invoice',
            'source_document_id' => $event->invoiceId,
            'document_number' => $event->invoiceNumber,
            'posting_date' => $documentDate,
            'document_date' => $documentDate,
            'journal_type' => 'standard',
            'description' => "Sales Invoicing GL Posting: {$event->invoiceNumber}",
            'lines' => $lines,
        ]);

        // 6. Link GL Journal Entry back to Sales Invoice
        SalesInvoice::withoutGlobalScope(TenantScope::class)->where('id', $event->invoiceId)->update([
            'gl_journal_entry_id' => (string) $journalEntry->id,
        ]);

        // Payments recorded before the invoice reached the ledger can now settle its item
        $this->payments->postWaitingCustomerPayments($event->invoiceId);

        // 7. Explicit Accounting Audit Entry
        $this->auditLedger?->record(
            action: 'SALES_INVOICE_GL_POSTED',
            model: $journalEntry,
            payloadBefore: null,
            payloadAfter: [
                'invoice_id' => $event->invoiceId,
                'invoice_number' => $event->invoiceNumber,
                'customer_id' => $event->customerId,
                'journal_entry_id' => (string) $journalEntry->id,
                'total_gross' => $totalGross,
                'currency' => $currency,
            ],
            moduleCode: 'MODULE_22_GENERAL_LEDGER'
        );
    }

    /**
     * The lines between the document total and what the customer pays. The VAT of a split-payment invoice stays on its
     * account for the register, and leaves it in the same entry: the public administration pays it, so it is neither
     * collected nor owed. Each line is skipped when its amount is zero.
     *
     * @return list<array<string, mixed>>
     */
    private function fiscalLines(InvoicePostedEvent $event, SalesInvoice $invoice, string $vatAccountId, string $currency, string $exchangeRate): array
    {
        $tenantId = $event->tenantId;
        $number = $event->invoiceNumber;
        $line = static fn (string $accountId, string $debit, string $credit, string $description): array => [
            'account_id' => $accountId,
            'debit_amount' => $debit,
            'credit_amount' => $credit,
            'currency' => $currency,
            'exchange_rate' => $exchangeRate,
            'description' => "{$description}: Invoice {$number}",
        ];
        $lines = [];

        if ($invoice->withholding_tax_enabled && Decimal::isPositive((string) $invoice->withholding_tax_amount)) {
            $lines[] = $line((string) $this->accounts->withholdingReceivable($tenantId)->id, (string) $invoice->withholding_tax_amount, '0', 'Withholding suffered');
        }

        if ($invoice->enasarco_enabled && Decimal::isPositive((string) $invoice->enasarco_amount)) {
            $lines[] = $line((string) $this->accounts->enasarcoExpense($tenantId)->id, (string) $invoice->enasarco_amount, '0', 'Enasarco withheld');
        }

        if ($invoice->split_payment && Decimal::isPositive((string) $invoice->total_tax)) {
            $lines[] = $line($vatAccountId, (string) $invoice->total_tax, '0', 'Split payment VAT paid by the customer to the tax authority');
        }

        if ($invoice->fund_enabled && Decimal::isPositive((string) $invoice->fund_amount)) {
            $account = TipoCassa::tryFrom((string) $invoice->fund_type)?->isRivalsaInps() ? $this->accounts->pensionFundRecharge($tenantId) : $this->accounts->pensionFundPayable($tenantId);
            $lines[] = $line((string) $account->id, '0', (string) $invoice->fund_amount, 'Pension fund contribution');
        }

        if ($invoice->stamp_duty_applied && Decimal::isPositive((string) $invoice->stamp_duty_amount)) {
            $amount = (string) $invoice->stamp_duty_amount;
            $lines[] = $line((string) $this->accounts->stampDutyPayable($tenantId)->id, '0', $amount, 'Stamp duty');

            if (! $invoice->stamp_duty_charged) {
                $lines[] = $line((string) $this->accounts->stampDutyExpense($tenantId)->id, $amount, '0', 'Stamp duty not charged to the customer');
            }
        }

        return $lines;
    }

    /**
     * A self-invoice opens no receivable and earns nothing: it only moves VAT, as its type says. A document without an
     * entry is left without one, which is also what makes it pass this listener again unchanged.
     */
    private function postSelfInvoice(InvoicePostedEvent $event, SalesInvoice $invoice, string $periodId, string $documentDate): void
    {
        $treatment = TipoDocumento::ofInvoice($invoice)->ledgerTreatment();

        if ($treatment === SelfInvoiceLedgerTreatment::NoEntry) {
            return;
        }

        $tenantId = $event->tenantId;
        $revenueByLine = array_column($event->revenueLines, 'amount', 'line_id');
        $debitAccount = $treatment === SelfInvoiceLedgerTreatment::Integration ? $this->accounts->inputVat($tenantId) : $this->accounts->selfInvoiceVatCost($tenantId);
        $creditAccount = $this->accounts->outputVat($tenantId);
        $lines = [];

        foreach ($event->taxLines as $taxLine) {
            $amount = LedgerAmount::of($taxLine['amount']);

            if (Decimal::isZero($amount)) {
                continue;
            }

            $baseAmount = $revenueByLine[(string) ($taxLine['line_id'] ?? '')] ?? null;
            $vat = [
                'currency' => $event->currency,
                'exchange_rate' => LedgerAmount::rate($event->exchangeRate),
                'vat_tax_amount' => $amount,
                'vat_base_amount' => $baseAmount !== null ? LedgerAmount::of($baseAmount) : '0',
                'vat_rate_percent' => isset($taxLine['tax_rate']) ? LedgerAmount::of($taxLine['tax_rate']) : null,
            ];

            $lines[] = $vat + ['account_id' => $debitAccount->id, 'debit_amount' => $amount, 'credit_amount' => '0', 'description' => "VAT of self-invoice {$event->invoiceNumber}"];
            $lines[] = $vat + ['account_id' => $creditAccount->id, 'debit_amount' => '0', 'credit_amount' => $amount, 'description' => "VAT of self-invoice {$event->invoiceNumber}"];
        }

        if ($lines === []) {
            return;
        }

        $journalEntry = $this->postingService->createAndPost([
            'tenant_id' => $tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $periodId,
            'entry_number' => 'AF-INV-'.strtoupper(Str::random(6)),
            'source_document_type' => 'sales_invoice',
            'source_document_id' => $event->invoiceId,
            'document_number' => $event->invoiceNumber,
            'posting_date' => $documentDate,
            'document_date' => $documentDate,
            'journal_type' => 'standard',
            'description' => "Self-invoice GL Posting: {$event->invoiceNumber}",
            'lines' => $lines,
        ]);

        SalesInvoice::withoutGlobalScope(TenantScope::class)->where('id', $event->invoiceId)->update([
            'gl_journal_entry_id' => (string) $journalEntry->id,
        ]);
    }

    /**
     * An Italian invoice under split payment or reverse charge carries VAT the seller does not settle. Split payment
     * tax goes to its own account, so it does not end up on the output VAT account the settlement clears.
     */
    private function vatTreatmentOf(InvoicePostedEvent $event): ?string
    {
        if (strtoupper((string) Tenant::query()->whereKey($event->tenantId)->value('country')) !== 'IT') {
            return null;
        }

        $invoice = SalesInvoice::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $event->tenantId)
            ->whereKey($event->invoiceId)
            ->first(['id', 'split_payment', 'reverse_charge']);

        if ($invoice?->split_payment) {
            return VatLedgerReader::TREATMENT_SPLIT_PAYMENT;
        }

        return $invoice?->reverse_charge ? VatLedgerReader::TREATMENT_REVERSE_CHARGE : null;
    }

    /**
     * The gross of a document can differ from the sum of its lines only by the cash rounding of its currency, which
     * goes to its own account. A bigger difference stays in the entry, so the posting refuses it as unbalanced.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, mixed>|null
     */
    private function roundingLine(InvoicePostedEvent $event, array $lines, string $currency, string $exchangeRate): ?array
    {
        $difference = '0';

        foreach ($lines as $line) {
            $difference = Decimal::add($difference, LedgerAmount::of($line['debit_amount']));
            $difference = Decimal::sub($difference, LedgerAmount::of($line['credit_amount']));
        }

        if (Decimal::isZero($difference) || Decimal::compare(Decimal::abs($difference), self::MAX_ROUNDING) > 0) {
            return null;
        }

        $account = $this->accounts->rounding($event->tenantId);
        $isGain = Decimal::isPositive($difference);

        return [
            'account_id' => $account->id,
            'debit_amount' => $isGain ? '0' : LedgerAmount::of(Decimal::abs($difference)),
            'credit_amount' => $isGain ? LedgerAmount::of($difference) : '0',
            'currency' => $currency,
            'exchange_rate' => $exchangeRate,
            'description' => "Rounding: Invoice {$event->invoiceNumber}",
        ];
    }

    private function resolveAccount(string $tenantId, string $code, string $name, string $type, string $normalBalance): ChartOfAccount
    {
        return ChartOfAccount::firstOrCreate(
            ['tenant_id' => $tenantId, 'account_code' => $code],
            [
                'id' => (string) Str::uuid7(),
                'name' => $name,
                'account_class' => $type,
                'account_type' => $type,
                'normal_balance' => $normalBalance,
                'currency' => TenantAccountingCurrency::of($tenantId),
                'is_active' => true,
            ]
        );
    }
}
