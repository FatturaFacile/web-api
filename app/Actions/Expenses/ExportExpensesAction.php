<?php

declare(strict_types=1);

namespace App\Actions\Expenses;

use App\Events\Expenses\ExpensesExportedToAccountant;
use App\Models\Expense;
use App\Models\Tenant;
use App\Services\Expenses\ExpenseExportService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ExportExpensesAction
{
    public function __construct(
        private readonly ExpenseExportService $exportService,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Export expenses to accountant CSV/JSON format and optionally stamp accounting export timestamp.
     *
     * @param  array<string, mixed>  $filters
     * @return array{content: string, filename: string, count: int, total_amount: float}
     */
    public function execute(array $filters): array
    {
        /** @var Collection<int, Expense> $expenses */
        $expenses = $this->exportService->queryExpenses($filters);

        $totalAmount = (float) $expenses->sum('amount_gross');
        $count = $expenses->count();
        $dateStamp = now()->format('Ymd_His');
        $filename = "expenses_export_{$dateStamp}.csv";
        $csvContent = $this->exportService->generateCsv($expenses);

        $markAsExported = (bool) ($filters['mark_as_exported'] ?? true);

        if ($markAsExported && $count > 0) {
            DB::transaction(function () use ($expenses): void {
                $now = now();
                Expense::whereIn('id', $expenses->pluck('id'))->update([
                    'accounting_exported_at' => $now,
                ]);
            });
        }

        $tenant = Tenant::findOrFail($this->tenantContext->getTenantId());
        event(new ExpensesExportedToAccountant(
            tenant: $tenant,
            actor: Auth::user(),
            expenseIds: $expenses->pluck('id')->all(),
            totalCount: $count,
            totalAmount: round($totalAmount, 2),
            filterCriteria: $filters
        ));

        return [
            'content' => $csvContent,
            'filename' => $filename,
            'count' => $count,
            'total_amount' => round($totalAmount, 2),
        ];
    }
}
