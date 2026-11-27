<?php

declare(strict_types=1);

namespace Modules\GreenCare\Domain\Enums;

/**
 * Unit of the dose of a phytosanitary product.
 */
enum DosageUnit: string
{
    case MlPerHl = 'ml/hl';
    case GPer100Sqm = 'g/100sqm';
    case LPerHa = 'l/ha';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
