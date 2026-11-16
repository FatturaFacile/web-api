<?php

declare(strict_types=1);

namespace App\Enums;

enum CustomsOriginType: string
{
    case Preferential = 'preferential';
    case NonPreferential = 'non_preferential';

    public function isPreferential(): bool
    {
        return $this === self::Preferential;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
