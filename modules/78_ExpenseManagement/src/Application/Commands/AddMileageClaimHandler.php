<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Application\Commands;

use Illuminate\Support\Facades\DB;
use Modules\ExpenseManagement\Models\ExpenseLine;
use Modules\ExpenseManagement\Models\ExpenseReport;
use Modules\ExpenseManagement\Services\MileageService;
use Modules\ExpenseManagement\Services\ReportService;

final class AddMileageClaimHandler
{
    public function __construct(private readonly MileageService $mileage, private readonly ReportService $reports) {}

    public function handle(AddMileageClaimCommand $command): ExpenseLine
    {
        $report = ExpenseReport::query()->findOrFail($command->reportId);
        $this->reports->assertEditable($report);

        return DB::transaction(function () use ($report, $command): ExpenseLine {
            $line = $this->mileage->add($report, $command->data);
            $this->reports->recalculate($report);
            $this->reports->audit($report, 'mileage_added', $command->userId, null, null, ['amount' => $line->amount_in_base_currency], (string) $line->id);

            return $line;
        });
    }
}
