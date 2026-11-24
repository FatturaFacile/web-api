<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Services;

use Illuminate\Support\Facades\DB;

/**
 * Rate between two currencies for the reporting of campaigns (CHF / EUR): the latest tenant rate, else the latest
 * generic one, else the inverse of the opposite pair. Read straight from the table so that it also works from the
 * public tracking endpoints and queue jobs, which run without a tenant context.
 */
final class CurrencyRateService
{
    public function rate(string $tenantId, string $from, string $to): float
    {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));
        if ($from === '' || $to === '' || $from === $to) {
            return 1.0;
        }

        $direct = $this->latest($tenantId, $from, $to);
        if ($direct !== null && $direct > 0) {
            return $direct;
        }

        $inverse = $this->latest($tenantId, $to, $from);

        return $inverse !== null && $inverse > 0 ? 1 / $inverse : 1.0;
    }

    private function latest(string $tenantId, string $source, string $target): ?float
    {
        $rate = DB::table('exchange_rates')
            ->where('source', $source)->where('target', $target)->whereNull('deleted_at')
            ->where(static fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            ->orderByDesc('effective_date')
            ->orderByRaw('CASE WHEN tenant_id IS NOT NULL THEN 0 ELSE 1 END')
            ->value('rate');

        return $rate === null ? null : (float) $rate;
    }
}
