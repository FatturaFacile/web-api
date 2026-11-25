<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Services;

use Illuminate\Support\Facades\DB;
use Modules\ExpenseManagement\Models\ExpenseReport;
use Modules\ExpenseManagement\Models\PerDiemAllowance;

/**
 * Removal of a per-diem allowance with the recalculation of the report and its audit entry.
 */
final class PerDiemTrail
{
    public function __construct(private readonly ReportService $reports) {}

    public function remove(ExpenseReport $report, string $perDiemId, string $userId): void
    {
        $this->reports->assertEditable($report);
        $allowance = PerDiemAllowance::query()->where('expense_report_id', $report->id)->findOrFail($perDiemId);

        DB::transaction(function () use ($report, $allowance, $userId): void {
            $allowance->delete();
            $this->reports->recalculate($report);
            $this->reports->audit($report, 'per_diem_removed', $userId);
        });
    }
}
