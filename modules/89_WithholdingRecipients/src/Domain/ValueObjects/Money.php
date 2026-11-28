<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Domain\ValueObjects;

/**
 * Immutable amount held in integer cents (no float drift). Percentages are applied with half-up rounding on the cent.
 */
final readonly class Money
{
    public function __construct(public int $cents = 0) {}

    public static function zero(): self
    {
        return new self(0);
    }

    public static function fromCents(int $cents): self
    {
        return new self($cents);
    }

    /**
     * @param  int|float|string|null  $amount  decimal amount, e.g. "1234.56"
     */
    public static function of(int|float|string|null $amount): self
    {
        if ($amount === null || $amount === '') {
            return new self(0);
        }

        $decimal = is_float($amount) ? number_format($amount, 6, '.', '') : trim((string) $amount);
        $scaled = bcmul($decimal, '100', 6);

        return new self((int) bcadd($scaled, str_starts_with($scaled, '-') ? '-0.5' : '0.5', 0));
    }

    public function plus(self $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function minus(self $other): self
    {
        return new self($this->cents - $other->cents);
    }

    /**
     * @param  int|float|string  $percent  e.g. "23", "11.5", 8.75
     */
    public function percent(int|float|string $percent): self
    {
        $numerator = $this->cents * (int) round(((float) $percent) * 10000);
        $rounded = intdiv(abs($numerator) * 2 + 1000000, 2000000);

        return new self($numerator < 0 ? -$rounded : $rounded);
    }

    public function max(self $other): self
    {
        return $this->cents >= $other->cents ? $this : $other;
    }

    public function min(self $other): self
    {
        return $this->cents <= $other->cents ? $this : $other;
    }

    /**
     * Never negative.
     */
    public function floorAtZero(): self
    {
        return $this->cents < 0 ? new self(0) : $this;
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    public function greaterThan(self $other): bool
    {
        return $this->cents > $other->cents;
    }

    public function lessThanOrEqual(self $other): bool
    {
        return $this->cents <= $other->cents;
    }

    /**
     * Splits the amount in a first part (rounded) and the remainder, so the two always add up.
     *
     * @return array{0: self, 1: self}
     */
    public function split(int|float|string $firstSharePercent): array
    {
        $first = $this->percent($firstSharePercent);

        return [$first, $this->minus($first)];
    }

    public function toDecimal(): string
    {
        $sign = $this->cents < 0 ? '-' : '';
        $abs = abs($this->cents);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }

    public function toFloat(): float
    {
        return $this->cents / 100;
    }
}
