<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Domain\Enums;

enum NoticeStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Awarded = 'awarded';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
