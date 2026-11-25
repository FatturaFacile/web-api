<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Domain\Enums;

enum BillingRunStatus: string
{
    case Drafted = 'drafted';
    case Invoiced = 'invoiced';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
