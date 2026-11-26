<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\ValueObjects;

use InvalidArgumentException;
use Modules\Treasury\Currency\Domain\Support\Dec;

/**
 * An amount in a currency, kept as a decimal string. Conversion rounds half up to the cent.
 */
final readonly class Money
{
    private function __construct(public string $amount, public CurrencyCode $currency) {}

    public static function of(string $amount, CurrencyCode|string $currency): self
    {
        return new self(Dec::money($amount), $currency instanceof CurrencyCode ? $currency : CurrencyCode::of($currency));
    }

    public function convert(ExchangeRateValue $rate, CurrencyCode $to): self
    {
        return new self(Dec::money(Dec::mul($this->amount, $rate->value)), $to);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(Dec::money(Dec::add($this->amount, $other->amount)), $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(Dec::money(Dec::sub($this->amount, $other->amount)), $this->currency);
    }

    public function negate(): self
    {
        return new self(Dec::money(Dec::neg($this->amount)), $this->currency);
    }

    public function isZero(): bool
    {
        return Dec::isZero($this->amount);
    }

    private function assertSameCurrency(self $other): void
    {
        if (! $this->currency->equals($other->currency)) {
            throw new InvalidArgumentException("Cannot combine {$this->currency->value} with {$other->currency->value}.");
        }
    }
}
