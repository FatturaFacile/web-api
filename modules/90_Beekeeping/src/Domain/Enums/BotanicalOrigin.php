<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Domain\Enums;

enum BotanicalOrigin: string
{
    case Acacia = 'acacia';
    case Chestnut = 'chestnut';
    case Linden = 'linden';
    case AlpineRhododendron = 'alpine_rhododendron';
    case Wildflower = 'wildflower';
    case Citrus = 'citrus';
    case Sulla = 'sulla';
    case Eucalyptus = 'eucalyptus';
    case Honeydew = 'honeydew';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
