<?php

declare(strict_types=1);

namespace Modules\Payroll\Exports;

use App\Services\Security\CsvFormulaNeutralizer;
use DateTimeInterface;

/**
 * Semicolon separated values with CRLF line ends, as written by the desktop exports. Text that a spreadsheet would
 * run as a formula is neutralised.
 */
final class CsvBuilder
{
    private const string ByteOrderMark = "\xEF\xBB\xBF";

    private string $content = '';

    public function row(mixed ...$values): void
    {
        $this->content .= implode(';', array_map(static fn (mixed $value): string => self::quote(self::format($value)), $values))."\r\n";
    }

    public function encoded(): string
    {
        return self::ByteOrderMark.$this->content;
    }

    private static function format(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            is_bool($value) => $value ? '1' : '0',
            default => CsvFormulaNeutralizer::neutralize($value),
        };
    }

    private static function quote(string $text): string
    {
        return strpbrk($text, ";\"\r\n") === false ? $text : '"'.str_replace('"', '""', $text).'"';
    }
}
