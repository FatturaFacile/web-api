<?php

declare(strict_types=1);

namespace App\Services\Dashboard\Widgets;

use App\Models\Invoice;
use Carbon\CarbonImmutable;

/**
 * Customer invoices past their due date with an open balance (credit notes and proformas are not receivables).
 */
class OverdueInvoicesWidget extends AbstractDashboardWidget
{
    public function getId(): string
    {
        return 'overdue_invoices';
    }

    public function getType(): string
    {
        return 'data_table';
    }

    public function getDefaultDimensions(): array
    {
        return ['w' => 12, 'h' => 4];
    }

    public function getConfigurableOptions(): array
    {
        return ['limit' => ['type' => 'integer', 'min' => 1, 'max' => 50, 'default' => 10]];
    }

    public function getData(array $settings = []): array
    {
        $limit = $this->resolveSettings($settings)['limit'];
        $today = CarbonImmutable::today();

        $overdue = Invoice::query()
            ->select(['id', 'contact_id', 'invoice_number', 'due_date', 'total_gross'])
            ->whereNotIn('invoice_type', ['credit_note', 'proforma'])
            ->where('payment_status', '!=', 'paid')
            ->where('due_date', '<', $today->toDateString())
            ->withSum('payments', 'amount')
            ->with('contact:id,company_name,first_name,last_name')
            ->get()
            ->map(fn (Invoice $invoice): array => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'contact_name' => $invoice->contact === null ? null : ($invoice->contact->company_name ?: trim($invoice->contact->first_name.' '.$invoice->contact->last_name)),
                'due_date' => $invoice->due_date->toDateString(),
                'days_overdue' => (int) abs($invoice->due_date->startOfDay()->diffInDays($today)),
                'outstanding' => round((float) $invoice->total_gross - (float) $invoice->payments_sum_amount, 2),
            ])
            ->filter(fn (array $row): bool => $row['outstanding'] > 0)
            ->sortByDesc('days_overdue')
            ->values();

        return [
            'currency' => $this->currency(),
            'count' => $overdue->count(),
            'total_outstanding' => round((float) $overdue->sum('outstanding'), 2),
            'average_days_overdue' => $overdue->isEmpty() ? 0.0 : round((float) $overdue->avg('days_overdue'), 1),
            'invoices' => $overdue->take($limit)->all(),
        ];
    }
}
