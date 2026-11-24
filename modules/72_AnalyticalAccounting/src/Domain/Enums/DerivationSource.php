<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Domain\Enums;

enum DerivationSource: string
{
    case MANUAL = 'MANUAL';
    case RULE = 'RULE';
    case MASTER_DEFAULT = 'MASTER_DEFAULT';
    case APPORTIONMENT = 'APPORTIONMENT';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
