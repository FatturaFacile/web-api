<?php

declare(strict_types=1);

namespace Modules\AnalyticsDss\Infrastructure\Support;

use Illuminate\Support\Facades\DB;

/**
 * Daily FX rate lookup on `exchange_rates` (SNB/ECB feeds): the last rate on or before the day, direct, inverse or crossed
 * through EUR. Rates of the tenant win over global ones. Results are memoized per instance, so a projection run reads each
 * (pair, day) once.
 */
final class FxRateResolver
{
    /** @var array<string, float|null> */
    private array $memo = [];

    /**
     * Rate to multiply an amount in `$from` with to get `$to`, or null when no rate is known.
     */
    public function rate(string $tenantId, string $from, string $to, string $date): ?float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return 1.0;
        }

        $key = "{$tenantId}|{$from}|{$to}|{$date}";

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $rate = $this->direct($tenantId, $from, $to, $date);

        if ($rate === null && $from !== 'EUR' && $to !== 'EUR') {
            $leg1 = $this->direct($tenantId, $from, 'EUR', $date);
            $leg2 = $this->direct($tenantId, 'EUR', $to, $date);
            $rate = $leg1 !== null && $leg2 !== null ? $leg1 * $leg2 : null;
        }

        return $this->memo[$key] = $rate;
    }

    public function convert(string $tenantId, float $amount, string $from, string $to, string $date): ?float
    {
        $rate = $this->rate($tenantId, $from, $to, $date);

        return $rate === null ? null : round($amount * $rate, 4);
    }

    private function direct(string $tenantId, string $from, string $to, string $date): ?float
    {
        $found = $this->latest($tenantId, $from, $to, $date);

        if ($found !== null) {
            return $found;
        }

        $inverse = $this->latest($tenantId, $to, $from, $date);

        return $inverse !== null && $inverse > 0.0 ? 1.0 / $inverse : null;
    }

    private function latest(string $tenantId, string $source, string $target, string $date): ?float
    {
        $row = DB::table('exchange_rates')
            ->where('source', $source)
            ->where('target', $target)
            ->where('effective_date', '<=', $date)
            ->whereNull('deleted_at')
            ->where(static fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            ->orderByDesc('effective_date')
            ->orderByRaw('CASE WHEN tenant_id IS NULL THEN 1 ELSE 0 END')
            ->first(['rate']);

        return $row !== null ? (float) $row->rate : null;
    }
}
