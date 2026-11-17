<?php

declare(strict_types=1);

namespace Modules\HumanResources\Enums;

enum CvParseStatus: string
{
    case Pending = 'pending';
    case Parsed = 'parsed';
    case Partial = 'partial';
    case Failed = 'failed';
    case NotProvided = 'not_provided';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
