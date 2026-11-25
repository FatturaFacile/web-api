<?php

declare(strict_types=1);

namespace Modules\Treasury\Services\Reconciliation;

use App\Models\ExchangeRate;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;

/**
 * Exchange between two currencies at the rate published on or before a date, through the accounting currency of the
 * tenant (rates are stored as units of the foreign currency per unit of the accounting currency). Exact decimals only.
 */
final class CurrencyConverter
{
    /**
     * @var array<string, string|null>
     */
    private array $cache = [];

    /**
     * Rate to multiply an amount in `$from` by to get the amount in `$to`, null when a rate is missing.
     */
    public function rate(string $tenantId, string $from, string $to, string $date): ?string
    {
        if ($from === $to) {
            return '1';
        }

        $base = TenantAccountingCurrency::of($tenantId);
        $perBaseFrom = $this->unitsPerBase($base, $from, $date);
        $perBaseTo = $this->unitsPerBase($base, $to, $date);

        if ($perBaseFrom === null || $perBaseTo === null) {
            return null;
        }

        return bcdiv($perBaseTo, $perBaseFrom, 10);
    }

    /**
     * Half-up rounding of a decimal string to cents.
     */
    public static function round2(string $value): string
    {
        return bccomp($value, '0', 10) >= 0 ? bcadd($value, '0.005', 2) : bcsub($value, '0.005', 2);
    }

    private function unitsPerBase(string $base, string $currency, string $date): ?string
    {
        if ($currency === $base) {
            return '1';
        }

        $key = "{$base}|{$currency}|{$date}";

        if (! array_key_exists($key, $this->cache)) {
            $rate = ExchangeRate::query()->where('source', $base)->where('target', $currency)->whereDate('effective_date', '<=', $date)->latest('effective_date')->value('rate');
            $this->cache[$key] = $rate !== null && bccomp((string) $rate, '0', 10) === 1 ? (string) $rate : null;
        }

        return $this->cache[$key];
    }
}
