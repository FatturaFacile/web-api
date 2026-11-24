<?php

declare(strict_types=1);

namespace App\Services\Security;

/**
 * CSV formula injection defence, shared by every CSV export: a text cell starting with a character a spreadsheet reads
 * as a formula (`=`, `+`, `-`, `@`, tab, carriage return) is prefixed with an apostrophe, so it opens as text. Numbers
 * (including negative ones) are left alone.
 */
final class CsvFormulaNeutralizer
{
    /**
     * @var list<string>
     */
    private const array FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public static function neutralize(mixed $value): string
    {
        $text = (string) $value;

        return $text !== '' && ! is_numeric($text) && in_array($text[0], self::FORMULA_PREFIXES, true) ? "'".$text : $text;
    }

    /**
     * @param  array<int|string, mixed>  $row
     * @return array<int|string, string>
     */
    public static function row(array $row): array
    {
        return array_map(self::neutralize(...), $row);
    }
}
