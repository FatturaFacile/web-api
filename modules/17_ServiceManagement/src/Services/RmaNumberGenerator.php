<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services;

use Illuminate\Support\Facades\DB;

/**
 * Sequential RMA numbers per tenant and year: RMA-{YYYY}-{000123}, backed by a row-locked counter.
 */
final readonly class RmaNumberGenerator
{
    public function next(string $tenantId, ?int $year = null): string
    {
        $year ??= (int) now()->format('Y');

        return DB::transaction(function () use ($tenantId, $year): string {
            DB::table('esm_rma_counters')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'year' => $year,
                'last_value' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $counter = DB::table('esm_rma_counters')
                ->where('tenant_id', $tenantId)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            $next = (int) ($counter->last_value ?? 0) + 1;

            DB::table('esm_rma_counters')
                ->where('tenant_id', $tenantId)
                ->where('year', $year)
                ->update(['last_value' => $next, 'updated_at' => now()]);

            return sprintf('RMA-%04d-%06d', $year, $next);
        });
    }
}
