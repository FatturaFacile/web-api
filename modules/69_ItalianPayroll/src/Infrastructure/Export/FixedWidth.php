<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Infrastructure\Export;

/**
 * Field helpers of the positional (fixed-width) layouts: ASCII only, truncated or padded to the exact width.
 */
final class FixedWidth
{
    public static function text(string $value, int $width): string
    {
        $ascii = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return str_pad(substr(strtoupper($ascii), 0, $width), $width);
    }

    /** A number without decimal point (decimals implied), zero-padded to the left. */
    public static function number(string $value, int $width, int $decimals = 2): string
    {
        $scaled = (string) (int) round(((float) $value) * (10 ** $decimals));

        return str_pad(substr($scaled, -$width), $width, '0', STR_PAD_LEFT);
    }
}
