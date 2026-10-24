<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\Contact;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\Payment;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Models\Subscription;
use App\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Read-only analytics on a contact: sales statistics, payment behaviour and the open-items account statement.
 * Proforma invoices are ignored everywhere; credit notes reduce the invoiced totals.
 */
class ContactInsights
{
    /**
     * @return array<string, mixed>
     */
    public function statistics(Contact $contact): array
    {
        $invoices = Invoice::where('contact_id', $contact->id)->where('invoice_type', '!=', 'proforma')->get();
        $billable = $invoices->where('invoice_type', '!=', 'credit_note');
        $credits = $invoices->where('invoice_type', 'credit_note');
        $today = today();

        $balances = $billable->map(fn (Invoice $invoice): float => round((float) $invoice->total_gross - (float) $invoice->total_paid, 2));
        $overdue = $billable->filter(fn (Invoice $invoice): bool => $invoice->payment_status !== 'paid' && $invoice->due_date !== null && $invoice->due_date->lt($today));

        return [
            'contact_id' => $contact->id,
            'invoice_count' => $billable->count(),
            'invoiced_total' => round((float) $billable->sum('total_gross') - (float) $credits->sum('total_gross'), 2),
            'paid_total' => round((float) $billable->sum('total_paid'), 2),
            'outstanding_total' => max(0.0, round($balances->sum() - (float) $credits->sum('total_gross'), 2)),
            'overdue_total' => round($overdue->sum(fn (Invoice $invoice): float => (float) $invoice->total_gross - (float) $invoice->total_paid), 2),
            'average_invoice_value' => $billable->isEmpty() ? 0.0 : round((float) $billable->avg('total_gross'), 2),
            'first_invoice_date' => $billable->min('date')?->toDateString(),
            'last_invoice_date' => $billable->max('date')?->toDateString(),
            'average_days_to_pay' => $this->averageDaysToPay($billable),
            'yearly_sales' => $this->yearlySales($billable, $credits),
            'documents' => [
                'quotes' => Quote::where('contact_id', $contact->id)->count(),
                'sales_orders' => SalesOrder::where('contact_id', $contact->id)->count(),
                'delivery_notes' => DeliveryNote::where('contact_id', $contact->id)->count(),
                'work_orders' => WorkOrder::where('client_id', $contact->id)->count(),
                'active_subscriptions' => Subscription::where('contact_id', $contact->id)->where('status', 'active')->count(),
            ],
            'purchases' => $this->purchases($contact),
        ];
    }

    /**
     * Open invoices of the contact as of a date, with days overdue and ageing buckets.
     *
     * @return array<string, mixed>
     */
    public function statement(Contact $contact, ?CarbonInterface $asOf = null): array
    {
        $asOf = CarbonImmutable::instance($asOf ?? now())->startOfDay();

        $lines = Invoice::where('contact_id', $contact->id)
            ->whereNotIn('invoice_type', ['proforma', 'credit_note'])
            ->where('payment_status', '!=', 'paid')
            ->whereDate('date', '<=', $asOf)
            ->orderBy('due_date')
            ->get()
            ->map(function (Invoice $invoice) use ($asOf): array {
                $balance = round((float) $invoice->total_gross - (float) $invoice->total_paid, 2);
                $daysOverdue = $invoice->due_date !== null && $invoice->due_date->lt($asOf) ? (int) $invoice->due_date->diffInDays($asOf) : 0;

                return [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'date' => $invoice->date->toDateString(),
                    'due_date' => $invoice->due_date?->toDateString(),
                    'currency' => $invoice->currency,
                    'total' => (float) $invoice->total_gross,
                    'paid' => (float) $invoice->total_paid,
                    'balance' => $balance,
                    'days_overdue' => $daysOverdue,
                ];
            })
            ->filter(fn (array $line): bool => $line['balance'] > 0)
            ->values();

        $bucket = fn (int $days): string => match (true) {
            $days <= 0 => 'not_due',
            $days <= 30 => '1_30',
            $days <= 60 => '31_60',
            $days <= 90 => '61_90',
            default => 'over_90',
        };

        $ageing = array_fill_keys(['not_due', '1_30', '31_60', '61_90', 'over_90'], 0.0);

        foreach ($lines as $line) {
            $ageing[$bucket($line['days_overdue'])] += $line['balance'];
        }

        return [
            'contact_id' => $contact->id,
            'as_of' => $asOf->toDateString(),
            'lines' => $lines->all(),
            'total_due' => round((float) $lines->sum('balance'), 2),
            'overdue_total' => round((float) $lines->where('days_overdue', '>', 0)->sum('balance'), 2),
            'ageing' => array_map(fn (float $amount): float => round($amount, 2), $ageing),
        ];
    }

    /**
     * Average days between invoice date and the last payment, over fully paid invoices.
     *
     * @param  Collection<int, Invoice>  $billable
     */
    private function averageDaysToPay(Collection $billable): ?float
    {
        $paid = $billable->where('payment_status', 'paid');

        if ($paid->isEmpty()) {
            return null;
        }

        $lastPayments = Payment::whereIn('invoice_id', $paid->pluck('id'))
            ->selectRaw('invoice_id, MAX(paid_at) as last_paid_at')
            ->groupBy('invoice_id')
            ->pluck('last_paid_at', 'invoice_id');

        $days = $paid->filter(fn (Invoice $invoice): bool => $lastPayments->has($invoice->id))
            ->map(fn (Invoice $invoice): int => (int) $invoice->date->diffInDays(CarbonImmutable::parse($lastPayments[$invoice->id]), false));

        return $days->isEmpty() ? null : round((float) $days->avg(), 1);
    }

    /**
     * Net sales per calendar year, credit notes deducted, most recent year first.
     *
     * @param  Collection<int, Invoice>  $billable
     * @param  Collection<int, Invoice>  $credits
     * @return array<string, float>
     */
    private function yearlySales(Collection $billable, Collection $credits): array
    {
        $sales = $billable->groupBy(fn (Invoice $invoice): string => (string) $invoice->date->year)
            ->map(fn (Collection $group): float => (float) $group->sum('total_net'));

        foreach ($credits->groupBy(fn (Invoice $invoice): string => (string) $invoice->date->year) as $year => $group) {
            $sales[$year] = ($sales[$year] ?? 0.0) - (float) $group->sum('total_net');
        }

        return $sales->map(fn (float $amount): float => round($amount, 2))->sortKeysDesc()->all();
    }

    /**
     * Spend with the contact as a supplier, supplier credit notes (TD04) deducted.
     *
     * @return array{document_count: int, total: float}
     */
    private function purchases(Contact $contact): array
    {
        $documents = PassiveInvoice::where('supplier_id', $contact->id)->get();

        return [
            'document_count' => $documents->count(),
            'total' => round((float) $documents->where('document_type', '!=', 'TD04')->sum('total_amount') - (float) $documents->where('document_type', 'TD04')->sum('total_amount'), 2),
        ];
    }
}
