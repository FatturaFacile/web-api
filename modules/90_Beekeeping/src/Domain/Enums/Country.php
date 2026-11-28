<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Domain\Enums;

enum Country: string
{
    case IT = 'IT';
    case CH = 'CH';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
