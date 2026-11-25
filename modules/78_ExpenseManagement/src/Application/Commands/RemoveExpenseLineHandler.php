<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Application\Commands;

use Illuminate\Support\Facades\DB;
use Modules\ExpenseManagement\Domain\Enums\ReconciliationStatus;
use Modules\ExpenseManagement\Models\CorporateCardTransaction;
use Modules\ExpenseManagement\Models\ExpenseLine;
use Modules\ExpenseManagement\Models\ExpenseReport;
use Modules\ExpenseManagement\Services\ReportService;

final class RemoveExpenseLineHandler
{
    public function __construct(private readonly ReportService $reports) {}

    public function handle(RemoveExpenseLineCommand $command): ExpenseReport
    {
        $report = ExpenseReport::query()->findOrFail($command->reportId);
        $this->reports->assertEditable($report);
        $line = ExpenseLine::query()->where('expense_report_id', $report->id)->findOrFail($command->lineId);

        return DB::transaction(function () use ($report, $line, $command): ExpenseReport {
            $transactionId = $line->corporate_card_transaction_id;
            $line->mileageClaim()->delete();
            $line->delete();

            if ($transactionId !== null) {
                $transaction = CorporateCardTransaction::query()->find($transactionId);
                $matched = (string) ExpenseLine::query()->where('corporate_card_transaction_id', $transactionId)->sum('amount_in_base_currency');
                $transaction?->update(['matched_amount' => $matched, 'status' => ReconciliationStatus::Unmatched]);
            }

            $this->reports->recalculate($report);
            $this->reports->audit($report, 'line_removed', $command->userId, null, null, [], $command->lineId);

            return $report;
        });
    }
}
