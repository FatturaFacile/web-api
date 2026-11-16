<?php

declare(strict_types=1);

namespace Modules\Quality\Services;

use App\Support\Database\TransactionLock;
use Illuminate\Support\Facades\DB;

/**
 * Progressive numbers per company and year (NC-2027-0001), taken under a transaction-level advisory lock on PostgreSQL
 * so two concurrent requests never read the same last number. A unique index (tenant, number) is the final guard.
 */
final class QualityNumbers
{
    public function next(string $table, string $prefix, string $tenantId): string
    {
        $year = now()->format('Y');
        $like = "{$prefix}-{$year}-%";

        TransactionLock::acquire("{$table}:{$tenantId}");

        $last = DB::table($table)->where('tenant_id', $tenantId)->where('number', 'like', $like)->max('number');
        $sequence = $last === null ? 1 : ((int) substr((string) $last, -4)) + 1;

        return sprintf('%s-%s-%04d', $prefix, $year, $sequence);
    }
}
