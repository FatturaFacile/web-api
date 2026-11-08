<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services;

use App\Models\PaymentTerm;
use App\Models\PaymentTermInstallment;
use App\Services\Sales\DocumentTotalsCalculator;
use Carbon\CarbonImmutable;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Models\InvoiceInstallment;
use Modules\Invoicing\Models\SalesInvoice;

/**
 * The payment schedule (scadenze) of an issued sales document. At emission the amount due is split by the payment term
 * of the document (its installments, percentages and days, end of month), in decimal arithmetic with the last
 * installment taking the rounding remainder; the document due date becomes the last installment. The open amount of each
 * installment is derived from what the document has been paid, applied to the earliest due dates first, so payments,
 * credit-note applications and treasury collections keep the schedule current without writing to it.
 */
final class InvoiceInstallmentService
{
    /**
     * Stores the installments of a document being issued. Without a payment term the document keeps its single due date
     * and no installment is stored. Calling it again on a document that already has installments changes nothing.
     */
    public function scheduleAtIssue(SalesInvoice $invoice): void
    {
        if ($invoice->payment_term_id === null || $invoice->isCreditNote() || $invoice->invoice_type === 'proforma') {
            return;
        }

        if (InvoiceInstallment::withoutGlobalScope(TenantScope::class)->where('invoice_id', $invoice->id)->exists()) {
            return;
        }

        $term = PaymentTerm::withoutGlobalScopes()->where(fn ($query) => $query->where('tenant_id', $invoice->tenant_id)->orWhereNull('tenant_id'))->find($invoice->payment_term_id);
        $amount = Decimal::round2($invoice->amountDue());

        if ($term === null || ! Decimal::isPositive($amount)) {
            return;
        }

        $rows = $this->split($term, $amount, CarbonImmutable::parse($invoice->date));

        foreach ($rows as $row) {
            InvoiceInstallment::withoutGlobalScope(TenantScope::class)->create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => (string) $invoice->tenant_id,
                'invoice_id' => (string) $invoice->id,
                'sequence' => $row['sequence'],
                'due_date' => $row['due_date'],
                'amount' => $row['amount'],
            ]);
        }

        if ($rows !== []) {
            $invoice->due_date = $rows[count($rows) - 1]['due_date'];
        }
    }

    /**
     * The installments of a document with what is paid and open on each, earliest first. A document without stored
     * installments has one: its due date for its amount due.
     *
     * @return list<array{sequence: int, due_date: string|null, amount: string, paid: string, open: string}>
     */
    public function scheduleOf(SalesInvoice $invoice): array
    {
        $installments = InvoiceInstallment::withoutGlobalScope(TenantScope::class)->where('invoice_id', $invoice->id)->orderBy('sequence')->get();
        $rows = $installments->isEmpty()
            ? [['sequence' => 1, 'due_date' => ($invoice->getAttribute('due_date') instanceof \DateTimeInterface ? $invoice->getAttribute('due_date')->format('Y-m-d') : null), 'amount' => Decimal::round2($invoice->amountDue())]]
            : $installments->map(static fn (InvoiceInstallment $row): array => ['sequence' => $row->sequence, 'due_date' => $row->due_date->toDateString(), 'amount' => Decimal::round2((string) $row->amount)])->all();

        $paid = DocumentTotalsCalculator::decimal($invoice->total_paid ?? '0');
        $result = [];

        foreach ($rows as $row) {
            $applied = Decimal::compare($paid, $row['amount']) >= 0 ? $row['amount'] : (Decimal::isPositive($paid) ? $paid : '0');
            $paid = Decimal::sub($paid, $applied);
            $result[] = $row + ['paid' => Decimal::round2($applied), 'open' => Decimal::round2(Decimal::sub($row['amount'], $applied))];
        }

        return $result;
    }

    /**
     * @return list<array{sequence: int, due_date: string, amount: string}>
     */
    private function split(PaymentTerm $term, string $amount, CarbonImmutable $documentDate): array
    {
        $parts = PaymentTermInstallment::query()->where('payment_term_id', $term->id)->orderBy('sequence')->get()
            ->map(static fn (PaymentTermInstallment $row): array => ['sequence' => (int) $row->sequence, 'percent' => DocumentTotalsCalculator::decimal($row->percent), 'days' => (int) $row->days])
            ->all();

        if ($parts === []) {
            $parts = [['sequence' => 1, 'percent' => '100', 'days' => (int) $term->days]];
        }

        $rows = [];
        $allocated = '0';
        $lastIndex = count($parts) - 1;

        foreach ($parts as $index => $part) {
            $due = $documentDate->startOfDay()->addDays($part['days']);

            if ($term->end_of_month) {
                $due = $due->endOfMonth();
            }

            $share = $index === $lastIndex
                ? Decimal::round2(Decimal::sub($amount, $allocated))
                : Decimal::round2(Decimal::div(Decimal::mul($amount, $part['percent']), '100'));
            $allocated = Decimal::add($allocated, $share);

            $rows[] = ['sequence' => $part['sequence'], 'due_date' => $due->toDateString(), 'amount' => $share];
        }

        return $rows;
    }
}
