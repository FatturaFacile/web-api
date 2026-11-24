<?php

declare(strict_types=1);

namespace App\Enums;

enum TaricCodeLevel: string
{
    case Hs6 = 'HS6';
    case Cn8 = 'CN8';
    case Taric10 = 'TARIC10';

    /**
     * Number of digits a code of this level must have.
     */
    public function digits(): int
    {
        return match ($this) {
            self::Hs6 => 6,
            self::Cn8 => 8,
            self::Taric10 => 10,
        };
    }

    /**
     * Resolve the level from a numeric code, or null when the length is invalid.
     */
    public static function fromCode(string $code): ?self
    {
        return match (strlen($code)) {
            6 => self::Hs6,
            8 => self::Cn8,
            10 => self::Taric10,
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
