<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Infrastructure\Providers;

use InvalidArgumentException;
use Modules\Treasury\Currency\Domain\Enums\RateSource;
use Modules\Treasury\Currency\Domain\Enums\RateType;
use Modules\Treasury\Currency\Domain\Exceptions\RateProviderException;
use Modules\Treasury\Currency\Domain\Support\Dec;
use Modules\Treasury\Currency\Domain\ValueObjects\CurrencyPair;
use Modules\Treasury\Currency\Domain\ValueObjects\ExchangeRateValue;
use Modules\Treasury\Currency\Domain\ValueObjects\RateQuote;

/**
 * European Central Bank reference rates (daily feed around 16:00 CET): 1 EUR = rate of each currency. The XML is validated
 * (a dated cube, ISO codes, positive decimal rates); a feed with no valid rate fails.
 */
final class EcbRateProvider extends HttpRateProvider
{
    public function source(): RateSource
    {
        return RateSource::Ecb;
    }

    public function fetch(): array
    {
        $config = (array) config('currency_fx.providers.ecb');
        $body = $this->download((string) $config['url'], (int) $config['timeout'], array_map('intval', (array) $config['backoff_ms']));

        return $this->parse($body);
    }

    /**
     * @return list<RateQuote>
     *
     * @throws RateProviderException
     */
    public function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = simplexml_load_string($xml, options: LIBXML_NONET | LIBXML_NOCDATA);
            $cubes = $document === false ? [] : ($document->xpath('//*[@time]') ?: []);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $quotes = [];

        foreach ($cubes as $day) {
            $date = (string) $day['time'];

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                continue;
            }

            foreach ($day->children() as $cube) {
                $currency = (string) ($cube['currency'] ?? '');
                $rate = (string) ($cube['rate'] ?? '');

                if (preg_match('/^[A-Z]{3}$/', $currency) !== 1 || ! Dec::isDecimal($rate) || ! Dec::isPositive($rate) || $currency === 'EUR') {
                    continue;
                }

                try {
                    $quotes[] = new RateQuote(CurrencyPair::of('EUR', $currency), ExchangeRateValue::of($rate), $date, RateType::Mid, RateSource::Ecb);
                } catch (InvalidArgumentException) {
                    continue;
                }
            }
        }

        if ($quotes === []) {
            throw new RateProviderException('ECB feed has no valid rate: the schema is not the expected eurofxref XML.');
        }

        return $quotes;
    }
}
