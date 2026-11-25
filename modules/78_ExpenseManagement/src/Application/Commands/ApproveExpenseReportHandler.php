<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Application\Commands;

use Modules\ExpenseManagement\Events\ExpenseReportApprovedEvent;
use Modules\ExpenseManagement\Models\ExpenseReport;
use Modules\ExpenseManagement\Services\ReportService;

final class ApproveExpenseReportHandler
{
    public function __construct(private readonly ReportService $reports) {}

    public function handle(ApproveExpenseReportCommand $command): ExpenseReport
    {
        $report = $this->reports->approve(ExpenseReport::query()->findOrFail($command->reportId), $command->userId, $command->approvedAmount, $command->comment);
        event(new ExpenseReportApprovedEvent((string) $report->id, $report->report_number, $report->user_id, $report->approved_amount, (string) $report->tenant_id));

        return $report;
    }
}
