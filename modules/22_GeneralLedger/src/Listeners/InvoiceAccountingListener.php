<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Listeners;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\Invoicing\Events\InvoicePostedEvent;
use Modules\Invoicing\Models\SalesInvoice;

final class InvoiceAccountingListener
{
    public function __construct(
        private readonly DoubleEntryPostingServiceInterface $postingService,
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

        // 2. Resolve Active Fiscal Period
        $period = $this->resolveFiscalPeriod($event->tenantId);
        if ($period === null) {
            return;
        }

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

        $currency = ! empty($event->currency) ? $event->currency : 'EUR';
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
                'description' => "AR Receivable: Invoice {$event->invoiceNumber}",
            ],
        ];

        // Lines 2+: Credit Revenue Lines
        if (! empty($event->revenueLines)) {
            foreach ($event->revenueLines as $revLine) {
                $revAccountId = ! empty($revLine['account_id']) ? $revLine['account_id'] : $defaultRevenueAccount->id;
                $lines[] = [
                    'account_id' => $revAccountId,
                    'debit_amount' => 0.0,
                    'credit_amount' => (float) $revLine['amount'],
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
                $vatAccountId = ! empty($taxLine['account_id']) ? $taxLine['account_id'] : $defaultVatAccount->id;
                $lines[] = [
                    'account_id' => $vatAccountId,
                    'debit_amount' => 0.0,
                    'credit_amount' => (float) $taxLine['amount'],
                    'currency' => $currency,
                    'exchange_rate' => $exchangeRate,
                    'vat_tax_amount' => (float) $taxLine['amount'],
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
                'description' => "Output VAT: Invoice {$event->invoiceNumber}",
            ];
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
            'posting_date' => $event->date,
            'document_date' => $event->date,
            'journal_type' => 'standard',
            'description' => "Sales Invoicing GL Posting: {$event->invoiceNumber}",
            'lines' => $lines,
        ]);

        // 6. Link GL Journal Entry back to Sales Invoice
        SalesInvoice::where('id', $event->invoiceId)->update([
            'gl_journal_entry_id' => (string) $journalEntry->id,
        ]);

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

    private function resolveFiscalPeriod(string $tenantId): ?FiscalPeriod
    {
        return FiscalPeriod::where('tenant_id', $tenantId)
            ->where('status', 'open')
            ->first();
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
                'currency' => 'EUR',
                'is_active' => true,
            ]
        );
    }
}
