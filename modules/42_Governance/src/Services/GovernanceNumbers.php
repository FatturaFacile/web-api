<?php

declare(strict_types=1);

namespace Modules\Governance\Services;

use App\Support\Database\TransactionLock;
use Illuminate\Support\Facades\DB;

/**
 * Progressive numbers per company and year (RSK-2027-0001), taken under a transaction lock so two concurrent requests
 * never read the same last number. A unique index (tenant, code) is the final guard.
 */
final class GovernanceNumbers
{
    public function next(string $table, string $prefix, string $tenantId): string
    {
        $year = now()->format('Y');

        TransactionLock::acquire("{$table}:{$tenantId}");

        $last = DB::table($table)->where('tenant_id', $tenantId)->where('code', 'like', "{$prefix}-{$year}-%")->max('code');
        $sequence = $last === null ? 1 : ((int) substr((string) $last, -4)) + 1;

        return sprintf('%s-%s-%04d', $prefix, $year, $sequence);
    }
}
