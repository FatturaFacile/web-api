<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Application\Commands;

use Illuminate\Support\Facades\DB;
use Modules\ExpenseManagement\Models\ExpenseLine;
use Modules\ExpenseManagement\Models\ExpenseReport;
use Modules\ExpenseManagement\Services\ExpenseLineService;
use Modules\ExpenseManagement\Services\ReportService;

final class UpdateExpenseLineHandler
{
    public function __construct(private readonly ExpenseLineService $lines, private readonly ReportService $reports) {}

    public function handle(UpdateExpenseLineCommand $command): ExpenseLine
    {
        $report = ExpenseReport::query()->findOrFail($command->reportId);
        $this->reports->assertEditable($report);
        $line = ExpenseLine::query()->where('expense_report_id', $report->id)->findOrFail($command->lineId);

        return DB::transaction(function () use ($report, $line, $command): ExpenseLine {
            $line = $this->lines->save($report, $line, $command->data);
            $this->reports->recalculate($report);
            $this->reports->audit($report, 'line_updated', $command->userId, null, null, ['amount' => $line->amount_in_base_currency], (string) $line->id);

            return $line;
        });
    }
}
