<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Domain\Enums;

enum PeriodType: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Custom = 'custom';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
