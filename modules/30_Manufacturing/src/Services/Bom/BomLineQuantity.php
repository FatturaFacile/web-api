<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\Bom;

use Modules\Manufacturing\Models\MfgBomComponent;
use Modules\Manufacturing\Support\Money;

/**
 * The quantity arithmetic of one BOM line, shared by every explosion so the cost rollup, the MRP and the UI agree:
 * the variable part scales with the parent quantity and is grossed up by the proportional scrap and divided by the
 * yield; the fixed setup waste is added once per run of the parent, whatever its size.
 */
final class BomLineQuantity
{
    /**
     * Quantity needed for a parent ratio (parent quantity / BOM base quantity), waste included.
     */
    public static function gross(MfgBomComponent $line, string $ratio): string
    {
        return Money::add(self::variable($line, $ratio), self::fixed($line));
    }

    /**
     * Net quantity per the parent ratio: neither scrap nor yield nor setup waste.
     */
    public static function net(MfgBomComponent $line, string $ratio): string
    {
        return Money::mul($line->quantity_per, $ratio);
    }

    /**
     * The part of the gross quantity that scales with the parent quantity (proportional scrap and yield included).
     */
    public static function variable(MfgBomComponent $line, string $ratio): string
    {
        return Money::mul(Money::mul($line->quantity_per, $ratio), self::factor($line));
    }

    /**
     * The fixed setup waste of the line.
     */
    public static function fixed(MfgBomComponent $line): string
    {
        return Money::of($line->fixed_scrap_quantity);
    }

    /**
     * Multiplier of one net unit: (1 + scrap%) / yield.
     */
    public static function factor(MfgBomComponent $line): string
    {
        $grossUp = Money::add('1', Money::div($line->scrap_percentage, '100'));
        $yield = Money::isPositive($line->yield_percentage) ? Money::div($line->yield_percentage, '100') : '1';

        return Money::div($grossUp, $yield);
    }
}
