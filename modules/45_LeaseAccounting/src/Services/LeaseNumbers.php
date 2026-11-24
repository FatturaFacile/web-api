<?php

declare(strict_types=1);

namespace Modules\LeaseAccounting\Services;

use App\Support\Database\TransactionLock;
use Illuminate\Support\Facades\DB;

/**
 * Progressive lease numbers per company and year (LS-2027-0001), taken under a transaction-level lock so two
 * concurrent requests never read the same last number. A unique index (tenant, number) is the final guard.
 */
final class LeaseNumbers
{
    public function next(string $tenantId): string
    {
        $year = now()->format('Y');

        TransactionLock::acquire("lease_contracts:{$tenantId}");

        $last = DB::table('lease_contracts')->where('tenant_id', $tenantId)->where('number', 'like', "LS-{$year}-%")->max('number');
        $sequence = $last === null ? 1 : ((int) substr((string) $last, -4)) + 1;

        return sprintf('LS-%s-%04d', $year, $sequence);
    }
}
