<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Services;

use Carbon\CarbonImmutable;
use Modules\Treasury\Currency\Domain\Contracts\RateTableLoader;
use Modules\Treasury\Currency\Domain\Entities\ExchangeRate;
use Modules\Treasury\Currency\Domain\Entities\ExchangeRateTable;
use Modules\Treasury\Currency\Domain\Entities\ResolvedRate;
use Modules\Treasury\Currency\Domain\Enums\RateSource;
use Modules\Treasury\Currency\Domain\Enums\RateType;
use Modules\Treasury\Currency\Domain\Exceptions\ExchangeRateMissingException;
use Modules\Treasury\Currency\Domain\Support\Dec;
use Modules\Treasury\Currency\Domain\ValueObjects\CurrencyPair;
use Modules\Treasury\Currency\Domain\ValueObjects\ExchangeRateValue;

/**
 * Chooses the rate of a pair on a day: the rate of the day, or the latest earlier one up to five days back (weekends and
 * holidays), directly, by inversion of the opposite pair, or as a cross rate through the pivot currency. Beyond the window the
 * answer is the blocking `exchange_rate_missing`: a rate is never invented.
 *
 * - Contract rates (fixed for a contract, an order or a document) are chosen only when asked for, by reference.
 * - ESTV monthly averages (Swiss VAT) refer to a calendar month: the rate of the month of the day, stored on its first day.
 */
final readonly class RateSelector
{
    public const int WINDOW_DAYS = 5;

    public const string PIVOT = 'EUR';

    public function __construct(
        private RateTableLoader $loader,
        private CrossRateCalculator $cross = new CrossRateCalculator,
        private int $windowDays = self::WINDOW_DAYS,
    ) {}

    /**
     * @throws ExchangeRateMissingException
     */
    public function select(CurrencyPair $pair, string $date, ?string $tenantId, RateType $type = RateType::Mid, ?string $contractReference = null): ResolvedRate
    {
        $date = CarbonImmutable::parse($date)->toDateString();

        if ($pair->isIdentity()) {
            return new ResolvedRate($pair, ExchangeRateValue::one(), $type, RateSource::Manual, $date, $date, false, false, null);
        }

        if ($type === RateType::Contract || $contractReference !== null) {
            $contract = $this->contract($pair, $date, $tenantId, $contractReference);

            if ($contract !== null) {
                return $contract;
            }

            if ($type === RateType::Contract) {
                throw new ExchangeRateMissingException($pair, $date, $this->windowDays);
            }

            $type = RateType::Mid;
        }

        $windowStart = $type === RateType::VatEstvMonthly
            ? CarbonImmutable::parse($date)->startOfMonth()->toDateString()
            : CarbonImmutable::parse($date)->subDays($this->windowDays)->toDateString();

        $table = $this->loader->load([$pair->base->value, $pair->quote->value, self::PIVOT], $windowStart, $date, $tenantId, [$type]);

        $candidates = array_values(array_filter([
            $this->direct($table, $pair, $type, $date, $windowStart, $tenantId),
            $this->inverse($table, $pair, $type, $date, $windowStart, $tenantId),
            $this->viaPivot($table, $pair, $type, $date, $windowStart, $tenantId),
        ]));

        if ($candidates === []) {
            throw new ExchangeRateMissingException($pair, $date, $this->windowDays);
        }

        $best = $candidates[0];

        foreach ($candidates as $candidate) {
            // Freshest day wins; at equal days the order direct, inverse, cross is kept (first found stays).
            if ($candidate->validOn > $best->validOn) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private function direct(ExchangeRateTable $table, CurrencyPair $pair, RateType $type, string $date, string $windowStart, ?string $tenantId): ?ResolvedRate
    {
        $rate = $table->latestOnOrBefore($pair, $type, $date, $windowStart, $tenantId);

        return $rate === null ? null : $this->resolved($pair, $rate->rate, $rate, $date, $rate->isDerived, false);
    }

    private function inverse(ExchangeRateTable $table, CurrencyPair $pair, RateType $type, string $date, string $windowStart, ?string $tenantId): ?ResolvedRate
    {
        $rate = $table->latestOnOrBefore($pair->inverse(), $type, $date, $windowStart, $tenantId);

        return $rate === null ? null : $this->resolved($pair, $rate->rate->inverse(), $rate, $date, true, false);
    }

    private function viaPivot(ExchangeRateTable $table, CurrencyPair $pair, RateType $type, string $date, string $windowStart, ?string $tenantId): ?ResolvedRate
    {
        $pivot = self::PIVOT;

        if ($pair->base->value === $pivot || $pair->quote->value === $pivot) {
            return null;
        }

        foreach ($table->days($type, $windowStart, $date, $tenantId) as $day) {
            $a = $this->pivotUnits($table, $pair->base->value, $type, $day, $tenantId);
            $b = $this->pivotUnits($table, $pair->quote->value, $type, $day, $tenantId);

            if ($a !== null && $b !== null) {
                $value = $this->cross->cross($a['units'], $b['units']);
                $source = $a['rate']->source === $b['rate']->source ? $a['rate']->source : RateSource::Manual;
                $anchor = new ExchangeRate(null, $pair, $value, $type, $source, $day, true, null, null);

                return $this->resolved($pair, $value, $anchor, $date, true, false);
            }
        }

        return null;
    }

    /**
     * Pivot units for one unit of a currency on exactly a day, full precision, from the direct or the opposite pair.
     *
     * @return array{units: string, rate: ExchangeRate}|null
     */
    private function pivotUnits(ExchangeRateTable $table, string $currency, RateType $type, string $day, ?string $tenantId): ?array
    {
        $toPivot = CurrencyPair::of($currency, self::PIVOT);
        $direct = $table->onDay($toPivot, $type, $day, $tenantId);

        if ($direct !== null) {
            return ['units' => $direct->rate->value, 'rate' => $direct];
        }

        $opposite = $table->onDay($toPivot->inverse(), $type, $day, $tenantId);

        return $opposite === null ? null : ['units' => Dec::div('1', $opposite->rate->value), 'rate' => $opposite];
    }

    private function contract(CurrencyPair $pair, string $date, ?string $tenantId, ?string $reference): ?ResolvedRate
    {
        $table = $this->loader->load([$pair->base->value, $pair->quote->value], '0001-01-01', $date, $tenantId, [RateType::Contract]);
        $direct = $table->contractRate($pair, $date, $tenantId, $reference);

        if ($direct !== null) {
            return $this->resolved($pair, $direct->rate, $direct, $date, false, true);
        }

        $inverse = $table->contractRate($pair->inverse(), $date, $tenantId, $reference);

        return $inverse === null ? null : $this->resolved($pair, $inverse->rate->inverse(), $inverse, $date, true, true);
    }

    private function resolved(CurrencyPair $pair, ExchangeRateValue $value, ExchangeRate $source, string $date, bool $derived, bool $contract): ResolvedRate
    {
        return new ResolvedRate($pair, $value, $source->type, $source->source, $source->validOn, $date, $derived, $contract, $source->id);
    }
}
