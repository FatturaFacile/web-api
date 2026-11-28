<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Domain\Enums;

enum DeclarationType: string
{
    case IM7 = 'IM7';
    case PassarImport = 'Passar_Import';
    case T1 = 'T1';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
