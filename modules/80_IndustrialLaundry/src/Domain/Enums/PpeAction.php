<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Domain\Enums;

/**
 * What the laundry decides for a PPE garment that reached its certified wash limit.
 */
enum PpeAction: string
{
    case Replace = 'replace';
    case Mend = 'mend';
    case Condemn = 'condemn';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
