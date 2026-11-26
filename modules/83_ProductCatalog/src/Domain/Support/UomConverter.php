<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Domain\Support;

use Modules\ProductCatalog\Domain\Enums\RoundingMode;
use Modules\ProductCatalog\Exceptions\CatalogException;

/**
 * Exact conversion between the units of one product. Every unit is described by the number of base stock units it contains
 * (the base unit itself is 1), so any conversion goes through the base quantity: multiplication by a stored factor is exact,
 * and the only rounding happens once, when a base quantity is expressed in a larger unit, following the product's policy.
 * That keeps purchase, stock and sales quantities free of cumulative rounding drift.
 */
final class UomConverter
{
    public const string BASE = 'BASE';

    /**
     * @param  array<string, string>  $factors  unit code => base units contained in one of that unit
     */
    public function __construct(
        private readonly array $factors,
        private readonly int $precision = 4,
        private readonly RoundingMode $rounding = RoundingMode::HalfUp,
    ) {}

    /**
     * @return list<string>
     */
    public function units(): array
    {
        return [self::BASE, ...array_keys($this->factors)];
    }

    public function factorOf(string $unit): string
    {
        if ($unit === self::BASE) {
            return Dec::of(1);
        }

        $factor = $this->factors[$unit] ?? null;

        if ($factor === null || Dec::cmp($factor, 0) <= 0) {
            throw new CatalogException('unknown_unit', 422, ['unit' => $unit]);
        }

        return Dec::of($factor);
    }

    /**
     * A quantity of a unit expressed in base stock units (exact).
     */
    public function toBase(string|int|float $quantity, string $unit): string
    {
        return Dec::mul($quantity, $this->factorOf($unit));
    }

    /**
     * A base quantity expressed in a unit, rounded once to the precision of the product.
     */
    public function fromBase(string|int|float $baseQuantity, string $unit): string
    {
        $exact = Dec::div($baseQuantity, $this->factorOf($unit));

        if ($this->rounding === RoundingMode::None) {
            if (! Dec::fits($exact, $this->precision)) {
                throw new CatalogException('conversion_not_exact', 422, ['quantity' => Dec::trim($exact), 'unit' => $unit, 'precision' => $this->precision]);
            }

            return Dec::round($exact, $this->precision, 'down');
        }

        return Dec::round($exact, $this->precision, $this->rounding->value);
    }

    /**
     * Converts between two units through the base quantity.
     */
    public function convert(string|int|float $quantity, string $from, string $to): string
    {
        return $this->fromBase($this->toBase($quantity, $from), $to);
    }

    /**
     * Decomposes a base quantity into whole packages, largest unit first, with the remainder in base units; the parts add back to
     * the quantity exactly (for example 1,030 units = 1 pallet of 1,000 + 1 carton of 24 + 6 units).
     *
     * @return array{parts: list<array{unit: string, quantity: string}>, remainder: string}
     */
    public function split(string|int|float $baseQuantity): array
    {
        $left = Dec::of($baseQuantity);
        $parts = [];
        $units = $this->factors;
        uasort($units, static fn (string $a, string $b): int => Dec::cmp($b, $a));

        foreach (array_keys($units) as $unit) {
            $factor = $this->factorOf($unit);

            if (Dec::cmp($factor, 1) <= 0 || Dec::cmp($left, $factor) < 0) {
                continue;
            }

            $whole = bcdiv($left, $factor, 0);
            $parts[] = ['unit' => $unit, 'quantity' => $whole];
            $left = Dec::sub($left, Dec::mul($whole, $factor));
        }

        return ['parts' => $parts, 'remainder' => Dec::trim($left)];
    }

    /**
     * Whether a quantity of the unit is a whole number of base units, so a movement in it never leaves a fraction of stock.
     */
    public function isWholeInBase(string|int|float $quantity, string $unit): bool
    {
        return Dec::fits($this->toBase($quantity, $unit), 0);
    }
}
