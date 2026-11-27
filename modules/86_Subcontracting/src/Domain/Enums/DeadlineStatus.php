<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Domain\Enums;

enum DeadlineStatus: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Expired = 'expired';
    case Closed = 'closed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
