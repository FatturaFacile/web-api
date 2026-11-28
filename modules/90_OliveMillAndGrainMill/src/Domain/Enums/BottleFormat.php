<?php

declare(strict_types=1);

namespace Modules\OliveMillAndGrainMill\Domain\Enums;

enum BottleFormat: string
{
    case L025 = '0.25L';
    case L050 = '0.50L';
    case L075 = '0.75L';
    case L3 = '3L';
    case L5 = '5L';

    public function liters(): float
    {
        return match ($this) {
            self::L025 => 0.25,
            self::L050 => 0.5,
            self::L075 => 0.75,
            self::L3 => 3.0,
            self::L5 => 5.0,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
