<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Domain\Enums;

enum AllocationMethod: string
{
    case FIFO = 'FIFO';
    case SpecificMRN = 'Specific_MRN';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
