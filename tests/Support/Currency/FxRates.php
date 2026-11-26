<?php

declare(strict_types=1);

namespace Tests\Support\Currency;

use Modules\Treasury\Currency\Domain\Contracts\RateTableLoader;
use Modules\Treasury\Currency\Domain\Entities\ExchangeRate;
use Modules\Treasury\Currency\Domain\Entities\ExchangeRateTable;
use Modules\Treasury\Currency\Domain\Enums\RateSource;
use Modules\Treasury\Currency\Domain\Enums\RateType;
use Modules\Treasury\Currency\Domain\Services\RateSelector;
use Modules\Treasury\Currency\Domain\ValueObjects\CurrencyPair;
use Modules\Treasury\Currency\Domain\ValueObjects\ExchangeRateValue;
use Modules\Treasury\Currency\Domain\ValueObjects\RateQuote;

/**
 * Builders of rates for the currency tests: domain entities, an in-memory table loader (no database, no network) and provider
 * quotes.
 */
final class FxRates
{
    public static function rate(
        string $base,
        string $quote,
        string $rate,
        string $day,
        RateType $type = RateType::Mid,
        ?string $tenantId = null,
        bool $derived = false,
        ?string $supersededBy = null,
        ?string $contractReference = null,
        RateSource $source = RateSource::Ecb,
    ): ExchangeRate {
        return new ExchangeRate(
            id: $base.$quote.$day.$type->value.($tenantId ?? 'official'),
            pair: CurrencyPair::of($base, $quote),
            rate: ExchangeRateValue::of($rate),
            type: $type,
            source: $source,
            validOn: $day,
            isDerived: $derived,
            supersededBy: $supersededBy,
            tenantId: $tenantId,
            reason: null,
            contractReference: $contractReference,
        );
    }

    /**
     * @param  list<ExchangeRate>  $rates
     */
    public static function loader(array $rates): RateTableLoader
    {
        return new class($rates) implements RateTableLoader
        {
            /**
             * @param  list<ExchangeRate>  $rates
             */
            public function __construct(private readonly array $rates) {}

            public function load(array $currencies, string $from, string $to, ?string $tenantId, array $types): ExchangeRateTable
            {
                $currencies = array_map('strtoupper', $currencies);

                return new ExchangeRateTable(array_values(array_filter(
                    $this->rates,
                    static fn (ExchangeRate $rate): bool => in_array($rate->pair->base->value, $currencies, true)
                        && in_array($rate->pair->quote->value, $currencies, true)
                        && in_array($rate->type, $types, true)
                        && $rate->validOn >= $from && $rate->validOn <= $to
                        && $rate->isVisibleTo($tenantId),
                )));
            }
        };
    }

    /**
     * @param  list<ExchangeRate>  $rates
     */
    public static function selector(array $rates): RateSelector
    {
        return new RateSelector(self::loader($rates));
    }

    public static function quote(string $base, string $quote, string $rate, string $day, RateSource $source = RateSource::Ecb, RateType $type = RateType::Mid): RateQuote
    {
        return new RateQuote(CurrencyPair::of($base, $quote), ExchangeRateValue::of($rate), $day, $type, $source);
    }
}
