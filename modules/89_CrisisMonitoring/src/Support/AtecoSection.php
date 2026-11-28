<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Support;

/**
 * The ATECO 2007 sections (A to S) of the CNDCEC sectoral thresholds, and the section of an ATECO code from its division
 * (the first two digits).
 */
final class AtecoSection
{
    /** @var list<string> */
    public const array Sections = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S'];

    /**
     * Highest division of each section.
     *
     * @var array<string, int>
     */
    private const array LastDivision = [
        'A' => 3, 'B' => 9, 'C' => 33, 'D' => 35, 'E' => 39, 'F' => 43, 'G' => 47, 'H' => 53, 'I' => 56, 'J' => 63,
        'K' => 66, 'L' => 68, 'M' => 75, 'N' => 82, 'O' => 84, 'P' => 85, 'Q' => 88, 'R' => 93, 'S' => 96,
    ];

    /**
     * The section of an ATECO code ("62.01", "6201", "62"), or null when the code is empty or outside A-S.
     */
    public static function fromCode(?string $code): ?string
    {
        if ($code === null || preg_match('/^\s*(\d{2})/', $code, $match) !== 1) {
            return null;
        }

        $division = (int) $match[1];

        foreach (self::LastDivision as $section => $last) {
            if ($division <= $last) {
                return $division >= 1 ? $section : null;
            }
        }

        return null;
    }

    public static function isValid(?string $section): bool
    {
        return $section !== null && in_array($section, self::Sections, true);
    }
}
