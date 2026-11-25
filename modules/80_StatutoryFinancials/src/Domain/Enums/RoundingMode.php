<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Domain\Enums;

/**
 * ExactCents keeps every line at two decimals; IntegerUnits_Euro_Chf shows whole euro / francs as the XBRL deposit requires.
 * Both modes run through the rounding quadrature, so every subtotal and total reconciles with zero residual.
 */
enum RoundingMode: string
{
    case ExactCents = 'ExactCents';
    case IntegerUnits = 'IntegerUnits_Euro_Chf';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function scale(): int
    {
        return match ($this) {
            self::ExactCents => 2,
            self::IntegerUnits => 0,
        };
    }
}
