<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Domain\Enums;

enum ReconciliationStatus: string
{
    case Unmatched = 'unmatched';
    case Matched = 'matched';
    case Ignored = 'ignored';
    case Disputed = 'disputed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
