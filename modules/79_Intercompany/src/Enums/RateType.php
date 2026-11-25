<?php

declare(strict_types=1);

namespace Modules\Intercompany\Enums;

enum RateType: string
{
    case Closing = 'closing';
    case Average = 'average';
    case Historical = 'historical';

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
