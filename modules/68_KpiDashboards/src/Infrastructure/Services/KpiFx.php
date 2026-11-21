<?php

declare(strict_types=1);

namespace Modules\KpiDashboards\Infrastructure\Services;

use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;

/**
 * Converts KPI amounts from the accounting currency of the tenant into the display currency (CHF or EUR) with the
 * published SNB / ECB rates held in `exchange_rates` (source = accounting currency, target = foreign currency, foreign
 * units per accounting unit). Flow metrics (P&L) use the average rate of the period, stock metrics (balance sheet) the
 * closing spot rate on the last day of the period.
 */
final class KpiFx
{
    /**
     * @return array{rate: float, kind: string, from: string, to: string, as_of: string|null, available: bool}
     */
    public function rate(string $tenantId, string $displayCurrency, CarbonImmutable $from, CarbonImmutable $to, bool $closing): array
    {
        $accounting = TenantAccountingCurrency::of($tenantId);
        $display = strtoupper($displayCurrency);
        $kind = $closing ? 'closing' : 'average';

        if ($display === $accounting) {
            return ['rate' => 1.0, 'kind' => $kind, 'from' => $accounting, 'to' => $display, 'as_of' => null, 'available' => true];
        }

        $published = $closing ? $this->closing($accounting, $display, $to) : $this->average($accounting, $display, $from, $to);

        if ($published === null) {
            return ['rate' => 1.0, 'kind' => $kind, 'from' => $accounting, 'to' => $display, 'as_of' => null, 'available' => false];
        }

        return ['rate' => $published['rate'], 'kind' => $kind, 'from' => $accounting, 'to' => $display, 'as_of' => $published['as_of'], 'available' => true];
    }

    /**
     * An amount of a document currency in the accounting currency, at the rate in force on the document date.
     */
    public function toAccounting(string $tenantId, string $currency, float $amount, CarbonImmutable $date): float
    {
        $accounting = TenantAccountingCurrency::of($tenantId);
        $currency = strtoupper($currency);

        if ($currency === $accounting || $currency === '') {
            return $amount;
        }

        $published = $this->closing($accounting, $currency, $date);

        return $published === null ? $amount : $amount / $published['rate'];
    }

    /**
     * @return array{rate: float, as_of: string}|null
     */
    private function closing(string $accounting, string $display, CarbonImmutable $to): ?array
    {
        $direct = ExchangeRate::query()->where('source', $accounting)->where('target', $display)
            ->whereDate('effective_date', '<=', $to->toDateString())->latest('effective_date')->first();

        if ($direct !== null && (float) $direct->rate > 0) {
            return ['rate' => (float) $direct->rate, 'as_of' => CarbonImmutable::parse($direct->effective_date)->toDateString()];
        }

        $inverse = ExchangeRate::query()->where('source', $display)->where('target', $accounting)
            ->whereDate('effective_date', '<=', $to->toDateString())->latest('effective_date')->first();

        return $inverse !== null && (float) $inverse->rate > 0
            ? ['rate' => 1 / (float) $inverse->rate, 'as_of' => CarbonImmutable::parse($inverse->effective_date)->toDateString()]
            : null;
    }

    /**
     * @return array{rate: float, as_of: string}|null
     */
    private function average(string $accounting, string $display, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        $inPeriod = fn (string $source, string $target) => ExchangeRate::query()->where('source', $source)->where('target', $target)
            ->whereDate('effective_date', '>=', $from->toDateString())->whereDate('effective_date', '<=', $to->toDateString());

        $direct = $inPeriod($accounting, $display)->avg('rate');

        if ($direct !== null && (float) $direct > 0) {
            return ['rate' => (float) $direct, 'as_of' => $to->toDateString()];
        }

        $inverseRates = $inPeriod($display, $accounting)->pluck('rate')->map(static fn (mixed $rate): float => 1 / max((float) $rate, 1e-12));

        if ($inverseRates->isNotEmpty()) {
            return ['rate' => (float) $inverseRates->avg(), 'as_of' => $to->toDateString()];
        }

        // No rate published inside the period: the closing rate is the best available estimate.
        return $this->closing($accounting, $display, $to);
    }
}
