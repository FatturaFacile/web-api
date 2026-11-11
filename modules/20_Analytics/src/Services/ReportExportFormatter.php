<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use App\Services\Security\CsvFormulaNeutralizer;
use InvalidArgumentException;
use Modules\DataExport\Services\ExcelCsvWriter;
use RuntimeException;

/**
 * Turns the rows of a report into a file, writing it while the rows are produced (nothing builds the whole file in
 * memory). Three formats:
 * - `csv`: comma separated, UTF-8, CRLF, every cell quoted (RFC 4180 style), for programs;
 * - `excel_csv`: the shared writer of the export module, semicolons and a byte order mark, which Excel opens correctly
 *   in an Italian or Swiss locale;
 * - `xlsx`: a real workbook ({@see XlsxReportWriter}), typed cells, bold frozen header and a Parameters sheet; the
 *   writer works on a temporary file that is then handed to the sink in blocks.
 * Every text cell is neutralised against spreadsheet formula injection (names of customers and suppliers are user
 * input). Column headers are the stable keys of the dimensions and measures.
 */
final readonly class ReportExportFormatter
{
    public const array Formats = ['csv', 'excel_csv', 'xlsx'];

    private const int Block = 65536;

    /**
     * Inject the collaborators.
     */
    public function __construct(private ExcelCsvWriter $excel, private XlsxReportWriter $xlsx) {}

    /**
     * Produces the file and hands it to the sink in blocks.
     *
     * @param  list<array<string, mixed>>  $columns
     * @param  iterable<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $meta  row_count, truncated, period and comparison_period of the report
     * @param  array<string, mixed>  $context  sheet name, dataset, report, specification and time, used by the xlsx workbook
     * @param  callable(string): void  $sink
     *
     * @throws InvalidArgumentException for an unknown format
     */
    public function stream(array $columns, iterable $rows, array $meta, array $context, string $format, callable $sink): void
    {
        $keys = array_map(static fn (array $column): string => (string) $column['key'], $columns);
        $cells = static function () use ($rows, $keys): iterable {
            foreach ($rows as $row) {
                yield array_map(static fn (string $key): mixed => is_bool($row[$key] ?? null) ? ($row[$key] ? 'true' : 'false') : ($row[$key] ?? ''), $keys);
            }
        };

        match ($format) {
            'csv' => $this->streamCsv($keys, $cells(), $sink),
            'excel_csv' => $this->streamExcelCsv($keys, $cells(), $sink),
            'xlsx' => $this->streamXlsx($columns, $rows, $meta, $context, $sink),
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
     * @param  list<string>  $keys
     * @param  iterable<list<mixed>>  $rows
     * @param  callable(string): void  $sink
     */
    private function streamCsv(array $keys, iterable $rows, callable $sink): void
    {
        $line = static fn (array $cells): string => implode(',', array_map(
            static fn (mixed $cell): string => '"'.str_replace('"', '""', CsvFormulaNeutralizer::neutralize($cell)).'"',
            $cells
        ))."\r\n";

        $sink($line($keys));
        $buffer = '';

        foreach ($rows as $row) {
            $buffer .= $line($row);

            if (strlen($buffer) >= self::Block) {
                $sink($buffer);
                $buffer = '';
            }
        }

        if ($buffer !== '') {
            $sink($buffer);
        }
    }

    /**
     * @param  list<string>  $keys
     * @param  iterable<list<mixed>>  $rows
     * @param  callable(string): void  $sink
     */
    private function streamExcelCsv(array $keys, iterable $rows, callable $sink): void
    {
        $sink($this->excel->write($keys, []));
        $buffer = [];

        foreach ($rows as $row) {
            $buffer[] = $row;

            if (count($buffer) >= 500) {
                $sink($this->excel->rows($buffer));
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $sink($this->excel->rows($buffer));
        }
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @param  iterable<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $context
     * @param  callable(string): void  $sink
     */
    private function streamXlsx(array $columns, iterable $rows, array $meta, array $context, callable $sink): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');

        if ($path === false) {
            throw new RuntimeException('A temporary file could not be created.');
        }

        try {
            $this->xlsx->writeToFile($path, $columns, $rows, $meta, $context);
            $handle = fopen($path, 'rb');

            if ($handle === false) {
                throw new RuntimeException('The workbook could not be read back.');
            }

            while (! feof($handle)) {
                $block = fread($handle, self::Block);

                if ($block !== false && $block !== '') {
                    $sink($block);
                }
            }

            fclose($handle);
        } finally {
            @unlink($path);
        }
    }
}
