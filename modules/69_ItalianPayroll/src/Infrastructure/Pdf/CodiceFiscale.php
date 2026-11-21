<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Infrastructure\Pdf;

/**
 * Finds and validates the Italian personal tax code (codice fiscale, 16 characters) in free text. A match must pass the
 * control character check, so that a code-like string in a payslip (a protocol, a cost center) is not taken for one.
 */
final class CodiceFiscale
{
    private const string Pattern = '/(?<![A-Z0-9])[A-Z]{6}[0-9LMNP-V]{2}[A-EHLMPR-T][0-9LMNP-V]{2}[A-Z][0-9LMNP-V]{3}[A-Z](?![A-Z0-9])/';

    private const array Odd = [
        '0' => 1, '1' => 0, '2' => 5, '3' => 7, '4' => 9, '5' => 13, '6' => 15, '7' => 17, '8' => 19, '9' => 21,
        'A' => 1, 'B' => 0, 'C' => 5, 'D' => 7, 'E' => 9, 'F' => 13, 'G' => 15, 'H' => 17, 'I' => 19, 'J' => 21,
        'K' => 2, 'L' => 4, 'M' => 18, 'N' => 20, 'O' => 11, 'P' => 3, 'Q' => 6, 'R' => 8, 'S' => 12, 'T' => 14,
        'U' => 16, 'V' => 10, 'W' => 22, 'X' => 25, 'Y' => 24, 'Z' => 23,
    ];

    public static function normalize(string $value): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $value));
    }

    public static function isValid(string $code): bool
    {
        $code = self::normalize($code);

        if (preg_match(self::Pattern, $code) !== 1) {
            return false;
        }

        $sum = 0;

        foreach (str_split(substr($code, 0, 15)) as $index => $char) {
            $sum += $index % 2 === 0 ? self::Odd[$char] : (ctype_digit($char) ? (int) $char : ord($char) - 65);
        }

        return chr(65 + $sum % 26) === $code[15];
    }

    /**
     * The first valid tax code in the text, or null.
     */
    public static function find(string $text): ?string
    {
        foreach (self::findAll($text) as $code) {
            return $code;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function findAll(string $text): array
    {
        preg_match_all(self::Pattern, strtoupper($text), $matches);

        return array_values(array_filter($matches[0], self::isValid(...)));
    }
}
