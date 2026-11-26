<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\ValueObjects;

use InvalidArgumentException;
use Modules\Treasury\Currency\Domain\Support\Dec;

/**
 * Units of the quote currency for one unit of the base currency, a positive decimal with six decimals (decimal(18,6)).
 */
final readonly class ExchangeRateValue
{
    private function __construct(public string $value) {}

    /**
     * A rate typed by a person or taken from a document: more than six decimals is refused, not silently rounded.
     */
    public static function strict(string $rate): self
    {
        if (! Dec::isDecimal($rate)) {
            throw new InvalidArgumentException("Invalid exchange rate [{$rate}].");
        }

        if (Dec::decimals($rate) > Dec::RATE) {
            throw new InvalidArgumentException("An exchange rate has at most 6 decimals [{$rate}].");
        }

        return self::of($rate);
    }

    /**
     * A published or computed rate: rounded half up to six decimals.
     */
    public static function of(string $rate): self
    {
        if (! Dec::isDecimal($rate)) {
            throw new InvalidArgumentException("Invalid exchange rate [{$rate}].");
        }

        $rounded = Dec::rate($rate);

        if (! Dec::isPositive($rounded)) {
            throw new InvalidArgumentException("An exchange rate must be greater than zero [{$rate}].");
        }

        return new self($rounded);
    }

    public static function one(): self
    {
        return new self('1.000000');
    }

    /**
     * The rate of the opposite pair.
     */
    public function inverse(): self
    {
        return self::of(Dec::div('1', $this->value));
    }

    public function equals(self $other): bool
    {
        return Dec::cmp($this->value, $other->value) === 0;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
