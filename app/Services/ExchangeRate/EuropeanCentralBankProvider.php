<?php

declare(strict_types=1);

namespace App\Services\ExchangeRate;

use App\Contracts\ExchangeRateProviderInterface;
use Modules\Treasury\Currency\Domain\Exceptions\RateProviderException;
use Modules\Treasury\Currency\Infrastructure\Providers\EcbRateProvider;

/**
 * Backward-compatible adapter of the ECB feed for the legacy `settings/exchange-rates/sync` endpoint: it reads the feed through the
 * multi-currency engine (Modules\Treasury\Currency, Mod 003) and answers the latest day as source/target/rate triplets, with the
 * rate as a decimal string. There are no built-in reference rates: when the feed cannot be fetched or validated it throws
 * {@see RateProviderException} and nothing is synchronised.
 */
class EuropeanCentralBankProvider implements ExchangeRateProviderInterface
{
    public function __construct(private readonly ?EcbRateProvider $ecb = null) {}

    /**
     * @return array<int, array{source: string, target: string, rate: string}>
     *
     * @throws RateProviderException
     */
    public function getRates(string $baseCurrency = 'EUR'): array
    {
        $quotes = ($this->ecb ?? new EcbRateProvider)->fetch();
        $latest = max(array_map(static fn ($quote): string => $quote->validOn, $quotes));
        $rates = [];

        foreach ($quotes as $quote) {
            if ($quote->validOn === $latest) {
                $rates[] = ['source' => $quote->pair->base->value, 'target' => $quote->pair->quote->value, 'rate' => $quote->rate->value];
            }
        }

        return $rates;
    }
}
