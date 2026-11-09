<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Listeners;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Exceptions\LedgerPostingException;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;
use Modules\GeneralLedger\Services\LedgerAccountResolver;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\LedgerAmount;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\Procurement\Events\GoodsReceivedEvent;
use Modules\Procurement\Events\PurchaseLiabilityReadyEvent;
use Modules\Procurement\Events\PurchaseOrderCancelledEvent;
use Modules\Procurement\Events\PurchaseOrderCommittedEvent;

final class ProcurementAccountingListener
{
    /** An order is a commitment, not an accounting fact: it stays out of the statutory books. */
    private const string COMMITMENT_LEDGER = 'management';

    public function __construct(
        private readonly DoubleEntryPostingServiceInterface $postingService,
        private readonly FiscalPeriodResolver $periods,
        private readonly LedgerAccountResolver $accounts = new LedgerAccountResolver
    ) {}

    public function handlePurchaseOrderCommitted(PurchaseOrderCommittedEvent $event): void
    {
        $postingDate = $this->periods->dateOfEvent($event);
        $period = $this->periods->forDate($event->tenantId, $postingDate);

        $encumbranceExpAcc = $this->resolveAccount($event->tenantId, '6990', 'Encumbrance Expense', 'expense', 'debit');
        $encumbranceResAcc = $this->resolveAccount($event->tenantId, '3990', 'Reserve for Encumbrances', 'equity', 'credit');

        $poNumber = $event->purchaseOrderNumber ?? $event->poNumber;

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'posting_date' => $postingDate,
            'document_date' => $postingDate,
            'entry_number' => 'ENC-'.$poNumber,
            'source_document_type' => 'purchase_order',
            'source_document_id' => $event->purchaseOrderId,
            'ledger_type' => self::COMMITMENT_LEDGER,
            'journal_type' => 'standard',
            'description' => "PO Encumbrance Commitment for {$poNumber}",
            'lines' => [
                [
                    'account_id' => $encumbranceExpAcc->id,
                    'debit_amount' => LedgerAmount::of($event->committedAmount, 4),
                    'credit_amount' => '0',
                    'description' => 'PO Encumbrance',
                ],
                [
                    'account_id' => $encumbranceResAcc->id,
                    'debit_amount' => '0',
                    'credit_amount' => LedgerAmount::of($event->committedAmount, 4),
                    'description' => 'PO Encumbrance Reserve',
                ],
            ],
        ]);
    }

    public function handlePurchaseOrderCancelled(PurchaseOrderCancelledEvent $event): void
    {
        $postingDate = $this->periods->dateOfEvent($event);
        $period = $this->periods->forDate($event->tenantId, $postingDate);

        $encumbranceExpAcc = $this->resolveAccount($event->tenantId, '6990', 'Encumbrance Expense', 'expense', 'debit');
        $encumbranceResAcc = $this->resolveAccount($event->tenantId, '3990', 'Reserve for Encumbrances', 'equity', 'credit');

        $reason = $event->reason ?? ($event->cancellationReason ?? 'Cancelled');

        $amount = LedgerAmount::of($event->cancelledAmount ?? '0', 4);
        if (! Decimal::isPositive($amount)) {
            $encEntry = DB::table('journal_entries')
                ->join('journal_lines', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->where('journal_entries.tenant_id', $event->tenantId)
                ->where('journal_entries.source_document_id', $event->purchaseOrderId)
                ->where('journal_entries.entry_number', 'LIKE', 'ENC-%')
                ->where('journal_lines.debit_amount', '>', 0)
                ->first();
            $amount = LedgerAmount::of($encEntry->debit_amount ?? '0', 4);
        }

        if (! Decimal::isPositive($amount)) {
            return;
        }

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'posting_date' => $postingDate,
            'document_date' => $postingDate,
            'entry_number' => 'REV-ENC-'.$event->purchaseOrderId,
            'source_document_type' => 'purchase_order',
            'source_document_id' => $event->purchaseOrderId,
            'ledger_type' => self::COMMITMENT_LEDGER,
            'journal_type' => 'reversing',
            'description' => "PO Encumbrance Reversal: {$reason}",
            'lines' => [
                [
                    'account_id' => $encumbranceResAcc->id,
                    'debit_amount' => $amount,
                    'credit_amount' => '0',
                    'description' => 'Reverse Encumbrance Reserve',
                ],
                [
                    'account_id' => $encumbranceExpAcc->id,
                    'debit_amount' => '0',
                    'credit_amount' => $amount,
                    'description' => 'Reverse Encumbrance Expense',
                ],
            ],
        ]);
    }

    public function handleGoodsReceived(GoodsReceivedEvent $event): void
    {
        $postingDate = $this->periods->dateOfEvent($event);
        $period = $this->periods->forDate($event->tenantId, $postingDate);

        $invAcc = $this->resolveAccount($event->tenantId, '1200', 'Merchandise Inventory Asset', 'asset', 'debit');
        $grIrAcc = $this->resolveAccount($event->tenantId, '2010', 'GR/IR Clearing Account', 'liability', 'credit');

        $receiptValue = $this->receiptValue($event);

        $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'posting_date' => $postingDate,
            'document_date' => $postingDate,
            'entry_number' => 'GR-'.$event->receiptNumber,
            'source_document_type' => 'goods_receipt',
            'source_document_id' => $event->receiptId,
            'journal_type' => 'standard',
            'description' => "GR/IR Accrual for Receipt {$event->receiptNumber}",
            'lines' => [
                [
                    'account_id' => $invAcc->id,
                    'debit_amount' => $receiptValue,
                    'credit_amount' => '0',
                    'description' => "Inventory Receipt: {$event->supplierDeliveryNoteRef}",
                ],
                [
                    'account_id' => $grIrAcc->id,
                    'debit_amount' => '0',
                    'credit_amount' => $receiptValue,
                    'description' => 'GR/IR Clearing Liability',
                ],
            ],
        ]);
    }

    public function handlePurchaseLiabilityReady(PurchaseLiabilityReadyEvent $event): void
    {
        $sourceType = $event->isCreditNote ? 'vendor_credit_note' : 'vendor_invoice';

        $alreadyPosted = JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $event->tenantId)
            ->where('source_document_type', $sourceType)
            ->where('source_document_id', $event->matchLogId)
            ->exists();

        if ($alreadyPosted) {
            return;
        }

        $postingDate = $event->documentDate ?? $this->periods->dateOfEvent($event);
        $period = $this->periods->forDate($event->tenantId, $postingDate);

        // Without a purchase order there is no goods receipt to clear: the supply is a cost.
        $grIrAcc = $event->purchaseOrderId === ''
            ? $this->accounts->purchaseExpense($event->tenantId)
            : $this->resolveAccount($event->tenantId, '2010', 'GR/IR Clearing Account', 'liability', 'credit');
        $vatAcc = $this->accounts->inputVat($event->tenantId);
        $apAcc = $this->accounts->payables($event->tenantId);

        $currency = ! empty($event->currency) ? $event->currency : TenantAccountingCurrency::of($event->tenantId);
        $exchangeRate = LedgerAmount::of($event->exchangeRate, 6);
        $taxAmount = LedgerAmount::of($event->taxAmount, 2);
        $stampDuty = LedgerAmount::of($event->stampDutyAmount, 2);
        $withholding = LedgerAmount::of($event->withholdingTaxAmount, 2);
        $contribution = LedgerAmount::of($event->contributionAmount, 2);

        $lines = $this->costLines($event, (string) $grIrAcc->id, $currency, $exchangeRate);

        if (Decimal::isPositive($taxAmount)) {
            $lines[] = [
                'account_id' => $vatAcc->id,
                'debit_amount' => $taxAmount,
                'credit_amount' => '0',
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'vat_base_amount' => LedgerAmount::of($event->netAmount, 2),
                'vat_tax_amount' => $taxAmount,
                'vat_rate_percent' => $this->effectiveVatRate($event),
                'description' => "Input VAT for Invoice {$event->vendorInvoiceNumber}",
            ];
        }

        if (Decimal::isPositive($stampDuty)) {
            $lines[] = [
                'account_id' => $this->accounts->stampDutyExpense($event->tenantId)->id,
                'debit_amount' => $stampDuty,
                'credit_amount' => '0',
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'description' => "Stamp duty charged on Invoice {$event->vendorInvoiceNumber}",
            ];
        }

        $lines[] = [
            'account_id' => $apAcc->id,
            'debit_amount' => '0',
            'credit_amount' => Decimal::round2(Decimal::sub(Decimal::sub(LedgerAmount::of($event->grossAmount, 2), $withholding), $contribution)),
            'currency' => $currency,
            'exchange_rate' => $exchangeRate,
            'partner_type' => 'supplier',
            'partner_id' => $event->supplierId,
            'due_date' => $event->dueDate,
            'description' => "Supplier Payable: Invoice {$event->vendorInvoiceNumber}",
        ];

        $withheld = [
            [$withholding, $this->accounts->withholdingPayable(...)],
            [$contribution, $this->accounts->contributionsPayable(...)],
        ];

        foreach ($withheld as [$amount, $account]) {
            if (Decimal::isPositive($amount)) {
                $lines[] = [
                    'account_id' => $account($event->tenantId)->id,
                    'debit_amount' => '0',
                    'credit_amount' => $amount,
                    'currency' => $currency,
                    'exchange_rate' => $exchangeRate,
                    'description' => "Withheld on Invoice {$event->vendorInvoiceNumber}",
                ];
            }
        }

        if ($event->isCreditNote) {
            $lines = array_map(static fn (array $line): array => ['debit_amount' => $line['credit_amount'], 'credit_amount' => $line['debit_amount']] + $line, $lines);
        }

        $journalEntry = $this->postingService->createAndPost([
            'tenant_id' => $event->tenantId,
            'company_id' => $event->companyId,
            'fiscal_period_id' => $period->id,
            'posting_date' => $postingDate,
            'document_date' => $postingDate,
            'entry_number' => 'VEND-INV-'.strtoupper(Str::random(6)),
            'source_document_type' => $sourceType,
            'source_document_id' => $event->matchLogId,
            'document_number' => $event->vendorInvoiceNumber,
            'journal_type' => 'standard',
            'description' => "Vendor Liability for Invoice {$event->vendorInvoiceNumber}",
            'lines' => $lines,
        ]);

        DB::table('passive_invoices')
            ->where('tenant_id', $event->tenantId)
            ->where('id', $event->matchLogId)
            ->update(['gl_journal_entry_id' => (string) $journalEntry->id]);
    }

    /**
     * The debit side of a supplier invoice: the amounts sent to specific cost accounts and the rest on the default one.
     *
     * @return list<array<string, mixed>>
     */
    private function costLines(PurchaseLiabilityReadyEvent $event, string $defaultAccountId, string $currency, string $exchangeRate): array
    {
        $line = fn (string $accountId, string $amount): array => [
            'account_id' => $accountId,
            'debit_amount' => $amount,
            'credit_amount' => '0',
            'currency' => $currency,
            'exchange_rate' => $exchangeRate,
            'description' => "Clear GR/IR: Invoice {$event->vendorInvoiceNumber}",
        ];

        $allocations = $event->purchaseOrderId === '' ? $event->costAllocations : [];
        $lines = array_map(static fn (array $allocation): array => $line($allocation['account_id'], LedgerAmount::of($allocation['amount'], 2)), $allocations);
        $allocated = array_reduce($allocations, static fn (string $sum, array $allocation): string => bcadd($sum, LedgerAmount::of($allocation['amount'], 2), 2), '0.00');
        $remaining = bcsub(LedgerAmount::of($event->netAmount, 2), $allocated, 2);

        if (bccomp($remaining, '0', 2) !== 0 || $lines === []) {
            $lines[] = $line($defaultAccountId, $remaining);
        }

        return $lines;
    }

    /**
     * The value of the accepted goods at the cost of the order lines. A line without a cost is refused: the receipt
     * must not reach the balance sheet with an invented value.
     */
    private function receiptValue(GoodsReceivedEvent $event): string
    {
        $total = '0';

        foreach ($event->receivedLines as $line) {
            if (! isset($line['unit_cost'])) {
                throw new LedgerPostingException("Goods receipt {$event->receiptNumber}: a line has no unit cost.");
            }

            $quantity = LedgerAmount::of($line['quantity_accepted'] ?? $line['quantity_received'] ?? '0', 4);
            $total = Decimal::add($total, Decimal::mul($quantity, LedgerAmount::of($line['unit_cost'], 4)));
        }

        if (! Decimal::isPositive($total)) {
            throw new LedgerPostingException("Goods receipt {$event->receiptNumber} has no value to post.");
        }

        return bcadd($total, '0', 4);
    }

    /**
     * The event carries the totals of the supplier invoice, so the rate is the one they express.
     */
    private function effectiveVatRate(PurchaseLiabilityReadyEvent $event): ?string
    {
        $netAmount = number_format($event->netAmount, 4, '.', '');

        if (! Decimal::isPositive($netAmount)) {
            return null;
        }

        return Decimal::round2(Decimal::div(Decimal::mul(number_format($event->taxAmount, 4, '.', ''), '100'), $netAmount));
    }

    private function resolveAccount(string $tenantId, string $code, string $name, string $type, string $normalBalance): ChartOfAccount
    {
        return ChartOfAccount::withoutGlobalScopes()->firstOrCreate(
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
