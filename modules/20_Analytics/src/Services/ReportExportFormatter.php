<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use App\Services\Security\CsvFormulaNeutralizer;
use InvalidArgumentException;
use Modules\DataExport\Services\ExcelCsvWriter;

/**
 * Turns the result of a report into a file. Three formats:
 * - `csv`: comma separated, UTF-8, CRLF, every cell quoted (RFC 4180 style), for programs;
 * - `excel_csv`: the shared writer of the export module, semicolons and a byte order mark, which Excel opens correctly
 *   in an Italian or Swiss locale;
 * - `xlsx`: a real workbook ({@see XlsxReportWriter}), typed cells, bold frozen header and a Parameters sheet.
 * Every text cell is neutralised against spreadsheet formula injection (names of customers and suppliers are user
 * input). Column headers are the stable keys of the dimensions and measures.
 */
final readonly class ReportExportFormatter
{
    public const array Formats = ['csv', 'excel_csv', 'xlsx'];

    /**
     * Inject the collaborators.
     */
    public function __construct(private ExcelCsvWriter $excel, private XlsxReportWriter $xlsx) {}

    /**
     * @param  array{columns: list<array<string, mixed>>, rows: list<array<string, mixed>>, meta?: array<string, mixed>}  $result
     * @param  array<string, mixed>  $context  sheet name, dataset, report, specification and time, used by the xlsx workbook
     *
     * @throws InvalidArgumentException for an unknown format
     */
    public function format(array $result, string $format, array $context = []): string
    {
        $keys = array_map(static fn (array $column): string => (string) $column['key'], $result['columns']);
        $rows = array_map(static fn (array $row): array => array_map(
            static fn (string $key): mixed => is_bool($row[$key] ?? null) ? ($row[$key] ? 'true' : 'false') : ($row[$key] ?? ''),
            $keys
        ), $result['rows']);

        return match ($format) {
            'excel_csv' => $this->excel->write($keys, $rows),
            'csv' => $this->plain($keys, $rows),
            'xlsx' => $this->xlsx->render($result, $context),
            default => throw new InvalidArgumentException("Unknown export format [{$format}]."),
        };
    }

    public function extension(string $format): string
    {
        return $format === 'xlsx' ? 'xlsx' : 'csv';
    }

    public function mimeType(string $format): string
    {
        return $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=UTF-8';
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
