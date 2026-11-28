<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Domain\Enums;

/**
 * Size of a harness; UNIVERSAL fits the other categories.
 */
enum PpeSize: string
{
    case Baby = 'BABY';
    case Junior = 'JUNIOR';
    case Adult = 'ADULT';
    case Xl = 'XL';
    case Universal = 'UNIVERSAL';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
