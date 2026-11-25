<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Domain\Enums;

enum CashFlowMethod: string
{
    case Indirect = 'indirect';
    case Direct = 'direct';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
