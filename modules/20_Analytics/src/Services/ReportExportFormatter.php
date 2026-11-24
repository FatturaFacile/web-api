<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use App\Services\Security\CsvFormulaNeutralizer;
use InvalidArgumentException;
use Modules\Analytics\Export\PdfReportRenderer;
use Modules\Analytics\Export\RegionalReportFormatter;
use Modules\DataExport\Services\ExcelCsvWriter;
use RuntimeException;
use stdClass;

/**
 * Turns the rows of a report into a file, writing it while the rows are produced (nothing builds the whole file in
 * memory). Formats:
 * - `csv`: comma separated, UTF-8, CRLF, every cell quoted (RFC 4180 style), for programs;
 * - `excel_csv`: the shared writer of the export module, semicolons and a byte order mark, which Excel opens correctly
 *   in an Italian or Swiss locale;
 * - `xlsx`: a real workbook ({@see XlsxReportWriter}), typed cells, bold frozen header and a Parameters sheet; the
 *   writer works on a temporary file that is then handed to the sink in blocks;
 * - `json`: metadata, columns, rows and totals, written row by row;
 * - `pdf`: an executive document ({@see PdfReportRenderer}), limited to `analytics.reporting.pdf_max_rows` rows.
 * Every text cell is neutralised against spreadsheet formula injection (names of customers and suppliers are user
 * input). Without a `locale` in the context, column headers are the stable keys of the dimensions and measures and the
 * values are raw; with one, headers are translated and csv, xlsx and pdf follow the regional number and date formats
 * of {@see RegionalReportFormatter}.
 */
final readonly class ReportExportFormatter
{
    public const array Formats = ['csv', 'excel_csv', 'xlsx', 'pdf', 'json'];

    private const int Block = 65536;

    /**
     * Inject the collaborators.
     */
    public function __construct(private ExcelCsvWriter $excel, private XlsxReportWriter $xlsx, private PdfReportRenderer $pdf = new PdfReportRenderer) {}

    /**
     * Produces the file and hands it to the sink in blocks.
     *
     * @param  list<array<string, mixed>>  $columns
     * @param  iterable<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $meta  row_count, truncated, period and comparison_period of the report
     * @param  array<string, mixed>  $context  sheet name, dataset, report, specification, time and optionally locale, used by the workbook and the localized formats
     * @param  callable(string): void  $sink
     *
     * @throws InvalidArgumentException for an unknown format
     */
    public function stream(array $columns, iterable $rows, array $meta, array $context, string $format, callable $sink): void
    {
        if (! in_array($format, self::Formats, true)) {
            throw new InvalidArgumentException("Unknown export format [{$format}].");
        }

        $locale = isset($context['locale']) ? new RegionalReportFormatter((string) $context['locale']) : null;

        match (true) {
            $format === 'json' => $this->streamJson($columns, $rows, $meta, $context, $locale, $sink),
            $format === 'pdf' => $this->streamPdf($columns, $rows, $meta, $context, $locale ?? new RegionalReportFormatter(null), $sink),
            $locale !== null && in_array($format, ['csv', 'excel_csv'], true) => $this->streamRegionalCsv($columns, $rows, $context, $locale, $format === 'excel_csv', $sink),
            default => $this->streamLegacy($format, $columns, $rows, $meta, $context, $sink),
        };
    }

    public function extension(string $format): string
    {
        return match ($format) {
            'xlsx' => 'xlsx',
            'pdf' => 'pdf',
            'json' => 'json',
            default => 'csv',
        };
    }

    public function mimeType(string $format): string
    {
        return match ($format) {
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pdf' => 'application/pdf',
            'json' => 'application/json',
            default => 'text/csv; charset=UTF-8',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @param  iterable<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $context
     * @param  callable(string): void  $sink
     */
    private function streamLegacy(string $format, array $columns, iterable $rows, array $meta, array $context, callable $sink): void
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
            default => $this->streamXlsx($columns, $rows, $meta, $context, $sink),
        };
    }

    /**
     * CSV in the regional style: translated headers, locale number and date formats, `;` or `,` as the locale wants, and
     * a byte order mark for the Excel flavour.
     *
     * @param  list<array<string, mixed>>  $columns
     * @param  iterable<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $context
     * @param  callable(string): void  $sink
     */
    private function streamRegionalCsv(array $columns, iterable $rows, array $context, RegionalReportFormatter $regional, bool $bom, callable $sink): void
    {
        $delimiter = $regional->delimiter();
        $line = static fn (array $cells): string => implode($delimiter, array_map(
            static fn (string $cell): string => '"'.str_replace('"', '""', CsvFormulaNeutralizer::neutralize($cell)).'"',
            $cells
        ))."\r\n";
        $dataset = (string) ($context['dataset'] ?? '');

        $sink(($bom ? "\xEF\xBB\xBF" : '').$line(array_map(static fn (array $column): string => $regional->header($dataset, $column), $columns)));
        $buffer = '';

        foreach ($rows as $row) {
            $buffer .= $line(array_map(static fn (array $column): string => $regional->cell($column, $row[$column['key']] ?? null), $columns));

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
     * Structured JSON: `meta`, `columns`, `rows` and `totals` (sums of the additive measures), written row by row. Values
     * stay raw (decimal strings); only the column labels follow the locale.
     *
     * @param  list<array<string, mixed>>  $columns
     * @param  iterable<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $context
     * @param  callable(string): void  $sink
     */
    private function streamJson(array $columns, iterable $rows, array $meta, array $context, ?RegionalReportFormatter $regional, callable $sink): void
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION;
        $dataset = (string) ($context['dataset'] ?? '');
        $described = array_map(static fn (array $column): array => [...$column, 'label' => $regional === null ? ($column['label'] ?? $column['key']) : $regional->header($dataset, $column)], $columns);
        $generated = $context['generated_at'] ?? null;
        $head = json_encode([...$meta, 'dataset' => $dataset, 'report' => $context['report'] ?? null, 'locale' => $regional?->locale, 'generated_at' => $generated instanceof \DateTimeInterface ? $generated->format(DATE_ATOM) : null], $flags);

        $sink('{"meta":'.$head.',"columns":'.json_encode($described, $flags).',"rows":[');
        $totals = [];
        $first = true;
        $buffer = '';

        foreach ($rows as $row) {
            foreach ($columns as $column) {
                if (($column['kind'] ?? '') === 'measure' && ! empty($column['additive']) && is_numeric($row[$column['key']] ?? null) && ($row['__type'] ?? 'data') === 'data') {
                    $totals[$column['key']] = bcadd($totals[$column['key']] ?? '0', (string) $row[$column['key']], 6);
                }
            }

            $buffer .= ($first ? '' : ',').json_encode($row, $flags);
            $first = false;

            if (strlen($buffer) >= self::Block) {
                $sink($buffer);
                $buffer = '';
            }
        }

        $sink($buffer.'],"totals":'.json_encode($totals === [] ? new stdClass : $totals, $flags).'}');
    }

    /**
     * An executive PDF. A PDF cannot be produced in chunks, so it holds at most `analytics.reporting.pdf_max_rows` rows
     * (the rest is reported as truncated) and is handed to the sink in blocks.
     *
     * @param  list<array<string, mixed>>  $columns
     * @param  iterable<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $context
     * @param  callable(string): void  $sink
     */
    private function streamPdf(array $columns, iterable $rows, array $meta, array $context, RegionalReportFormatter $regional, callable $sink): void
    {
        $cap = max(1, (int) config('analytics.reporting.pdf_max_rows', 2000));
        $held = [];

        foreach ($rows as $row) {
            if (count($held) >= $cap) {
                $meta['truncated'] = true;

                break;
            }

            $held[] = $row;
        }

        $context['generated_at'] ??= now();
        $context['title'] ??= $context['report'] ?? $context['dataset'] ?? '';
        $document = $this->pdf->render($columns, $held, $meta, $context, $regional);

        foreach (str_split($document, self::Block) as $block) {
            $sink($block);
        }
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
