<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Domain\Enums;

enum FiscalRegime: string
{
    case Enasarco = 'enasarco';
    case Aec = 'aec';
    case SwissDirect = 'swiss_direct';
    case None = 'none';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
