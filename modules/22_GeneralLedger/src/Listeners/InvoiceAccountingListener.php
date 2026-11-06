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
use Modules\GeneralLedger\Services\PaymentLedgerService;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\GeneralLedger\Services\Tax\VatLedgerReader;
use Modules\Invoicing\Events\InvoicePostedEvent;
use Modules\Invoicing\Models\SalesInvoice;

final class InvoiceAccountingListener
{
    /** Half of the smallest Swiss coin is the widest cash rounding; a sum of rounded lines stays below 0.05 too. */
    private const string MAX_ROUNDING = '0.05';

    public function __construct(
        private readonly DoubleEntryPostingServiceInterface $postingService,
        private readonly FiscalPeriodResolver $periods,
        private readonly PaymentLedgerService $payments,
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

        // 3. Resolve Master General Ledger Accounts
        $arAccount = $this->resolveAccount(
            tenantId: $event->tenantId,
            code: '1100',
            name: 'Accounts Receivable (Crediti v/Clienti)',
            type: 'asset',
            normalBalance: 'debit'
        );
        $arAccount->update(['is_reconciliation_account' => true, 'reconciliation_type' => 'customer']);

        $defaultRevenueAccount = $this->resolveAccount(
            tenantId: $event->tenantId,
            code: '4000',
            name: 'Sales Revenue (Ricavi delle Vendite)',
            type: 'revenue',
            normalBalance: 'credit'
        );

        $defaultVatAccount = $this->resolveAccount(
            tenantId: $event->tenantId,
            code: '2200',
            name: 'IVA c/Vendite (Sales Output VAT)',
            type: 'liability',
            normalBalance: 'credit'
        );

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
                $revenueByLine[(string) $revenueLine['line_id']] = (float) $revenueLine['amount'];
            }
        }

        $currency = ! empty($event->currency) ? $event->currency : TenantAccountingCurrency::of($event->tenantId);
        $exchangeRate = (float) ($event->exchangeRate > 0 ? $event->exchangeRate : 1.0);
        $totalGross = (float) $event->totalGross;
        $totalTax = (float) $event->totalTax;
        $totalNet = (float) $event->totalNet;

        // 4. Construct Balanced Journal Lines
        // Line 1: Debit Accounts Receivable (Total Gross)
        $lines = [
            [
                'account_id' => $arAccount->id,
                'debit_amount' => $totalGross,
                'credit_amount' => 0.0,
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
                $amount = (float) $revLine['amount'];

                if ($amount === 0.0) {
                    continue;
                }

                $lines[] = [
                    'account_id' => $revAccountId,
                    'debit_amount' => $amount < 0 ? abs($amount) : 0.0,
                    'credit_amount' => $amount > 0 ? $amount : 0.0,
                    'currency' => $currency,
                    'exchange_rate' => $exchangeRate,
                    'cost_center_id' => $revLine['cost_center_id'] ?? null,
                    'profit_center_id' => $revLine['profit_center_id'] ?? null,
                    'description' => $revLine['description'] ?? "Sales Revenue: Invoice {$event->invoiceNumber}",
                ];
            }
        } else {
            $lines[] = [
                'account_id' => $defaultRevenueAccount->id,
                'debit_amount' => 0.0,
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
                $amount = (float) $taxLine['amount'];

                if ($amount === 0.0) {
                    continue;
                }

                $lines[] = [
                    'account_id' => $vatAccountId,
                    'debit_amount' => $amount < 0 ? abs($amount) : 0.0,
                    'credit_amount' => $amount > 0 ? $amount : 0.0,
                    'currency' => $currency,
                    'exchange_rate' => $exchangeRate,
                    'vat_tax_amount' => (float) $taxLine['amount'],
                    'vat_base_amount' => $revenueByLine[(string) ($taxLine['line_id'] ?? '')] ?? 0.0,
                    'vat_rate_percent' => $taxLine['tax_rate'] ?? null,
                    'vat_treatment' => $vatTreatment,
                    'description' => "Output VAT: Invoice {$event->invoiceNumber}",
                ];
            }
        } elseif ($totalTax > 0) {
            $lines[] = [
                'account_id' => $defaultVatAccount->id,
                'debit_amount' => 0.0,
                'credit_amount' => $totalTax,
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'vat_tax_amount' => $totalTax,
                'vat_base_amount' => $totalNet,
                'vat_treatment' => $vatTreatment,
                'description' => "Output VAT: Invoice {$event->invoiceNumber}",
            ];
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
    private function roundingLine(InvoicePostedEvent $event, array $lines, string $currency, float $exchangeRate): ?array
    {
        $difference = '0';

        foreach ($lines as $line) {
            $difference = Decimal::add($difference, number_format((float) $line['debit_amount'], 4, '.', ''));
            $difference = Decimal::sub($difference, number_format((float) $line['credit_amount'], 4, '.', ''));
        }

        if (Decimal::isZero($difference) || Decimal::compare(Decimal::abs($difference), self::MAX_ROUNDING) > 0) {
            return null;
        }

        $account = $this->resolveAccount($event->tenantId, '6960', 'Rounding differences (Arrotondamenti)', 'expense', 'debit');
        $isGain = Decimal::isPositive($difference);

        return [
            'account_id' => $account->id,
            'debit_amount' => $isGain ? 0.0 : (float) Decimal::abs($difference),
            'credit_amount' => $isGain ? (float) $difference : 0.0,
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
