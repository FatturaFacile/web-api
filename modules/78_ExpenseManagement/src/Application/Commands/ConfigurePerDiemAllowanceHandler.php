<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Application\Commands;

use Illuminate\Support\Facades\DB;
use Modules\ExpenseManagement\Models\ExpenseReport;
use Modules\ExpenseManagement\Models\PerDiemAllowance;
use Modules\ExpenseManagement\Services\PerDiemService;
use Modules\ExpenseManagement\Services\ReportService;

final class ConfigurePerDiemAllowanceHandler
{
    public function __construct(private readonly PerDiemService $perDiems, private readonly ReportService $reports) {}

    public function handle(ConfigurePerDiemAllowanceCommand $command): PerDiemAllowance
    {
        $report = ExpenseReport::query()->findOrFail($command->reportId);
        $this->reports->assertEditable($report);

        return DB::transaction(function () use ($report, $command): PerDiemAllowance {
            $allowance = $this->perDiems->add($report, $command->data);
            $this->reports->recalculate($report);
            $this->reports->audit($report, 'per_diem_added', $command->userId, null, null, ['gross' => $allowance->gross_allowance_amount, 'taxable_excess' => $allowance->taxable_excess_amount]);

            return $allowance;
        });
    }
}
