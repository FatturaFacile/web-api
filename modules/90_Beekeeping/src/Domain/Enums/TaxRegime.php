<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Domain\Enums;

enum TaxRegime: string
{
    case SpecialAgricultural = 'special_agricultural';
    case Exemption = 'exemption';
    case Ordinary = 'ordinary';
    case Standard = 'standard';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
