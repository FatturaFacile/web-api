<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Application\Queries;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\ExpenseManagement\Domain\Enums\ReportStatus;
use Modules\ExpenseManagement\Models\ExpenseLine;

final class GetExpensePolicyViolationsHandler
{
    /**
     * Lines of live reports with at least one violation (of the severity asked). The JSON column is filtered in PHP so the
     * query stays valid on SQLite and PostgreSQL.
     *
     * @return LengthAwarePaginator<int, ExpenseLine>
     */
    public function handle(GetExpensePolicyViolationsQuery $query): LengthAwarePaginator
    {
        $lines = ExpenseLine::query()
            ->with(['report.claimant:id,name', 'category'])
            ->whereNotNull('policy_violations')
            ->whereHas('report', function ($reports) use ($query): void {
                $reports->where('status', '!=', ReportStatus::Cancelled->value)
                    ->when($query->reportId !== null, fn ($inner) => $inner->where('id', $query->reportId))
                    ->when($query->restrictToUserId !== null, fn ($inner) => $inner->where('user_id', $query->restrictToUserId))
                    ->when($query->employeeId !== null, fn ($inner) => $inner->where(fn ($who) => $who->where('employee_id', $query->employeeId)->orWhere('user_id', $query->employeeId)));
            })
            ->orderByDesc('expense_date')
            ->limit(2000)
            ->get()
            ->map(function (ExpenseLine $line) use ($query): ExpenseLine {
                $violations = array_values(array_filter(
                    $line->policy_violations ?? [],
                    static fn (array $violation): bool => $query->severity === null || ($violation['severity'] ?? null) === $query->severity,
                ));
                $line->setAttribute('filtered_violations', $violations);

                return $line;
            })
            ->filter(static fn (ExpenseLine $line): bool => $line->getAttribute('filtered_violations') !== [])
            ->values();

        $page = max(1, $query->page);

        return new LengthAwarePaginator($lines->forPage($page, $query->perPage)->values(), $lines->count(), $query->perPage, $page);
    }
}
