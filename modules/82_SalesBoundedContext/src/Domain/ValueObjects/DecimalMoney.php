<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Domain\ValueObjects;

use InvalidArgumentException;

/**
 * High-precision decimal money and commercial math value object (4 decimal places internal,
 * half-up 2 decimal places for settlement, plus Swiss 0.05 CHF rounding).
 */
final readonly class DecimalMoney
{
    public const SCALE = 4;

    private function __construct(
        public string $amount,
        public string $currency = 'CHF',
    ) {}

    public static function of(string|int|float $amount, string $currency = 'CHF'): self
    {
        $normalized = is_string($amount) ? trim($amount) : number_format((float) $amount, self::SCALE, '.', '');
        if (! is_numeric($normalized)) {
            throw new InvalidArgumentException("Invalid decimal amount [{$amount}].");
        }

        return new self(
            self::normalize((float) $normalized, self::SCALE),
            strtoupper(trim($currency)) ?: 'CHF'
        );
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return self::of((float) $this->amount + (float) $other->amount, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return self::of((float) $this->amount - (float) $other->amount, $this->currency);
    }

    public function multiply(string|int|float $factor): self
    {
        return self::of((float) $this->amount * (float) $factor, $this->currency);
    }

    public function percentage(string|int|float $ratePercent): self
    {
        return self::of(((float) $this->amount * (float) $ratePercent) / 100.0, $this->currency);
    }

    public function weighted(float $probabilityPercent): self
    {
        $clamped = max(0.0, min(100.0, $probabilityPercent));

        return $this->percentage($clamped);
    }

    /**
     * Cascading discount chain: e.g. [10, 5, 2] applies (1 - 0.10) * (1 - 0.05) * (1 - 0.02).
     *
     * @param  list<float|int|string>  $discountPercentages
     */
    public function applyCascadingDiscounts(array $discountPercentages): self
    {
        $current = (float) $this->amount;
        foreach ($discountPercentages as $pct) {
            $rate = max(0.0, min(100.0, (float) $pct));
            $current *= (1.0 - ($rate / 100.0));
        }

        return self::of($current, $this->currency);
    }

    public function allocateProRata(int $activeDays, int $totalPeriodDays): self
    {
        if ($totalPeriodDays <= 0) {
            throw new InvalidArgumentException('Total period days must be positive.');
        }

        $clampedDays = max(0, min($activeDays, $totalPeriodDays));

        return self::of(((float) $this->amount * $clampedDays) / $totalPeriodDays, $this->currency);
    }

    public function marginPercentage(self $cost): float
    {
        $this->assertSameCurrency($cost);
        $revenue = (float) $this->amount;
        if ($revenue <= 0.0) {
            return 0.0;
        }

        return round((($revenue - (float) $cost->amount) / $revenue) * 100.0, 2);
    }

    public function roundHalfUp(int $decimals = 2): string
    {
        return number_format(round((float) $this->amount, $decimals, PHP_ROUND_HALF_UP), $decimals, '.', '');
    }

    public function roundSwissFiveRappen(): string
    {
        $rounded = round(((float) $this->amount) * 20.0, 0, PHP_ROUND_HALF_UP) / 20.0;

        return number_format($rounded, 2, '.', '');
    }

    public function toFloat(int $decimals = 2): float
    {
        return (float) $this->roundHalfUp($decimals);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Currency mismatch: {$this->currency} vs {$other->currency}.");
        }
    }

    private static function normalize(float $value, int $scale): string
    {
        return number_format(round($value, $scale, PHP_ROUND_HALF_UP), $scale, '.', '');
    }
}
