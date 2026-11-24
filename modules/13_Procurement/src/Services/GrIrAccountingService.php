<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use App\Models\PassiveInvoice;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\Procurement\Models\InvoiceMatchingRun;
use Modules\Procurement\Models\PurchaseReceipt;
use Modules\Procurement\Support\Decimal;

/**
 * GR/IR (goods receipt / invoice receipt) accounting of a matched invoice. Booking a goods receipt accrues
 * inventory (debit) against GR/IR clearing 2010 (credit); posting the matched invoice reverses the accrual (debit
 * GR/IR for the quantity received and billed), books the input VAT (debit) and the supplier payable (credit), and puts
 * any billed value beyond the accrual on the purchase expense account. This service reads the journal entries tied to a
 * run and reports what is accrued, cleared and still open.
 */
final class GrIrAccountingService
{
    public const string ClearingAccountCode = '2010';

    /**
     * @return array{
     *     currency: string,
     *     expected_clearing_amount: string,
     *     expected_variance_amount: string,
     *     accrued_amount: string,
     *     cleared_amount: string,
     *     open_balance: string,
     *     receipts: list<array<string, mixed>>,
     *     invoice: array<string, mixed>|null
     * }
     */
    public function forRun(InvoiceMatchingRun $run): array
    {
        $tenantId = (string) $run->tenant_id;
        $receipts = $run->purchase_order_id === null
            ? collect()
            : PurchaseReceipt::query()->where('purchase_order_id', $run->purchase_order_id)->orderBy('received_date')->get();

        $entries = JournalEntry::query()
            ->where('tenant_id', $tenantId)
            ->where(static function ($query) use ($receipts, $run): void {
                $query->where(static fn ($inner) => $inner->where('source_document_type', 'goods_receipt')->whereIn('source_document_id', $receipts->pluck('id')->all()))
                    ->orWhere(static fn ($inner) => $inner->whereIn('source_document_type', ['vendor_invoice', 'vendor_credit_note'])->where('source_document_id', $run->passive_invoice_id));
            })
            ->with('lines.account')
            ->orderBy('posting_date')
            ->get();

        $accrued = '0.00';
        $cleared = '0.00';
        $receiptRows = [];
        $invoiceRow = null;

        foreach ($entries as $entry) {
            $row = $this->present($entry);
            $grIr = $this->clearingMovement($row['lines']);

            if ($entry->source_document_type === 'goods_receipt') {
                $accrued = Decimal::add($accrued, Decimal::sub($grIr['credit'], $grIr['debit'], 2), 2);
                $receipt = $receipts->firstWhere('id', $entry->source_document_id);
                $receiptRows[] = $row + [
                    'receipt_id' => (string) $entry->source_document_id,
                    'receipt_number' => $receipt?->receipt_number,
                    'clearing_amount' => Decimal::sub($grIr['credit'], $grIr['debit'], 2),
                ];

                continue;
            }

            $cleared = Decimal::add($cleared, Decimal::sub($grIr['debit'], $grIr['credit'], 2), 2);
            $invoiceRow = $row + ['clearing_amount' => Decimal::sub($grIr['debit'], $grIr['credit'], 2)];
        }

        return [
            'currency' => (string) $run->currency,
            'expected_clearing_amount' => (string) $run->gr_ir_amount,
            'expected_variance_amount' => (string) $run->price_variance_amount,
            'accrued_amount' => $accrued,
            'cleared_amount' => $cleared,
            'open_balance' => Decimal::sub($accrued, $cleared, 2),
            'receipts' => $receiptRows,
            'invoice' => $invoiceRow,
        ];
    }

    /**
     * The accrual a supplier invoice clears when it is posted: only an invoice whose current matching run cleared
     * (matched, within tolerance, resolved or overridden) against goods received reverses GR/IR. The remainder of the
     * invoice goes to the purchase expense account. Null when the invoice is posted as a plain cost.
     *
     * @return array{purchase_order_id: string, amount: string}|null
     */
    public function clearingFor(PassiveInvoice $invoice): ?array
    {
        if ($invoice->document_type === 'TD04') {
            return null;
        }

        $run = InvoiceMatchingRun::query()
            ->where('tenant_id', $invoice->tenant_id)
            ->where('passive_invoice_id', $invoice->id)
            ->where('is_current', true)
            ->first();

        if ($run === null || $run->purchase_order_id === null || $run->status->isBlocking() || ! Decimal::isPositive((string) $run->gr_ir_amount)) {
            return null;
        }

        return ['purchase_order_id' => (string) $run->purchase_order_id, 'amount' => (string) $run->gr_ir_amount];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(JournalEntry $entry): array
    {
        return [
            'journal_entry_id' => (string) $entry->id,
            'entry_number' => (string) $entry->entry_number,
            'posting_date' => $entry->posting_date?->toDateString(),
            'status' => (string) $entry->status,
            'description' => $entry->description,
            'lines' => $entry->lines->map(static fn ($line): array => [
                'account_code' => (string) $line->account->account_code,
                'account_name' => (string) $line->account->name,
                'debit_amount' => Decimal::of((string) $line->debit_amount, 2),
                'credit_amount' => Decimal::of((string) $line->credit_amount, 2),
                'description' => $line->description,
            ])->all(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{debit: string, credit: string}
     */
    private function clearingMovement(array $lines): array
    {
        $debit = '0.00';
        $credit = '0.00';

        foreach ($lines as $line) {
            if ($line['account_code'] === self::ClearingAccountCode) {
                $debit = Decimal::add($debit, $line['debit_amount'], 2);
                $credit = Decimal::add($credit, $line['credit_amount'], 2);
            }
        }

        return ['debit' => $debit, 'credit' => $credit];
    }
}
