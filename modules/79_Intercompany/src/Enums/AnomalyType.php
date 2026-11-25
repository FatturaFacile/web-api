<?php

declare(strict_types=1);

namespace Modules\Intercompany\Enums;

enum AnomalyType: string
{
    case None = 'none';
    case Timing = 'timing';
    case Fx = 'fx';
    case Amount = 'amount';
    case Tax = 'tax';
    case MissingCounterpart = 'missing_counterpart';

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
