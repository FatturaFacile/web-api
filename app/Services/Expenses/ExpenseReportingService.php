<?php

declare(strict_types=1);

namespace App\Services\Expenses;

use App\Models\Expense;
use App\Services\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ExpenseReportingService
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Get aggregate summary report: monthly, by category, by project, and by supplier.
     *
     * @return array{
     *     total_expenses: float,
     *     total_count: int,
     *     monthly: list<array<string, mixed>>,
     *     by_category: list<array<string, mixed>>,
     *     by_project: list<array<string, mixed>>,
     *     by_supplier: list<array<string, mixed>>
     * }
     */
    public function getSummaryReport(?int $year = null, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $year ??= (int) now()->format('Y');

        $baseQuery = Expense::query();
        if ($dateFrom) {
            $baseQuery->where('date', '>=', $dateFrom);
        } else {
            $baseQuery->whereYear('date', $year);
        }
        if ($dateTo) {
            $baseQuery->where('date', '<=', $dateTo);
        }

        $expenses = $baseQuery->with(['category.translations', 'contact'])->get();

        $totalAmount = (float) $expenses->sum('amount_gross');
        $totalCount = $expenses->count();

        return [
            'total_expenses' => round($totalAmount, 2),
            'total_count' => $totalCount,
            'monthly' => $this->getMonthlyBreakdown($expenses, $year),
            'by_category' => $this->getCategoryBreakdown($expenses, $totalAmount),
            'by_project' => $this->getProjectBreakdown($expenses),
            'by_supplier' => $this->getSupplierBreakdown($expenses),
        ];
    }

    /**
     * @param  Collection<int, Expense>  $expenses
     * @return list<array<string, mixed>>
     */
    private function getMonthlyBreakdown(Collection $expenses, int $year): array
    {
        $monthly = [];
        for ($m = 1; $m <= 12; $m++) {
            $monthStr = sprintf('%04d-%02d', $year, $m);
            $monthExpenses = $expenses->filter(fn (Expense $e) => $e->date && $e->date->format('Y-m') === $monthStr);

            $monthly[] = [
                'month' => $monthStr,
                'month_name' => Carbon::createFromDate($year, $m, 1)->translatedFormat('F'),
                'total_net' => round((float) $monthExpenses->sum('amount_net'), 2),
                'total_tax' => round((float) $monthExpenses->sum('tax_amount'), 2),
                'total_gross' => round((float) $monthExpenses->sum('amount_gross'), 2),
                'count' => $monthExpenses->count(),
            ];
        }

        return $monthly;
    }

    /**
     * @param  Collection<int, Expense>  $expenses
     * @return list<array<string, mixed>>
     */
    private function getCategoryBreakdown(Collection $expenses, float $totalAmount): array
    {
        $locale = app()->getLocale();

        return $expenses->groupBy('category_id')
            ->map(function (Collection $group, string $categoryId) use ($locale, $totalAmount): array {
                /** @var Expense $first */
                $first = $group->first();
                $category = $first->category;
                $name = $category?->translations->firstWhere('locale', $locale)?->label
                    ?? $category?->translations->firstWhere('locale', 'en')?->label
                    ?? $category?->code
                    ?? 'Unknown';

                $gross = round((float) $group->sum('amount_gross'), 2);
                $percentage = $totalAmount > 0 ? round(($gross / $totalAmount) * 100, 1) : 0.0;

                return [
                    'category_id' => $categoryId,
                    'code' => $category?->code ?? 'unknown',
                    'name' => $name,
                    'total_gross' => $gross,
                    'total_net' => round((float) $group->sum('amount_net'), 2),
                    'count' => $group->count(),
                    'percentage' => $percentage,
                ];
            })
            ->sortByDesc('total_gross')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Expense>  $expenses
     * @return list<array<string, mixed>>
     */
    private function getProjectBreakdown(Collection $expenses): array
    {
        return $expenses->groupBy('project_id')
            ->map(function (Collection $group, ?string $projectId): array {
                return [
                    'project_id' => $projectId,
                    'has_project' => ! empty($projectId),
                    'total_gross' => round((float) $group->sum('amount_gross'), 2),
                    'total_net' => round((float) $group->sum('amount_net'), 2),
                    'count' => $group->count(),
                ];
            })
            ->sortByDesc('total_gross')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, Expense>  $expenses
     * @return list<array<string, mixed>>
     */
    private function getSupplierBreakdown(Collection $expenses): array
    {
        return $expenses->groupBy(function (Expense $e): string {
            if ($e->contact) {
                return $e->contact->company_name ?? ($e->contact->first_name.' '.$e->contact->last_name);
            }

            return $e->merchant_name ?: 'Other / Unspecified';
        })
            ->map(function (Collection $group, string $supplierName): array {
                return [
                    'supplier_name' => $supplierName,
                    'contact_id' => $group->first()?->contact_id,
                    'total_gross' => round((float) $group->sum('amount_gross'), 2),
                    'count' => $group->count(),
                ];
            })
            ->sortByDesc('total_gross')
            ->values()
            ->all();
    }
}
