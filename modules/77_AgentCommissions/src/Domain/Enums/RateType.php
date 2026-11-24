<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Domain\Enums;

enum RateType: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
