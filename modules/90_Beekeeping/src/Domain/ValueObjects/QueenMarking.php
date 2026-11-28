<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Domain\ValueObjects;

/**
 * International five-year queen marking colour: 1/6 white, 2/7 yellow, 3/8 red, 4/9 green, 5/0 blue.
 */
final readonly class QueenMarking
{
    private const array Colors = [1 => 'white', 6 => 'white', 2 => 'yellow', 7 => 'yellow', 3 => 'red', 8 => 'red', 4 => 'green', 9 => 'green', 5 => 'blue', 0 => 'blue'];

    public static function colorForYear(int $year): string
    {
        return self::Colors[abs($year) % 10];
    }

    /**
     * Age of the queen in whole years (a queen born in the current year is 0 years old).
     */
    public static function ageInYears(int $queenYear, int $currentYear): int
    {
        return max(0, $currentYear - $queenYear);
    }
}
