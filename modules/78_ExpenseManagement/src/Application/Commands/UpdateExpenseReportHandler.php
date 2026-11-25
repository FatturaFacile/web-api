<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Application\Commands;

use Modules\ExpenseManagement\Models\ExpenseReport;
use Modules\ExpenseManagement\Services\ReportService;

final class UpdateExpenseReportHandler
{
    public function __construct(private readonly ReportService $reports) {}

    public function handle(UpdateExpenseReportCommand $command): ExpenseReport
    {
        return $this->reports->update(ExpenseReport::query()->findOrFail($command->reportId), $command->data, $command->userId);
    }
}
