<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Domain\Enums;

enum AccrualBasis: string
{
    case Invoiced = 'invoiced';
    case Collected = 'collected';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
