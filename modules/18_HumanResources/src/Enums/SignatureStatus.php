<?php

declare(strict_types=1);

namespace Modules\HumanResources\Enums;

enum SignatureStatus: string
{
    case NotRequested = 'not_requested';
    case Pending = 'pending';
    case Signed = 'signed';
    case Declined = 'declined';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
