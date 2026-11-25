<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Services;

use App\Support\Database\TransactionLock;
use Illuminate\Support\Facades\DB;

/**
 * Progressive report numbers per company (legal entity) and year (ER-2028-0001), taken under a transaction lock so two
 * concurrent requests never read the same last number. The unique index (tenant, company, number) is the final guard.
 */
final class ExpenseNumbers
{
    public function next(string $tenantId, ?string $companyId): string
    {
        $year = now()->format('Y');

        TransactionLock::acquire('exm_reports:'.$tenantId.':'.($companyId ?? '-'));

        $query = DB::table('exm_reports')->where('tenant_id', $tenantId)->where('report_number', 'like', "ER-{$year}-%");
        $companyId === null ? $query->whereNull('company_id') : $query->where('company_id', $companyId);
        $last = $query->max('report_number');
        $sequence = $last === null ? 1 : ((int) substr((string) $last, -4)) + 1;

        return sprintf('ER-%s-%04d', $year, $sequence);
    }
}
