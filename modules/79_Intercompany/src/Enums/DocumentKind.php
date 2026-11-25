<?php

declare(strict_types=1);

namespace Modules\Intercompany\Enums;

enum DocumentKind: string
{
    case Invoice = 'invoice';
    case DebitNote = 'debit_note';
    case CreditNote = 'credit_note';

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
