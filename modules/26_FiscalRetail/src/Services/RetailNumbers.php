<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Services;

use App\Support\Database\TransactionLock;
use Illuminate\Support\Facades\DB;

/**
 * Progressive numbers per company and year (RET-2027-0001, Z-2027-0001) taken under a transaction lock; the unique
 * index (tenant, number) is the final guard.
 */
final class RetailNumbers
{
    public function next(string $table, string $prefix, string $tenantId): string
    {
        $year = now()->format('Y');

        TransactionLock::acquire("{$table}:{$tenantId}");

        $last = DB::table($table)->where('tenant_id', $tenantId)->where('number', 'like', "{$prefix}-{$year}-%")->max('number');
        $sequence = $last === null ? 1 : ((int) substr((string) $last, -4)) + 1;

        return sprintf('%s-%s-%04d', $prefix, $year, $sequence);
    }
}
