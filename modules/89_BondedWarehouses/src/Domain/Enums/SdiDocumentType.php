<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Domain\Enums;

enum SdiDocumentType: string
{
    case TD22 = 'TD22';
    case TD23 = 'TD23';
    case None = 'None';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
