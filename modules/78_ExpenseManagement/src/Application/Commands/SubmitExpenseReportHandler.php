<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Application\Commands;

use Modules\ExpenseManagement\Models\ExpenseReport;
use Modules\ExpenseManagement\Services\ReportService;

final class SubmitExpenseReportHandler
{
    public function __construct(private readonly ReportService $reports) {}

    public function handle(SubmitExpenseReportCommand $command): ExpenseReport
    {
        return $this->reports->submit(ExpenseReport::query()->findOrFail($command->reportId), $command->userId);
    }
}
