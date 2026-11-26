<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Entities;

use Modules\Treasury\Currency\Domain\Enums\RateType;
use Modules\Treasury\Currency\Domain\ValueObjects\CurrencyPair;

/**
 * The rates visible to a company for some currencies and days (aggregate over {@see ExchangeRate}): official rates and the
 * company's own, superseded versions left out. It answers which rate applies, never inventing one.
 */
final class ExchangeRateTable
{
    /** Preference among the publishers of a rate for the same pair, type and day (lower is preferred). */
    private const array PUBLISHER_ORDER = ['manual' => 0, 'ecb' => 1, 'snb' => 2, 'estv' => 3];

    /** @var list<ExchangeRate> */
    private array $rates = [];

    /**
     * @param  iterable<ExchangeRate>  $rates
     */
    public function __construct(iterable $rates = [])
    {
        foreach ($rates as $rate) {
            $this->add($rate);
        }
    }

    public function add(ExchangeRate $rate): void
    {
        $this->rates[] = $rate;
    }

    /**
     * @return list<ExchangeRate>
     */
    public function all(): array
    {
        return $this->rates;
    }

    /**
     * The latest rate of the pair and type on or before a day and not before the window start; at equal days the company's
     * own rate wins over the official one, and among official rates the publisher order manual, ECB, SNB, ESTV decides.
     */
    public function latestOnOrBefore(CurrencyPair $pair, RateType $type, string $date, string $windowStart, ?string $tenantId): ?ExchangeRate
    {
        $best = null;

        foreach ($this->rates as $rate) {
            if (! $this->matches($rate, $pair, $type, $tenantId) || $rate->validOn > $date || $rate->validOn < $windowStart) {
                continue;
            }

            if ($best === null || $this->isBetter($rate, $best)) {
                $best = $rate;
            }
        }

        return $best;
    }

    /**
     * The rate of the pair and type valid exactly on a day.
     */
    public function onDay(CurrencyPair $pair, RateType $type, string $date, ?string $tenantId): ?ExchangeRate
    {
        return $this->latestOnOrBefore($pair, $type, $date, $date, $tenantId);
    }

    /**
     * Distinct days (latest first) with a rate of a type between two days, whatever the pair.
     *
     * @return list<string>
     */
    public function days(RateType $type, string $from, string $to, ?string $tenantId): array
    {
        $days = [];

        foreach ($this->rates as $rate) {
            if ($rate->type === $type && ! $rate->isSuperseded() && $rate->isVisibleTo($tenantId) && $rate->validOn >= $from && $rate->validOn <= $to) {
                $days[$rate->validOn] = true;
            }
        }

        $keys = array_keys($days);
        rsort($keys);

        return $keys;
    }

    /**
     * The contract rate of a reference (the latest version in force on a day), whatever its age.
     */
    public function contractRate(CurrencyPair $pair, string $date, ?string $tenantId, ?string $reference): ?ExchangeRate
    {
        $best = null;

        foreach ($this->rates as $rate) {
            if (! $this->matches($rate, $pair, RateType::Contract, $tenantId) || $rate->tenantId === null || $rate->validOn > $date) {
                continue;
            }

            if ($reference !== null && $rate->contractReference !== $reference) {
                continue;
            }

            if ($best === null || $rate->validOn > $best->validOn) {
                $best = $rate;
            }
        }

        return $best;
    }

    private function isBetter(ExchangeRate $candidate, ExchangeRate $best): bool
    {
        if ($candidate->validOn !== $best->validOn) {
            return $candidate->validOn > $best->validOn;
        }

        if (($candidate->tenantId !== null) !== ($best->tenantId !== null)) {
            return $candidate->tenantId !== null;
        }

        return self::PUBLISHER_ORDER[$candidate->source->value] < self::PUBLISHER_ORDER[$best->source->value];
    }

    private function matches(ExchangeRate $rate, CurrencyPair $pair, RateType $type, ?string $tenantId): bool
    {
        return $rate->type === $type && ! $rate->isSuperseded() && $rate->isVisibleTo($tenantId) && $rate->pair->equals($pair);
    }
}
