<?php

declare(strict_types=1);

namespace Modules\SwissFiscal\Domain\ValueObjects;

use DateTimeImmutable;
use Modules\SwissFiscal\Domain\Exceptions\SwissFiscalException;

/**
 * A locked exchange rate captured at quote or order creation: 1 unit of `base` equals `rate` units of `quote`.
 * Documents keep the snapshot so later rate changes never alter an agreed price.
 */
final readonly class ExchangeRateSnapshot
{
    public function __construct(
        public Currency $base,
        public Currency $quote,
        public string $rate,
        public DateTimeImmutable $capturedAt,
        public string $source = 'manual',
    ) {
        if (preg_match('/^\d+(\.\d{1,10})?$/', $rate) !== 1 || bccomp($rate, '0', 10) <= 0) {
            throw SwissFiscalException::invalidAmount($rate);
        }
    }

    /** Converts an amount of the base currency into the quote currency (half away from zero on the minor unit). */
    public function convert(Money $amount): Money
    {
        if ($amount->currency !== $this->base) {
            throw SwissFiscalException::currencyMismatch($amount->currency->value, $this->base->value);
        }

        $minorScale = bcpow('10', (string) ($this->quote->minorUnits() - $this->base->minorUnits()), 10);
        $converted = bcmul(bcmul((string) $amount->minor, $this->rate, 12), $minorScale, 12);

        return new Money(Money::roundHalfAwayFromZero($converted), $this->quote);
    }

    /** The inverse snapshot (quote to base), rounded to 10 decimals. */
    public function inverse(): self
    {
        return new self($this->quote, $this->base, bcdiv('1', $this->rate, 10), $this->capturedAt, $this->source);
    }
}
