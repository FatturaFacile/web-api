<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Domain\Enums;

enum FormulaType: string
{
    case Linear = 'Linear';
    case Bilinear = 'Bilinear';
    case Interpolation = 'Interpolation';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
