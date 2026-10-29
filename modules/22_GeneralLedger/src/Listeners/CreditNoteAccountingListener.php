<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Listeners;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\Invoicing\Events\CreditNoteIssuedEvent;
use Modules\Invoicing\Models\SalesInvoice;

final class CreditNoteAccountingListener
{
    public function __construct(
        private readonly DoubleEntryPostingServiceInterface $postingService,
        private readonly ?AuditLedgerInterface $auditLedger = null
    ) {}

    public function handle(CreditNoteIssuedEvent $event): void
    {
        // 1. Idempotency Check: Prevent duplicate GL postings
        $existingEntry = JournalEntry::where('tenant_id', $event->tenantId)
            ->where('source_document_type', 'credit_note')
            ->where('source_document_id', $event->creditNoteId)
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
        $revenueReversalAcc = $this->resolveAccount(
            tenantId: $event->tenantId,
            code: '4090',
            name: 'Sales Returns & Allowances (Resi su Vendite)',
            type: 'revenue',
            normalBalance: 'debit'
        );

        $vatReversalAcc = $this->resolveAccount(
            tenantId: $event->tenantId,
            code: '2200',
            name: 'IVA c/Vendite (Sales Output VAT)',
            type: 'liability',
            normalBalance: 'credit'
        );

        $arAccount = $this->resolveAccount(
            tenantId: $event->tenantId,
            code: '1100',
            name: 'Accounts Receivable (Crediti v/Clienti)',
            type: 'asset',
            normalBalance: 'debit'
        );
        $arAccount->update(['is_reconciliation_account' => true, 'reconciliation_type' => 'customer']);

        $creditedGross = (float) $event->creditedAmount;

        // 4. Calculate Net and VAT components from adjustment lines or defaults
        $netCredited = 0.0;
        $vatCredited = 0.0;

        if (! empty($event->adjustmentLines)) {
            foreach ($event->adjustmentLines as $line) {
                $qty = (float) ($line['quantity'] ?? 1.0);
                $unitPrice = (float) ($line['unit_price'] ?? 0.0);
                $taxRate = (float) ($line['tax_rate'] ?? 22.0);
                $lineNet = round($qty * $unitPrice, 2);
                $lineTax = round(($lineNet * $taxRate) / 100.0, 2);

                $netCredited += $lineNet;
                $vatCredited += $lineTax;
            }
        }

        if ($netCredited <= 0.0) {
            // Default calculation assuming standard 22% VAT rate
            $netCredited = round($creditedGross / 1.22, 2);
            $vatCredited = round($creditedGross - $netCredited, 2);
        } else {
            // Normalize rounding discrepancy
            $diff = round($creditedGross - ($netCredited + $vatCredited), 2);
            if (abs($diff) > 0.0) {
                $vatCredited += $diff;
            }
        }

        // 5. Construct Balanced Reversal Journal Lines
        // Line 1: Debit Revenue Reversal
        $lines = [
            [
                'account_id' => $revenueReversalAcc->id,
                'debit_amount' => $netCredited,
                'credit_amount' => 0.0,
                'currency' => 'EUR',
                'exchange_rate' => 1.0,
                'description' => "Revenue Reversal: Credit Note {$event->creditNoteNumber}",
            ],
        ];

        // Line 2: Debit Output VAT Reversal (if tax > 0)
        if ($vatCredited > 0) {
            $lines[] = [
                'account_id' => $vatReversalAcc->id,
                'debit_amount' => $vatCredited,
                'credit_amount' => 0.0,
                'currency' => 'EUR',
                'exchange_rate' => 1.0,
                'vat_tax_amount' => $vatCredited,
                'description' => "VAT Reversal: Credit Note {$event->creditNoteNumber}",
            ];
        }

        // Line 3: Credit Accounts Receivable (Gross Amount)
        $lines[] = [
            'account_id' => $arAccount->id,
            'debit_amount' => 0.0,
            'credit_amount' => $creditedGross,
            'currency' => 'EUR',
            'exchange_rate' => 1.0,
            'partner_type' => 'customer',
            'partner_id' => $event->customerId,
            'description' => "AR Credit Offset: Credit Note {$event->creditNoteNumber}",
        ];

        // 6. Post Double-Entry Journal Entry
        $entryNumber = 'CN-GL-'.strtoupper(Str::random(6));
        $journalEntry = $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'entry_number' => $entryNumber,
            'source_document_type' => 'credit_note',
            'source_document_id' => $event->creditNoteId,
            'posting_date' => now()->toDateString(),
            'document_date' => now()->toDateString(),
            'journal_type' => 'standard',
            'description' => "Credit Note GL Posting: {$event->creditNoteNumber}",
            'lines' => $lines,
        ]);

        // 7. Link GL Journal Entry back to Credit Note
        SalesInvoice::where('id', $event->creditNoteId)->update([
            'gl_journal_entry_id' => (string) $journalEntry->id,
        ]);

        // 8. Explicit Accounting Audit Entry
        $this->auditLedger?->record(
            action: 'SALES_CREDIT_NOTE_GL_POSTED',
            model: $journalEntry,
            payloadBefore: null,
            payloadAfter: [
                'credit_note_id' => $event->creditNoteId,
                'credit_note_number' => $event->creditNoteNumber,
                'original_invoice_id' => $event->originalInvoiceId,
                'customer_id' => $event->customerId,
                'journal_entry_id' => (string) $journalEntry->id,
                'credited_gross' => $creditedGross,
                'net_reversed' => $netCredited,
                'vat_reversed' => $vatCredited,
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
