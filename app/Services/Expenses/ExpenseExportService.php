<?php

declare(strict_types=1);

namespace App\Services\Expenses;

use App\Models\Expense;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ExpenseExportService
{
    /**
     * Build exportable expense dataset according to filter options.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Expense>
     */
    public function queryExpenses(array $filters): Collection
    {
        return Expense::query()
            ->with(['category.translations', 'contact', 'user', 'ticket', 'serviceReport'])
            ->when(! empty($filters['date_from']), fn (Builder $q) => $q->where('date', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn (Builder $q) => $q->where('date', '<=', $filters['date_to']))
            ->when(! empty($filters['category_id']), fn (Builder $q) => $q->where('category_id', $filters['category_id']))
            ->when(! empty($filters['project_id']), fn (Builder $q) => $q->where('project_id', $filters['project_id']))
            ->when(! empty($filters['contact_id']), fn (Builder $q) => $q->where('contact_id', $filters['contact_id']))
            ->when(! empty($filters['user_id']), fn (Builder $q) => $q->where('user_id', $filters['user_id']))
            ->when(! empty($filters['status']) && $filters['status'] !== 'all', fn (Builder $q) => $q->where('status', $filters['status']))
            ->orderBy('date')
            ->orderBy('expense_number')
            ->get();
    }

    /**
     * Generate standard CSV file contents for tax advisor and accountant import.
     *
     * @param  Collection<int, Expense>  $expenses
     */
    public function generateCsv(Collection $expenses): string
    {
        $handle = fopen('php://temp', 'r+');
        $locale = app()->getLocale();

        // Standard CSV Headers for Accountant / ERP Import
        fputcsv($handle, [
            'Expense Number',
            'Date',
            'Category Code',
            'Category Name',
            'Merchant / Supplier',
            'Supplier VAT Number',
            'Supplier Fiscal Code',
            'Net Amount',
            'Tax Rate %',
            'Tax Amount',
            'Gross Amount',
            'Currency',
            'Payment Method',
            'Status',
            'Billable',
            'Reimbursable',
            'Project ID',
            'Ticket ID',
            'Service Report ID',
            'Technician / Employee',
            'Notes',
            'Exported At',
        ]);

        foreach ($expenses as $expense) {
            $catName = $expense->category?->translations->firstWhere('locale', $locale)?->label
                ?? $expense->category?->translations->firstWhere('locale', 'en')?->label
                ?? $expense->category?->code
                ?? '';

            $supplierName = $expense->contact?->company_name
                ?? ($expense->contact ? $expense->contact->first_name.' '.$expense->contact->last_name : $expense->merchant_name)
                ?? '';

            fputcsv($handle, [
                $expense->expense_number,
                $expense->date?->format('Y-m-d') ?? '',
                $expense->category?->code ?? '',
                $catName,
                $supplierName,
                $expense->contact?->vat_number ?? '',
                $expense->contact?->fiscal_code ?? '',
                number_format((float) $expense->amount_net, 2, '.', ''),
                number_format((float) $expense->tax_rate, 2, '.', ''),
                number_format((float) $expense->tax_amount, 2, '.', ''),
                number_format((float) $expense->amount_gross, 2, '.', ''),
                $expense->currency,
                $expense->payment_method,
                $expense->status,
                $expense->is_billable ? 'YES' : 'NO',
                $expense->is_reimbursable ? 'YES' : 'NO',
                $expense->project_id ?? '',
                $expense->ticket_id ?? '',
                $expense->service_report_id ?? '',
                $expense->user?->name ?? '',
                $expense->notes ?? '',
                now()->toIso8601String(),
            ]);
        }

        rewind($handle);
        $csvContent = stream_get_contents($handle);
        fclose($handle);

        return $csvContent ?: '';
    }
}
