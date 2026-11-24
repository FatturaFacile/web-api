<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Domain\Enums;

enum AgentType: string
{
    case Direct = 'direct';
    case SubAgent = 'sub_agent';
    case MultiMandate = 'multi_mandate';
    case SingleMandate = 'single_mandate';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
