<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use App\Models\Invoice;
use App\Models\PassiveInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One list of what is still to collect and to pay, by due date. Payable and receivable reports stay where they are:
 * `/accounting/vendor-aging` gives the supplier buckets and the dashboard the 30-60-90 forecast; only the merged,
 * filterable list by date is here.
 */
final class DueCalendar
{
    /**
     * @param  array{direction?: ?string, contact_id?: ?string, overdue_only?: bool}  $filters
     * @return Collection<int, array{direction: string, document_id: string, document_number: string, contact_id: ?string, contact_name: string, due_date: string, outstanding: string, currency: string, payment_status: string, is_overdue: bool}>
     */
    public function entries(CarbonImmutable $from, CarbonImmutable $to, array $filters = []): Collection
    {
        $direction = $filters['direction'] ?? null;
        $entries = collect();

        if ($direction !== 'out') {
            $entries = $entries->concat($this->receivables($from, $to, $filters['contact_id'] ?? null));
        }

        if ($direction !== 'in') {
            $entries = $entries->concat($this->payables($from, $to, $filters['contact_id'] ?? null));
        }

        return $entries
            ->when($filters['overdue_only'] ?? false, static fn (Collection $items): Collection => $items->where('is_overdue', true))
            ->sortBy([['due_date', 'asc'], ['document_number', 'asc']])
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function receivables(CarbonImmutable $from, CarbonImmutable $to, ?string $contactId): Collection
    {
        return Invoice::with('contact')
            ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotIn('invoice_type', ['proforma', 'credit_note'])
            ->where('payment_status', '!=', 'paid')
            ->when($contactId, static fn ($query, string $id) => $query->where('contact_id', $id))
            ->get()
            ->map(fn (Invoice $invoice): array => $this->entry('in', (string) $invoice->id, (string) $invoice->invoice_number, $invoice->contact, $invoice->due_date, (string) $invoice->total_gross, (string) $invoice->total_paid, (string) $invoice->currency, (string) $invoice->payment_status))
            ->filter(static fn (array $entry): bool => bccomp($entry['outstanding'], '0', 2) === 1)
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function payables(CarbonImmutable $from, CarbonImmutable $to, ?string $contactId): Collection
    {
        return PassiveInvoice::with('supplier')
            ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
            ->where('document_type', '!=', 'TD04')
            ->where('payment_status', '!=', 'paid')
            ->when($contactId, static fn ($query, string $id) => $query->where('supplier_id', $id))
            ->get()
            ->map(fn (PassiveInvoice $invoice): array => $this->entry('out', (string) $invoice->id, (string) $invoice->invoice_number, $invoice->supplier, $invoice->due_date, (string) $invoice->total_amount, (string) $invoice->total_paid, (string) $invoice->currency, (string) $invoice->payment_status))
            ->filter(static fn (array $entry): bool => bccomp($entry['outstanding'], '0', 2) === 1)
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $direction, string $id, string $number, mixed $contact, mixed $dueDate, string $total, string $paid, string $currency, string $status): array
    {
        return [
            'direction' => $direction,
            'document_id' => $id,
            'document_number' => $number,
            'contact_id' => $contact?->id,
            'contact_name' => (string) ($contact?->company_name ?: trim($contact?->first_name.' '.$contact?->last_name)),
            'due_date' => $dueDate->toDateString(),
            'outstanding' => bcsub($total, $paid === '' ? '0' : $paid, 2),
            'currency' => $currency,
            'payment_status' => $status,
            'is_overdue' => $dueDate->isBefore(now()->startOfDay()),
        ];
    }
}
