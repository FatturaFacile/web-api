<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use App\Actions\Sales\RecordPayment;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Models\SalesInvoice;

/**
 * Split-payment invoices posted before the posting left only the net on the receivable still carry the gross: the VAT
 * part can never be collected, since the public administration pays it to the tax authority. The adjustment does in a
 * later entry what the posting does today: Cr receivables by the VAT, settling the item of the invoice, and Dr the VAT
 * account the invoice credited, so that VAT leaves it as the public administration pays it.
 */
final readonly class SplitPaymentReceivableAdjuster
{
    public const string Source = 'split_payment_adjustment';

    public function __construct(
        private DoubleEntryPostingServiceInterface $postingService,
        private FiscalPeriodResolver $periods,
        private LedgerAccountResolver $accounts,
        private RecordPayment $payments,
    ) {}

    /**
     * The split-payment invoices of the tenant whose entry still debits the receivable by more than the amount due, and
     * that no adjustment has corrected yet.
     *
     * @return Collection<int, array{invoice: SalesInvoice, receivable: string, due: string, adjustment: string}>
     */
    public function affected(string $tenantId): Collection
    {
        $receivables = (string) $this->accounts->receivables($tenantId)->id;
        $adjusted = JournalEntry::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('source_document_type', self::Source)->where('status', 'posted')->pluck('source_document_id');

        return SalesInvoice::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('split_payment', true)
            ->where('status', 'posted')
            ->where('invoice_type', '!=', 'credit_note')
            ->whereNotNull('gl_journal_entry_id')
            ->whereNotIn('id', $adjusted)
            ->orderBy('date')
            ->orderBy('invoice_number')
            ->get()
            ->map(function (SalesInvoice $invoice) use ($receivables): ?array {
                $receivable = Decimal::round2((string) JournalLine::withoutGlobalScopes()
                    ->where('journal_entry_id', $invoice->gl_journal_entry_id)
                    ->where('account_id', $receivables)
                    ->sum(DB::raw('debit_amount - credit_amount')));
                $due = $invoice->amountDue();
                $adjustment = Decimal::round2(Decimal::sub($receivable, $due));

                return Decimal::isPositive($adjustment)
                    ? ['invoice' => $invoice, 'receivable' => $receivable, 'due' => $due, 'adjustment' => $adjustment]
                    : null;
            })
            ->filter()
            ->values();
    }

    /**
     * Posts the adjustment of one invoice, once. The invoice is locked, so two runs cannot both post it.
     *
     * @return JournalEntry|null null when the invoice needs no adjustment any more
     */
    public function adjust(SalesInvoice $invoice, CarbonImmutable $postingDate): ?JournalEntry
    {
        return DB::transaction(function () use ($invoice, $postingDate): ?JournalEntry {
            $tenantId = (string) $invoice->tenant_id;
            SalesInvoice::withoutGlobalScopes()->whereKey($invoice->id)->lockForUpdate()->first();

            $existing = JournalEntry::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('source_document_type', self::Source)->where('source_document_id', $invoice->id)->where('status', 'posted')->exists();
            $row = $existing ? null : $this->affected($tenantId)->first(static fn (array $candidate): bool => $candidate['invoice']->id === $invoice->id);

            if ($row === null) {
                return null;
            }

            $amount = $row['adjustment'];
            $date = $postingDate->toDateString();
            $period = $this->periods->forDate($tenantId, $date);
            $currency = (string) ($invoice->currency ?: 'EUR');
            $rate = (float) ($invoice->exchange_rate ?: 1);

            $entry = $this->postingService->createAndPost([
                'tenant_id' => $tenantId,
                'company_id' => $tenantId,
                'fiscal_period_id' => $period->id,
                'entry_number' => 'SPA-'.strtoupper(Str::random(10)),
                'source_document_type' => self::Source,
                'source_document_id' => (string) $invoice->id,
                'document_number' => (string) $invoice->invoice_number,
                'posting_date' => $date,
                'document_date' => $date,
                'journal_type' => 'adjusting',
                'description' => "Split payment VAT off the receivable: Invoice {$invoice->invoice_number}",
                'lines' => [
                    ...$this->vatLines($invoice, $amount, $currency, $rate),
                    [
                        'account_id' => $this->accounts->receivables($tenantId)->id,
                        'debit_amount' => '0',
                        'credit_amount' => $amount,
                        'currency' => $currency,
                        'exchange_rate' => $rate,
                        'partner_type' => 'customer',
                        'partner_id' => (string) $invoice->contact_id,
                        'settles_entry_id' => (string) $invoice->gl_journal_entry_id,
                        'description' => "Split payment VAT paid by the customer to the tax authority: Invoice {$invoice->invoice_number}",
                    ],
                ],
            ]);

            SalesInvoice::withoutGlobalScopes()->whereKey($invoice->id)->update(['receivable_amount' => $row['due']]);
            $this->payments->recalculate(Invoice::withoutGlobalScopes()->findOrFail($invoice->id));

            return $entry;
        });
    }

    /**
     * The debit side: the VAT accounts the invoice entry credited, in their proportion, the last one taking the rounding.
     *
     * @return list<array<string, mixed>>
     */
    private function vatLines(SalesInvoice $invoice, string $amount, string $currency, float $rate): array
    {
        $credited = JournalLine::withoutGlobalScopes()
            ->where('journal_entry_id', $invoice->gl_journal_entry_id)
            ->where('vat_tax_amount', '>', 0)
            ->where('credit_amount', '>', 0)
            ->selectRaw('account_id, SUM(credit_amount) AS credited')
            ->groupBy('account_id')
            ->orderBy('account_id')
            ->get();
        $total = Decimal::round2((string) $credited->sum('credited'));

        if ($credited->isEmpty() || ! Decimal::isPositive($total)) {
            $credited = collect([(object) ['account_id' => $this->accounts->outputVat((string) $invoice->tenant_id)->id, 'credited' => $amount]]);
            $total = $amount;
        }

        $lines = [];
        $left = $amount;

        foreach ($credited->values() as $index => $row) {
            $share = $index === $credited->count() - 1 ? $left : Decimal::round2(Decimal::div(Decimal::mul($amount, (string) $row->credited), $total));
            $left = Decimal::sub($left, $share);
            $lines[] = [
                'account_id' => $row->account_id,
                'debit_amount' => Decimal::round2($share),
                'credit_amount' => '0',
                'currency' => $currency,
                'exchange_rate' => $rate,
                'description' => "Split payment VAT paid by the customer to the tax authority: Invoice {$invoice->invoice_number}",
            ];
        }

        return $lines;
    }
}
