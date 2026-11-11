<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use App\Services\Security\CsvFormulaNeutralizer;
use InvalidArgumentException;
use Modules\DataExport\Services\ExcelCsvWriter;

/**
 * Turns the result of a report into a file. Two formats, both CSV (no spreadsheet library is installed, and the product
 * does not claim to produce XLSX):
 * - `csv`: comma separated, UTF-8, CRLF, every cell quoted (RFC 4180 style), for programs;
 * - `excel_csv`: the shared writer of the export module, semicolons and a byte order mark, which Excel opens correctly
 *   in an Italian or Swiss locale.
 * Every text cell is neutralised against spreadsheet formula injection (names of customers and suppliers are user
 * input). Column headers are the stable keys of the dimensions and measures.
 */
final readonly class ReportExportFormatter
{
    public const array Formats = ['csv', 'excel_csv'];

    /**
     * Inject the collaborators.
     */
    public function __construct(private ExcelCsvWriter $excel) {}

    /**
     * @param  array{columns: list<array<string, mixed>>, rows: list<array<string, mixed>>}  $result
     *
     * @throws InvalidArgumentException for an unknown format
     */
    public function format(array $result, string $format): string
    {
        $keys = array_map(static fn (array $column): string => (string) $column['key'], $result['columns']);
        $rows = array_map(static fn (array $row): array => array_map(
            static fn (string $key): mixed => is_bool($row[$key] ?? null) ? ($row[$key] ? 'true' : 'false') : ($row[$key] ?? ''),
            $keys
        ), $result['rows']);

        return match ($format) {
            'excel_csv' => $this->excel->write($keys, $rows),
            'csv' => $this->plain($keys, $rows),
            default => throw new InvalidArgumentException("Unknown export format [{$format}]."),
        };
    }

    public function extension(string $format): string
    {
        return 'csv';
    }

    public function mimeType(string $format): string
    {
        return 'text/csv; charset=UTF-8';
    }

    /**
     * @param  list<string>  $header
     * @param  list<list<mixed>>  $rows
     */
    private function plain(array $header, array $rows): string
    {
        $line = static fn (array $cells): string => implode(',', array_map(
            static fn (mixed $cell): string => '"'.str_replace('"', '""', CsvFormulaNeutralizer::neutralize($cell)).'"',
            $cells
        ));

        return implode("\r\n", [$line($header), ...array_map($line, $rows)])."\r\n";
    }
}
