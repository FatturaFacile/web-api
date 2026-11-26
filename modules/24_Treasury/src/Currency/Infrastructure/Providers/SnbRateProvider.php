<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Infrastructure\Providers;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\Treasury\Currency\Domain\Enums\RateSource;
use Modules\Treasury\Currency\Domain\Enums\RateType;
use Modules\Treasury\Currency\Domain\Exceptions\RateProviderException;
use Modules\Treasury\Currency\Domain\Support\Dec;
use Modules\Treasury\Currency\Domain\ValueObjects\CurrencyPair;
use Modules\Treasury\Currency\Domain\ValueObjects\ExchangeRateValue;
use Modules\Treasury\Currency\Domain\ValueObjects\RateQuote;

/**
 * Swiss National Bank data portal (CSV cubes). The daily cube gives the rate of each currency in CHF per day (stored as mid
 * rates), the monthly cube the month-end rate (stored as closing rates on the last day of the month, used by the period-end
 * revaluation). A series such as `EUR1` is one unit, `JPY100` is quoted per 100 units: the rate is stored per unit. A feed
 * without a valid daily rate fails; the monthly cube is optional and its absence is not an error.
 */
final class SnbRateProvider extends HttpRateProvider
{
    public function source(): RateSource
    {
        return RateSource::Snb;
    }

    public function fetch(): array
    {
        $config = (array) config('currency_fx.providers.snb');
        $backoff = array_map('intval', (array) $config['backoff_ms']);
        $from = CarbonImmutable::now()->subDays((int) $config['lookback_days'])->toDateString();

        $quotes = $this->parse($this->download((string) $config['daily_url'], (int) $config['timeout'], $backoff, ['fromDate' => $from]), RateType::Mid);

        if ((string) ($config['monthly_url'] ?? '') !== '') {
            try {
                $quotes = [...$quotes, ...$this->parse($this->download((string) $config['monthly_url'], (int) $config['timeout'], $backoff, ['fromDate' => CarbonImmutable::now()->subMonths(2)->format('Y-m')]), RateType::Closing)];
            } catch (RateProviderException) {
                // The month-end rates are optional: the daily rates are what the documents need.
            }
        }

        return $quotes;
    }

    /**
     * @return list<RateQuote>
     *
     * @throws RateProviderException
     */
    public function parse(string $csv, RateType $type): array
    {
        $quotes = [];
        $columns = null;

        foreach (preg_split('/\R/', $csv) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            $cells = array_map(static fn (string $cell): string => trim($cell, " \t\""), str_getcsv($line, ';', '"', ''));

            if ($columns === null) {
                if (strtolower($cells[0]) === 'date') {
                    $columns = array_map('strtolower', $cells);
                }

                continue;
            }

            if (count($cells) < count($columns)) {
                continue;
            }

            $quote = $this->row($cells, $columns, $type);

            if ($quote !== null) {
                $quotes[] = $quote;
            }
        }

        if ($quotes === []) {
            throw new RateProviderException('SNB feed has no valid rate: the schema is not the expected data portal CSV (Date; series; Value).');
        }

        return $quotes;
    }

    /**
     * @param  list<string>  $cells
     * @param  list<string>  $columns
     */
    private function row(array $cells, array $columns, RateType $type): ?RateQuote
    {
        $date = $cells[0];
        $value = $cells[array_key_last($columns)];
        $series = null;

        foreach ($cells as $index => $cell) {
            if ($index > 0 && preg_match('/^([A-Z]{3})(\d+)$/', $cell) === 1) {
                $series = $cell;
                break;
            }
        }

        if ($series === null || ! Dec::isDecimal($value) || ! Dec::isPositive($value)) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            $day = $date;
        } elseif (preg_match('/^\d{4}-\d{2}$/', $date) === 1) {
            $day = CarbonImmutable::parse($date.'-01')->endOfMonth()->toDateString();
        } else {
            return null;
        }

        preg_match('/^([A-Z]{3})(\d+)$/', $series, $parts);
        $currency = $parts[1];
        $unit = $parts[2];

        if ($currency === 'CHF' || ! Dec::isPositive($unit)) {
            return null;
        }

        try {
            return new RateQuote(CurrencyPair::of($currency, 'CHF'), ExchangeRateValue::of(Dec::div($value, $unit)), $day, $type, RateSource::Snb);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
