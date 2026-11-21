<?php

declare(strict_types=1);

namespace Modules\SwissFiscal\Domain\ValueObjects;

use Modules\SwissFiscal\Domain\Exceptions\SwissFiscalException;

/**
 * Immutable monetary amount held in integer minor units (cents/rappen). All arithmetic with fractions goes through
 * bcmath and rounds half away from zero, so results are identical on every platform and database.
 */
final readonly class Money
{
    public function __construct(public int $minor, public Currency $currency) {}

    /** Parses a decimal string ("1234.5", "-0.05") and rounds it to the minor unit. */
    public static function of(string $decimal, Currency $currency): self
    {
        if (preg_match('/^-?\d+(\.\d+)?$/', $decimal) !== 1) {
            throw SwissFiscalException::invalidAmount($decimal);
        }

        $minor = bcmul($decimal, bcpow('10', (string) $currency->minorUnits(), 0), 12);

        return new self(self::roundHalfAwayFromZero($minor), $currency);
    }

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    /** Rounds a bcmath decimal string to the nearest integer, ties away from zero. */
    public static function roundHalfAwayFromZero(string $value): int
    {
        $negative = str_starts_with($value, '-') && bccomp($value, '0', 12) < 0;
        $absolute = ltrim($value, '-');
        $rounded = bcadd(bcadd($absolute, '0.5', 12), '0', 0);

        return (int) ($negative ? '-'.$rounded : $rounded);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minor - $other->minor, $this->currency);
    }

    /** Multiplies by a decimal factor, e.g. a quantity ("2.5") or a discount multiplier ("0.9"). */
    public function times(string $factor): self
    {
        if (preg_match('/^-?\d+(\.\d+)?$/', $factor) !== 1) {
            throw SwissFiscalException::invalidAmount($factor);
        }

        return new self(self::roundHalfAwayFromZero(bcmul((string) $this->minor, $factor, 12)), $this->currency);
    }

    /** A percentage of the amount, e.g. `percentage('8.1')` for Swiss standard VAT. */
    public function percentage(string $percent): self
    {
        return $this->times(bcdiv($percent, '100', 12));
    }

    /**
     * Splits the amount proportionally to the ratios without losing a single minor unit (largest remainder method;
     * the leftover units go to the first shares).
     *
     * @param  list<int>  $ratios
     * @return list<self>
     */
    public function allocate(array $ratios): array
    {
        $total = array_sum($ratios);
        if ($ratios === [] || $total <= 0) {
            throw SwissFiscalException::invalidAmount('allocation ratios');
        }

        $shares = [];
        $remainder = $this->minor;
        foreach ($ratios as $ratio) {
            $share = intdiv($this->minor * $ratio, $total);
            $shares[] = $share;
            $remainder -= $share;
        }

        for ($i = 0; $remainder > 0; $i = ($i + 1) % count($shares)) {
            $shares[$i]++;
            $remainder--;
        }

        return array_map(fn (int $share): self => new self($share, $this->currency), $shares);
    }

    /**
     * Rounds to the currency's cash increment: 0.05 for CHF, 0.01 otherwise. Ties round away from zero
     * (CHF 0.025 becomes 0.05, CHF 0.02 becomes 0.00).
     */
    public function roundToCashIncrement(): self
    {
        $step = $this->currency->cashIncrementMinor();
        $steps = self::roundHalfAwayFromZero(bcdiv((string) $this->minor, (string) $step, 12));

        return new self($steps * $step, $this->currency);
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minor === $other->minor;
    }

    /** Fixed-point decimal string without grouping ("1949.75"). */
    public function toDecimalString(): string
    {
        $scale = $this->currency->minorUnits();
        $absolute = str_pad((string) abs($this->minor), $scale + 1, '0', STR_PAD_LEFT);
        $text = substr($absolute, 0, -$scale).'.'.substr($absolute, -$scale);

        return ($this->minor < 0 ? '-' : '').$text;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw SwissFiscalException::currencyMismatch($this->currency->value, $other->currency->value);
        }
    }
}
