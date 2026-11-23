<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use Modules\Procurement\Exceptions\ProcurementRuleViolation;
use Modules\Procurement\Support\Decimal;

/**
 * Deterministic arithmetic of a purchase order line: cascading commercial discounts, dual unit of measure and foreign
 * currency. Everything is bcmath on decimal strings; the intermediate factor keeps 10 decimals and only the stored
 * amounts are rounded, so a price per carton and a price per base unit never drift apart.
 */
final readonly class PurchasePricingService
{
    private const int FactorScale = 10;

    /**
     * Validates a cascade such as 20 + 10 + 5: every step is a percentage from 0 to 100; zero steps are dropped.
     *
     * @param  array<int, mixed>|null  $discounts
     * @return list<string>
     *
     * @throws ProcurementRuleViolation when a step is not a percentage
     */
    public function normalizeCascade(?array $discounts): array
    {
        $steps = [];

        foreach (array_values($discounts ?? []) as $index => $step) {
            if (! is_numeric($step) || (float) $step < 0 || (float) $step > 100) {
                throw new ProcurementRuleViolation('Discount step '.($index + 1).' must be a percentage between 0 and 100.', "discount_cascade.{$index}");
            }

            if ((float) $step > 0) {
                $steps[] = Decimal::of($step, 4);
            }
        }

        return $steps;
    }

    /**
     * Multiplier left by the cascade: 20 + 10 + 5 leaves 0.8 x 0.9 x 0.95 = 0.684.
     *
     * @param  list<string>  $steps
     */
    public function cascadeFactor(array $steps): string
    {
        $factor = '1';

        foreach ($steps as $step) {
            $factor = bcmul($factor, bcsub('1', bcdiv($step, '100', self::FactorScale), self::FactorScale), self::FactorScale);
        }

        return $factor;
    }

    /**
     * The single percentage equal to the cascade (20 + 10 + 5 is 31.6 %).
     *
     * @param  list<string>  $steps
     */
    public function effectivePercent(array $steps): string
    {
        return Decimal::round(bcmul(bcsub('1', $this->cascadeFactor($steps), self::FactorScale), '100', self::FactorScale), 4);
    }

    /**
     * Net amount of a line: quantity x gross unit price x cascade, rounded once.
     *
     * @param  list<string>  $steps
     */
    public function lineNet(string $quantity, string $grossUnitPrice, array $steps): string
    {
        return Decimal::round(bcmul(bcmul($quantity, $grossUnitPrice, self::FactorScale), $this->cascadeFactor($steps), self::FactorScale), 2);
    }

    /**
     * Unit price after the cascade, to the 4 decimals of the stored price.
     *
     * @param  list<string>  $steps
     */
    public function netUnitPrice(string $grossUnitPrice, array $steps): string
    {
        return Decimal::round(bcmul($grossUnitPrice, $this->cascadeFactor($steps), self::FactorScale), 4);
    }

    /**
     * The factor that turns one order unit (carton, roll) into base stock units (kg, metres).
     *
     * @throws ProcurementRuleViolation when the factor is not positive
     */
    public function conversionFactor(mixed $factor): string
    {
        $value = Decimal::of($factor === null || $factor === '' ? '1' : $factor, 6);

        if (! Decimal::isPositive($value)) {
            throw new ProcurementRuleViolation('The unit of measure conversion factor must be greater than zero.', 'uom_conversion_factor');
        }

        return $value;
    }

    public function toBase(string $orderQuantity, string $factor): string
    {
        return Decimal::round(bcmul($orderQuantity, $factor, self::FactorScale), 4);
    }

    public function fromBase(string $baseQuantity, string $factor): string
    {
        return Decimal::round(bcdiv($baseQuantity, $factor, self::FactorScale), 4);
    }

    /**
     * Price of one base unit given the price of one order unit.
     */
    public function pricePerBaseUnit(string $unitPrice, string $factor): string
    {
        return Decimal::round(bcdiv($unitPrice, $factor, self::FactorScale), 6);
    }

    /**
     * Converts a document-currency amount to the company currency; `$rate` is company-currency units per one unit of
     * the document currency.
     */
    public function toBaseCurrency(string $amount, string $rate): string
    {
        return Decimal::round(bcmul($amount, $rate, self::FactorScale), 2);
    }
}
