<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Application\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\ExpenseManagement\Models\ExpenseReport;

final class GetExpenseReportsHandler
{
    /**
     * @return LengthAwarePaginator<int, ExpenseReport>
     */
    public function handle(GetExpenseReportsQuery $query): LengthAwarePaginator
    {
        return ExpenseReport::query()
            ->with('claimant:id,name')
            ->withCount([
                'lines as violations_count' => fn ($lines) => $lines->whereNotNull('policy_violations'),
            ])
            ->when($query->restrictToUserId !== null, fn ($reports) => $reports->where('user_id', $query->restrictToUserId))
            ->when($query->status !== null, fn ($reports) => $reports->where('status', $query->status))
            ->when($query->employeeId !== null, fn ($reports) => $reports->where(fn ($inner) => $inner->where('employee_id', $query->employeeId)->orWhere('user_id', $query->employeeId)))
            ->when($query->periodFrom !== null, fn ($reports) => $reports->whereDate('period_end', '>=', $query->periodFrom))
            ->when($query->periodTo !== null, fn ($reports) => $reports->whereDate('period_start', '<=', $query->periodTo))
            ->when($query->search !== null && $query->search !== '', function ($reports) use ($query): void {
                $term = '%'.mb_strtolower((string) $query->search).'%';
                $reports->where(fn ($inner) => $inner->whereRaw('LOWER(report_number) LIKE ?', [$term])->orWhereRaw('LOWER(COALESCE(title, \'\')) LIKE ?', [$term]));
            })
            ->orderByDesc('period_start')
            ->orderByDesc('report_number')
            ->paginate($query->perPage);
    }
}
