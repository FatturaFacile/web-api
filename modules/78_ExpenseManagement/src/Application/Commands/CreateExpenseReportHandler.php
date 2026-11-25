<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Application\Commands;

use Modules\ExpenseManagement\Models\ExpenseReport;
use Modules\ExpenseManagement\Services\ReportService;

final class CreateExpenseReportHandler
{
    public function __construct(private readonly ReportService $reports) {}

    public function handle(CreateExpenseReportCommand $command): ExpenseReport
    {
        return $this->reports->create($command->data, $command->tenantId, $command->userId);
    }
}
