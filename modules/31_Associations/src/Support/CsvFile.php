<?php

declare(strict_types=1);

namespace Modules\Associations\Support;

use App\Services\Security\CsvFormulaNeutralizer;

/**
 * CSV reading and writing for the registry.
 */
final class CsvFile
{
    /**
     * @return list<array<string, string>> the rows keyed by the lowercase header names
     */
    public static function read(string $path, int $maximumRows): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        $firstLine = (string) fgets($handle);
        rewind($handle);
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $header = fgetcsv($handle, 0, $delimiter, '"', '');

        if (! is_array($header)) {
            fclose($handle);

            return [];
        }

        $keys = array_map(static fn (?string $name): string => strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string) $name))), $header);
        $rows = [];

        while (($values = fgetcsv($handle, 0, $delimiter, '"', '')) !== false && count($rows) <= $maximumRows) {
            if ($values === [null]) {
                continue;
            }

            $padded = array_slice(array_pad($values, count($keys), ''), 0, count($keys));
            $rows[] = array_combine($keys, array_map(static fn (?string $value): string => self::toUtf8(trim((string) $value)), $padded));
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  list<string>  $header
     * @param  iterable<array<int, mixed>>  $rows
     * @return string the CSV with a BOM, so that spreadsheets read it as UTF-8
     */
    public static function write(array $header, iterable $rows): string
    {
        $handle = fopen('php://temp', 'r+b');
        fputcsv($handle, $header, ';', '"', '');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(self::neutralizeFormula(...), $row), ';', '"', '');
        }

        rewind($handle);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return "\xEF\xBB\xBF".$content;
    }

    /**
     * A value starting with a formula character would run as a formula once opened in a spreadsheet.
     */
    private static function neutralizeFormula(mixed $value): string
    {
        return CsvFormulaNeutralizer::neutralize($value);
    }

    private static function toUtf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : (string) mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }
}
