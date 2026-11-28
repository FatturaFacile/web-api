<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Domain\Enums;

enum GeneticLine: string
{
    case Ligustica = 'ligustica';
    case Carnica = 'carnica';
    case Buckfast = 'buckfast';
    case Mellifera = 'mellifera';
    case Hybrid = 'hybrid';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
