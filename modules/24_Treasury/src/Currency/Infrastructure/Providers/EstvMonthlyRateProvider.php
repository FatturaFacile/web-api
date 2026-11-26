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
 * ESTV monthly average rates for Swiss VAT (Art. 45 OIVA, Info IVA 16), stored as `vat_estv_monthly` rates in CHF per unit on
 * the first day of the month they refer to. Expected XML: a root with the month (`month="YYYY-MM"` or `<month>`), and one
 * `<currency>` per currency with its code (`code` attribute or `<code>`), its unit (`unit` attribute or `<unit>`, default 1) and
 * its rate (`rate` attribute or `<rate>`). A feed with no valid rate fails.
 */
final class EstvMonthlyRateProvider extends HttpRateProvider
{
    public function source(): RateSource
    {
        return RateSource::Estv;
    }

    public function fetch(): array
    {
        $config = (array) config('currency_fx.providers.estv');
        $month = CarbonImmutable::now()->subMonthNoOverflow()->format('Y-m');
        $current = CarbonImmutable::now()->format('Y-m');
        $backoff = array_map('intval', (array) $config['backoff_ms']);

        $quotes = $this->parse($this->download((string) $config['url'], (int) $config['timeout'], $backoff, ['month' => $month]), $month);

        try {
            // The average of the running month is not final before the month ends: it is picked up when ESTV publishes it.
            $quotes = [...$quotes, ...$this->parse($this->download((string) $config['url'], (int) $config['timeout'], $backoff, ['month' => $current]), $current)];
        } catch (RateProviderException) {
            // Not published yet.
        }

        return $quotes;
    }

    /**
     * @return list<RateQuote>
     *
     * @throws RateProviderException
     */
    public function parse(string $xml, ?string $defaultMonth = null): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = simplexml_load_string($xml, options: LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($document === false) {
            throw new RateProviderException('ESTV feed is not valid XML.');
        }

        $month = $this->text($document, 'month') ?? $defaultMonth;

        if ($month === null || preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            throw new RateProviderException('ESTV feed does not state the month of the rates.');
        }

        $day = $month.'-01';
        $quotes = [];

        foreach ($document->xpath('//currency') ?: [] as $node) {
            $code = strtoupper((string) $this->text($node, 'code'));
            $rate = (string) $this->text($node, 'rate');
            $unit = $this->text($node, 'unit') ?? '1';

            if (preg_match('/^[A-Z]{3}$/', $code) !== 1 || $code === 'CHF' || ! Dec::isDecimal($rate) || ! Dec::isDecimal($unit) || ! Dec::isPositive($rate) || ! Dec::isPositive($unit)) {
                continue;
            }

            try {
                $quotes[] = new RateQuote(CurrencyPair::of($code, 'CHF'), ExchangeRateValue::of(Dec::div($rate, $unit)), $day, RateType::VatEstvMonthly, RateSource::Estv);
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        if ($quotes === []) {
            throw new RateProviderException('ESTV feed has no valid rate: the schema is not the expected monthly average XML.');
        }

        return $quotes;
    }

    private function text(\SimpleXMLElement $node, string $name): ?string
    {
        if (isset($node[$name])) {
            return trim((string) $node[$name]);
        }

        return isset($node->{$name}) ? trim((string) $node->{$name}) : null;
    }
}
