<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Domain\Enums;

enum RunStatus: string
{
    case PREVIEW = 'PREVIEW';
    case CONSOLIDATED = 'CONSOLIDATED';
    case REVERSED = 'REVERSED';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
