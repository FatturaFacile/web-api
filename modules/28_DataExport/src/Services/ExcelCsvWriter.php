<?php

declare(strict_types=1);

namespace Modules\DataExport\Services;

/**
 * CSV that Excel opens correctly in an Italian locale: semicolons, a UTF-8 byte order mark and CRLF. Text cells that a
 * spreadsheet would run as a formula are neutralised.
 */
final class ExcelCsvWriter
{
    /**
     * @param  list<string>  $header
     * @param  iterable<array<int, mixed>>  $rows
     */
    public function write(array $header, iterable $rows): string
    {
        $lines = [$this->line($header)];

        foreach ($rows as $row) {
            $lines[] = $this->line($row);
        }

        return "\xEF\xBB\xBF".implode("\r\n", $lines)."\r\n";
    }

    /**
     * @param  array<int, mixed>  $cells
     */
    private function line(array $cells): string
    {
        return implode(';', array_map($this->cell(...), $cells));
    }

    private function cell(mixed $value): string
    {
        $text = (string) $value;

        if ($text !== '' && ! is_numeric($text) && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $text = "'".$text;
        }

        return '"'.str_replace('"', '""', $text).'"';
    }
}
