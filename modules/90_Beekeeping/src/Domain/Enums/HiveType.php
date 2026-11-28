<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Domain\Enums;

enum HiveType: string
{
    case DadantBlatt = 'dadant_blatt';
    case Langstroth = 'langstroth';
    case TopBar = 'top_bar';
    case Nucleus = 'nucleus';
    case MatingNucleus = 'mating_nucleus';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
