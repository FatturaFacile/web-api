<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Application\Commands;

use Modules\ExpenseManagement\Events\ExpenseReportRejectedEvent;
use Modules\ExpenseManagement\Models\ExpenseReport;
use Modules\ExpenseManagement\Services\ReportService;

final class RejectExpenseReportHandler
{
    public function __construct(private readonly ReportService $reports) {}

    public function handle(RejectExpenseReportCommand $command): ExpenseReport
    {
        $report = $this->reports->reject(ExpenseReport::query()->findOrFail($command->reportId), $command->userId, $command->reason);
        event(new ExpenseReportRejectedEvent((string) $report->id, $report->report_number, $report->user_id, $command->reason, (string) $report->tenant_id));

        return $report;
    }
}
