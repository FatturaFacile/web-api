<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Domain\Enums;

enum MovementSubject: string
{
    case Hives = 'hives';
    case Swarms = 'swarms';
    case Nuclei = 'nuclei';
    case Queens = 'queens';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
