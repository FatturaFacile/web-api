<?php

declare(strict_types=1);

namespace Modules\Plm\Enums;

enum ApprovalDepartment: string
{
    case Engineering = 'Engineering';
    case Quality = 'Quality';
    case Purchasing = 'Purchasing';
    case Production = 'Production';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
