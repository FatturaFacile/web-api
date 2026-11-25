<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Application\Queries;

use Modules\ExpenseManagement\Models\ExpenseReport;

final class GetExpenseReportDetailHandler
{
    public function handle(GetExpenseReportDetailQuery $query): ExpenseReport
    {
        return ExpenseReport::query()
            ->with(['claimant:id,name', 'lines.category', 'lines.mileageClaim', 'perDiems', 'auditLogs', 'payouts'])
            ->findOrFail($query->reportId);
    }
}
