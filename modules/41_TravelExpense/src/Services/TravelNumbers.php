<?php

declare(strict_types=1);

namespace Modules\TravelExpense\Services;

use App\Support\Database\TransactionLock;
use Illuminate\Support\Facades\DB;

/**
 * Progressive trip numbers per company and year (TR-2027-0001), taken under a transaction lock so two concurrent
 * requests never read the same last number. The unique index (tenant, number) is the final guard.
 */
final class TravelNumbers
{
    public function next(string $tenantId): string
    {
        $year = now()->format('Y');

        TransactionLock::acquire("travel_trips:{$tenantId}");

        $last = DB::table('travel_trips')->where('tenant_id', $tenantId)->where('number', 'like', "TR-{$year}-%")->max('number');
        $sequence = $last === null ? 1 : ((int) substr((string) $last, -4)) + 1;

        return sprintf('TR-%s-%04d', $year, $sequence);
    }
}
