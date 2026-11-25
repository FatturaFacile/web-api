<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Domain\Enums;

enum AllowanceMethod: string
{
    case FlatRate = 'flat_rate';
    case Mixed = 'mixed';
    case Analytic = 'analytic';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
