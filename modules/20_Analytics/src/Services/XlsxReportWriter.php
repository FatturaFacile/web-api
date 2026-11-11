<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use App\Services\Security\CsvFormulaNeutralizer;
use DateTimeImmutable;
use DateTimeInterface;
use Generator;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\BooleanCell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Properties;
use OpenSpout\Writer\XLSX\Writer;
use Throwable;

/**
 * Writes the result of a report as a real .xlsx workbook with the OpenSpout streaming writer (rows go to a temporary
 * file, not to a growing in-memory document; the report is already capped at the row limit).
 *
 * Sheet 1 is the data: bold header row, frozen, widths fitted to the content, cells typed (counts and amounts as
 * numbers with a number format, date dimensions as dates, booleans as booleans, the rest as text). Text is neutralised
 * against formula injection like in the CSV formats, and written as strings, never as formulas.
 * Sheet 2, "Parameters", records what the numbers mean: dataset, report, dimensions, measures, filters, sort, top-N,
 * period, comparison, row count, truncation and generation time.
 */
final class XlsxReportWriter
{
    private const string DateFormat = 'yyyy-mm-dd';

    /**
     * Writes the workbook to a file while the rows are produced. The first rows are buffered only to fit the column
     * widths; the rest goes to the file one row at a time.
     *
     * @param  list<array<string, mixed>>  $columns
     * @param  iterable<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $meta  row_count, truncated, period and comparison_period of the report
     * @param  array{sheet_name: string, dataset: string, report: ?string, spec: array<string, mixed>, generated_at: DateTimeInterface}  $context
     */
    public function writeToFile(string $path, array $columns, iterable $rows, array $meta, array $context): void
    {
        $iterator = (static function () use ($rows): Generator {
            yield from $rows;
        })();
        $head = [];

        while ($iterator->valid() && count($head) < 500) {
            $head[] = $iterator->current();
            $iterator->next();
        }

        $options = new Options(properties: new Properties(title: $context['sheet_name'], application: 'Fattura Facile', creator: 'Fattura Facile', lastModifiedBy: 'Fattura Facile'));

        foreach ($this->widths($columns, $head) as $index => $width) {
            $options->setColumnWidth($width, $index + 1);
        }

        $writer = new Writer($options);
        $writer->openToFile($path);
        $sheet = $writer->getCurrentSheet();
        $sheet->setName($this->sheetName($context['sheet_name']));
        $sheet->setSheetView(new SheetView(freezeRow: 2));

        $writer->addRow(Row::fromValuesWithStyle(
            array_map(static fn (array $column): string => CsvFormulaNeutralizer::neutralize($column['key']), $columns),
            (new Style)->withFontBold(true)->withBackgroundColor('E7E6E6'),
        ));

        $write = fn (array $row) => $writer->addRow(new Row(array_map(fn (array $column): Cell => $this->cell($column, $row[$column['key']] ?? null), $columns)));

        foreach ($head as $row) {
            $write($row);
        }

        while ($iterator->valid()) {
            $write($iterator->current());
            $iterator->next();
        }

        $parameters = $writer->addNewSheetAndMakeItCurrent();
        $parameters->setName('Parameters');
        $parameters->setColumnWidth(28, 1);
        $parameters->setColumnWidth(80, 2);

        foreach ($this->parameters($meta, $context) as [$label, $value]) {
            $writer->addRow(new Row([
                new StringCell($label, (new Style)->withFontBold(true)),
                $value instanceof DateTimeInterface ? new DateTimeCell($value, (new Style)->withFormat('yyyy-mm-dd hh:mm:ss')) : new StringCell(CsvFormulaNeutralizer::neutralize($value)),
            ]));
        }

        $writer->close();
    }

    /**
     * @param  array<string, mixed>  $column
     */
    private function cell(array $column, mixed $value): Cell
    {
        if ($value === null || $value === '') {
            return new EmptyCell(null);
        }

        if (is_bool($value)) {
            return new BooleanCell($value);
        }

        $kind = $column['kind'] ?? 'measure';

        if ($kind === 'dimension') {
            return match ($column['type'] ?? 'string') {
                'date' => $this->dateCell((string) $value),
                'number' => is_numeric($value) ? new NumericCell($this->number((string) $value)) : new StringCell(CsvFormulaNeutralizer::neutralize($value)),
                default => new StringCell(CsvFormulaNeutralizer::neutralize($value)),
            };
        }

        $unit = $column['unit'] ?? 'count';

        return new NumericCell($this->number((string) $value), (new Style)->withFormat(match ($unit) {
            'count' => '#,##0',
            'quantity' => '#,##0.0000',
            default => '#,##0.00',
        }));
    }

    private function dateCell(string $value): Cell
    {
        try {
            return new DateTimeCell(new DateTimeImmutable($value), (new Style)->withFormat(self::DateFormat));
        } catch (Throwable) {
            return new StringCell(CsvFormulaNeutralizer::neutralize($value));
        }
    }

    private function number(string $value): float|int
    {
        return preg_match('/^-?\d+$/', $value) === 1 && strlen($value) < 16 ? (int) $value : (float) $value;
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @param  list<array<string, mixed>>  $sample
     * @return list<float>
     */
    private function widths(array $columns, array $sample): array
    {
        $widths = [];

        foreach ($columns as $column) {
            $longest = mb_strlen((string) $column['key']);

            foreach ($sample as $row) {
                $longest = max($longest, mb_strlen((string) ($row[$column['key']] ?? '')));
            }

            $widths[] = (float) max(10, min(60, $longest + 3));
        }

        return $widths;
    }

    private function sheetName(string $name): string
    {
        $clean = trim((string) preg_replace('/[\\\\\/\?\*\[\]:\s]+/', ' ', $name), " '");
        $clean = trim(mb_substr($clean === '' ? 'Report' : $clean, 0, 31), " '");

        return $clean === '' || strcasecmp($clean, 'Parameters') === 0 ? 'Report' : $clean;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array{sheet_name: string, dataset: string, report: ?string, spec: array<string, mixed>, generated_at: DateTimeInterface}  $context
     * @return list<array{0: string, 1: string|DateTimeInterface}>
     */
    private function parameters(array $meta, array $context): array
    {
        $spec = $context['spec'];
        $period = $meta['period'] ?? null;
        $comparison = $meta['comparison_period'] ?? null;
        $filters = array_map(static fn (array $filter): string => $filter['field'].' '.$filter['operator'].' '.json_encode($filter['value'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (array) ($spec['filters'] ?? []));
        $sort = array_map(static fn (array $item): string => $item['field'].' '.$item['direction'], (array) ($spec['sort'] ?? []));

        return [
            ['Dataset', $context['dataset']],
            ['Report', (string) ($context['report'] ?? '-')],
            ['Dimensions', implode(', ', (array) ($spec['dimensions'] ?? [])) ?: '-'],
            ['Measures', implode(', ', (array) ($spec['measures'] ?? []))],
            ['Filters', $filters === [] ? '-' : implode('; ', $filters)],
            ['Sort', $sort === [] ? '-' : implode(', ', $sort)],
            ['Top-N', (string) ($spec['limit'] ?? '-')],
            ['Period', is_array($period) ? $period['from'].' - '.$period['to'] : '-'],
            ['Comparison', (string) ($spec['compare'] ?? 'none').(is_array($comparison) ? ' ('.$comparison['from'].' - '.$comparison['to'].')' : '')],
            ['Rows', (string) ($meta['row_count'] ?? 0)],
            ['Truncated by the row limit', ! empty($meta['truncated']) ? 'yes' : 'no'],
            ['Generated at', $context['generated_at']],
        ];
    }
}
