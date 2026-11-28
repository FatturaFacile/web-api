<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Domain\Enums;

enum VarroaCountType: string
{
    case Natural = 'natural';
    case PostTreatment = 'post_treatment';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
