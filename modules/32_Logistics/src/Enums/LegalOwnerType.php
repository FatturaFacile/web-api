<?php

declare(strict_types=1);

namespace Modules\Logistics\Enums;

enum LegalOwnerType: string
{
    case Company = 'company';
    case Vendor = 'vendor';
    case Customer = 'customer';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
