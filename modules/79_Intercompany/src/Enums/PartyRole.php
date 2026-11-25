<?php

declare(strict_types=1);

namespace Modules\Intercompany\Enums;

enum PartyRole: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
