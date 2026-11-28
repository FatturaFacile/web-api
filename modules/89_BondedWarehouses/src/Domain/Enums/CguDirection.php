<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Domain\Enums;

enum CguDirection: string
{
    case Engage = 'Engage';
    case Release = 'Release';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
