<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Domain\Enums;

enum QueenStatus: string
{
    case PresentLaying = 'present_laying';
    case Virgin = 'virgin';
    case Supersedure = 'supersedure';
    case Missing = 'missing';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
