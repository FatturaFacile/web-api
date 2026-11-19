<?php

declare(strict_types=1);

namespace Modules\Analytics\Services;

use App\Services\Security\CsvFormulaNeutralizer;
use DateTimeImmutable;
use DateTimeInterface;
use Generator;
use InvalidArgumentException;
use Modules\Analytics\Calculation\FormulaParser;
use Modules\Analytics\Export\RegionalReportFormatter;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\BooleanCell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
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

    private string $dateFormat = self::DateFormat;

    /**
     * One Style object per number format, reused by every cell. OpenSpout registers each Style instance it meets, so a new
     * object per cell would keep one registry entry per cell in memory and defeat the streaming.
     *
     * @var array<string, Style>
     */
    private array $styles = [];

    /**
     * Writes the workbook to a file while the rows are produced. The first rows are buffered only to fit the column
     * widths; the rest goes to the file one row at a time.
     *
     * @param  list<array<string, mixed>>  $columns
     * @param  iterable<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $meta  row_count, truncated, period and comparison_period of the report
     * @param  array{sheet_name: string, dataset: string, report: ?string, spec: array<string, mixed>, generated_at: DateTimeInterface, locale?: string, totals?: bool}  $context
     */
    public function writeToFile(string $path, array $columns, iterable $rows, array $meta, array $context): void
    {
        $regional = isset($context['locale']) ? new RegionalReportFormatter((string) $context['locale']) : null;
        $this->dateFormat = $regional?->dateMask() ?? self::DateFormat;
        $this->styles = [];
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
            array_map(static fn (array $column): string => CsvFormulaNeutralizer::neutralize($regional === null ? $column['key'] : $regional->header((string) $context['dataset'], $column)), $columns),
            (new Style)->withFontBold(true)->withBackgroundColor('E7E6E6'),
        ));

        $count = 0;
        $templates = $this->formulaTemplates($columns);
        $write = function (array $row) use ($writer, $columns, $templates, &$count): void {
            $count++;
            $cells = [];

            foreach ($columns as $index => $column) {
                $cells[] = isset($templates[$index]) ? $this->calculatedCell($column, $templates[$index], $row, $count + 1) : $this->cell($column, $row[$column['key']] ?? null);
            }

            $writer->addRow(new Row($cells));
        };

        foreach ($head as $row) {
            $write($row);
        }

        while ($iterator->valid()) {
            $write($iterator->current());
            $iterator->next();
        }

        if ($regional !== null && $count > 0 && ($context['totals'] ?? true)) {
            $writer->addRow(new Row($this->totalsRow($columns, $count, $regional, (string) $context['dataset'])));
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
     * The Excel formula of each calculated column, as a template with `{r}` for the row number, built once per column. A
     * formula that reads a measure which is not a column of the sheet, or that cannot be expressed in Excel, gets no template and
     * its cells are written as values. Null (a zero divisor, a missing input) is an error cell in Excel, so the whole
     * expression is wrapped in IFERROR(..., "") to keep the empty cell of the value.
     *
     * @param  list<array<string, mixed>>  $columns
     * @return array<int, array{formula: string, refs: list<string>}>
     */
    private function formulaTemplates(array $columns): array
    {
        $letters = [];

        foreach ($columns as $index => $column) {
            $letters[(string) $column['key']] = $this->columnLetter($index + 1);
        }

        $parser = new FormulaParser;
        $templates = [];

        foreach ($columns as $index => $column) {
            if (! isset($column['calc']['formula'], $column['calc']['prefix'])) {
                continue;
            }

            $refs = [];

            try {
                $expression = $this->excelExpression($parser->parse((string) $column['calc']['formula']), (string) $column['calc']['prefix'], $letters, $refs);
            } catch (InvalidArgumentException) {
                continue;
            }

            $templates[$index] = ['formula' => '=IFERROR('.$expression.',"")', 'refs' => array_values(array_unique($refs))];
        }

        return $templates;
    }

    /**
     * @param  array<int, mixed>  $node
     * @param  array<string, string>  $letters
     * @param  list<string>  $refs  keys of the cells the expression reads
     *
     * @throws InvalidArgumentException when a reference is not a column of the sheet
     */
    private function excelExpression(array $node, string $prefix, array $letters, array &$refs): string
    {
        switch ($node[0]) {
            case 'num':
                return $node[1];
            case 'ref':
                $key = $prefix.$node[1];

                if (! isset($letters[$key])) {
                    throw new InvalidArgumentException("No column for [{$key}].");
                }

                $refs[] = $key;

                return $letters[$key].'{r}';
            case 'neg':
                return '(-'.$this->excelExpression($node[1], $prefix, $letters, $refs).')';
            case 'bin':
                return '('.$this->excelExpression($node[2], $prefix, $letters, $refs).$node[1].$this->excelExpression($node[3], $prefix, $letters, $refs).')';
        }

        $arguments = array_map(fn (array $argument): string => $this->excelExpression($argument, $prefix, $letters, $refs), $node[2]);

        return match ($node[1]) {
            'ABS' => 'ABS('.$arguments[0].')',
            'ROUND' => 'ROUND('.$arguments[0].','.($arguments[1] ?? '0').')',
            'NULLIF' => 'IF('.$arguments[0].'='.$arguments[1].',NA(),'.$arguments[0].')',
            'COALESCE' => $this->coalesce($arguments),
            default => throw new InvalidArgumentException("Function {$node[1]} has no Excel counterpart."),
        };
    }

    /**
     * @param  list<string>  $arguments
     */
    private function coalesce(array $arguments): string
    {
        $first = array_shift($arguments);

        return $arguments === [] ? $first : 'IFERROR('.$first.','.$this->coalesce($arguments).')';
    }

    /**
     * A native formula over the cells of the same row, with the computed value stored next to it for readers that do not
     * recalculate. When one of the cells it reads is empty the formula would count it as zero where the report counts it
     * as missing, so that cell is written as the value.
     *
     * @param  array<string, mixed>  $column
     * @param  array{formula: string, refs: list<string>}  $template
     * @param  array<string, mixed>  $row
     */
    private function calculatedCell(array $column, array $template, array $row, int $excelRow): Cell
    {
        $value = $row[$column['key']] ?? null;

        foreach ($template['refs'] as $key) {
            if (! is_numeric($row[$key] ?? null)) {
                return $this->cell($column, $value);
            }
        }

        $format = match ($column['unit'] ?? 'count') {
            'count' => '#,##0',
            'quantity' => '#,##0.0000',
            default => '#,##0.00',
        };

        return new FormulaCell(str_replace('{r}', (string) $excelRow, $template['formula']), is_numeric($value) ? $this->number((string) $value) : null, $this->style($format));
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

        return new NumericCell($this->number((string) $value), $this->style(match ($unit) {
            'count' => '#,##0',
            'quantity' => '#,##0.0000',
            default => '#,##0.00',
        }));
    }

    /**
     * The closing row: native `=SUM(...)` formulas over the additive measure columns (counts and sums, never averages), so
     * the workbook stays live when somebody edits or filters the data.
     *
     * @param  list<array<string, mixed>>  $columns
     * @return list<Cell>
     */
    private function totalsRow(array $columns, int $count, RegionalReportFormatter $regional, string $dataset): array
    {
        $bold = (new Style)->withFontBold(true);
        $cells = [];
        $labelled = false;

        foreach ($columns as $index => $column) {
            $letter = $this->columnLetter($index + 1);

            if (($column['kind'] ?? '') === 'measure' && ! empty($column['additive'])) {
                $format = match ($column['unit'] ?? 'count') {
                    'count' => '#,##0',
                    'quantity' => '#,##0.0000',
                    default => '#,##0.00',
                };
                $cells[] = new FormulaCell("=SUM({$letter}2:{$letter}".($count + 1).')', null, (new Style)->withFontBold(true)->withFormat($format));
            } elseif (! $labelled) {
                $cells[] = new StringCell((string) trans('analytics.export.grand_total', [], $regional->language()), $bold);
                $labelled = true;
            } else {
                $cells[] = new EmptyCell(null);
            }
        }

        return $cells;
    }

    private function columnLetter(int $number): string
    {
        $letters = '';

        while ($number > 0) {
            $number--;
            $letters = chr(65 + $number % 26).$letters;
            $number = intdiv($number, 26);
        }

        return $letters;
    }

    private function style(string $format): Style
    {
        return $this->styles[$format] ??= (new Style)->withFormat($format);
    }

    private function dateCell(string $value): Cell
    {
        try {
            return new DateTimeCell(new DateTimeImmutable($value), $this->style($this->dateFormat));
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
     * @param  array{sheet_name: string, dataset: string, report: ?string, spec: array<string, mixed>, generated_at: DateTimeInterface, locale?: string, totals?: bool}  $context
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
