<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Domain\Enums;

enum ProcedureType: string
{
    case Open = 'open';
    case Selective = 'selective';
    case Invitation = 'invitation';
    case DirectAward = 'direct_award';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
