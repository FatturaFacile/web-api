<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Application\Commands;

use Modules\ExpenseManagement\Models\ExpensePayout;
use Modules\ExpenseManagement\Models\ExpenseReport;
use Modules\ExpenseManagement\Services\PayoutService;

final class ExportExpenseToPayrollOrPain001Handler
{
    public function __construct(private readonly PayoutService $payouts) {}

    public function handle(ExportExpenseToPayrollOrPain001Command $command): ExpensePayout
    {
        return $this->payouts->export(ExpenseReport::query()->findOrFail($command->reportId), $command->channel, $command->executionDate, $command->userId);
    }
}
