<?php

declare(strict_types=1);

namespace Modules\GdoTradeTerms\Domain\Enums;

enum VolumeMode: string
{
    case Total = 'total';
    case Incremental = 'incremental';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
