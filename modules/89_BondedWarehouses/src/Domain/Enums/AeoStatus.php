<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Domain\Enums;

enum AeoStatus: string
{
    case None = 'None';
    case AEOC = 'AEOC';
    case AEOS = 'AEOS';
    case AEOF = 'AEOF';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
