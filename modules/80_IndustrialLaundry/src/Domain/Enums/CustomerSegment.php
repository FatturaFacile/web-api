<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Domain\Enums;

enum CustomerSegment: string
{
    case Hotel = 'hotel';
    case Restaurant = 'restaurant';
    case Clinic = 'clinic';
    case Rsa = 'rsa';
    case Industry = 'industry';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
