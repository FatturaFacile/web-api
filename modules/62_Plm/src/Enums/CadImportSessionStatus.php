<?php

declare(strict_types=1);

namespace Modules\Plm\Enums;

enum CadImportSessionStatus: string
{
    case Parsed = 'Parsed';
    case Reviewed = 'Reviewed';
    case Committed = 'Committed';
    case Failed = 'Failed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
