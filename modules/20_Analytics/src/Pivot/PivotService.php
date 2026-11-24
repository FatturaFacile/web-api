<?php

declare(strict_types=1);

namespace Modules\Analytics\Pivot;

use App\Models\User;
use Modules\Analytics\Calculation\CalculatedFields;
use Modules\Analytics\Semantic\Dataset;
use Modules\Analytics\Semantic\Measure;
use Modules\Analytics\Semantic\ReportEngine;
use Modules\Analytics\Semantic\ReportSpec;

/**
 * Turns the flat grouped rows of the report engine into a multi-level pivot matrix with subtotals and grand totals.
 *
 * Every level of total is its own query of the engine (the dimensions of the level only), never a sum of the detail in
 * PHP, so averages and distinct counts stay correct at every level and the same code runs on PostgreSQL and SQLite
 * without `GROUPING SETS`. The calculated fields are evaluated afterwards on the aggregated values of each cell, so a
 * ratio of totals is the ratio of the totals and not the sum of the ratios. All queries go through the engine, so tenant
 * isolation, the module permission, the row cap and the statement timeout apply to each of them.
 */
final readonly class PivotService
{
    private const array Formats = [Measure::UnitCurrency => 'currency', Measure::UnitCount => 'integer', Measure::UnitQuantity => 'number', Measure::UnitPercent => 'percentage'];

    private const int MaxChartCategories = 200;

    public function __construct(private ReportEngine $engine, private CalculatedFields $calculated) {}

    /**
     * @param  list<array{key: string, label: string, formula: string, format: string}>  $calculatedFields
     * @return array<string, mixed>
     */
    public function execute(ReportSpec $base, PivotDefinition $pivot, array $calculatedFields, User $user): array
    {
        $dataset = $this->engine->datasetFor($base->dataset, $user);
        $rowCount = count($pivot->rows);
        $columnCount = count($pivot->columns);
        $rowKeys = $this->keys($pivot->rows);
        $columnKeys = $this->keys($pivot->columns);
        $truncated = false;

        $detail = $this->level($base, $pivot, $rowCount, true, $rowKeys, $columnKeys, $user, $truncated);
        $rowTotals = $columnCount > 0 ? $this->level($base, $pivot, $rowCount, false, $rowKeys, $columnKeys, $user, $truncated) : [];
        $subtotals = [];

        if ($rowCount > 0 && $pivot->subtotals) {
            for ($depth = $rowCount - 1; $depth >= 1; $depth--) {
                $subtotals[$depth] = ['cells' => $this->level($base, $pivot, $depth, true, $rowKeys, $columnKeys, $user, $truncated), 'totals' => $columnCount > 0 ? $this->level($base, $pivot, $depth, false, $rowKeys, $columnKeys, $user, $truncated) : []];
            }
        }

        $grand = ['cells' => [], 'totals' => []];

        if ($rowCount > 0 && $pivot->grandTotals) {
            $grand = ['cells' => $this->level($base, $pivot, 0, true, $rowKeys, $columnKeys, $user, $truncated), 'totals' => $this->level($base, $pivot, 0, false, $rowKeys, $columnKeys, $user, $truncated)];
        }

        $headers = $this->headers($detail, $rowCount);
        $values = $this->values($dataset, $base, $calculatedFields);
        $valueKeys = array_column($values, 'key');
        $rows = [];

        foreach ($this->sortedRowTuples($detail, $rowCount) as $tuple) {
            $rows[] = ['tuple' => $tuple, 'type' => 'data'];
        }

        $ordered = $this->withSubtotals($rows, $rowCount, $pivot->subtotals);
        $matrix = [];

        foreach ($ordered as $entry) {
            $tuple = $entry['tuple'];
            $depth = count($tuple);
            $source = $entry['type'] === 'data' ? ['cells' => $detail, 'totals' => $rowTotals] : ($subtotals[$depth] ?? ['cells' => [], 'totals' => []]);
            $matrix[] = $this->row($entry['type'], $depth, $tuple, $source['cells'], $source['totals'], $headers, $columnCount, $calculatedFields, $pivot, $valueKeys, $rowCount === 0 ? $detail : null);
        }

        if ($rowCount > 0 && $pivot->grandTotals) {
            $matrix[] = $this->row('grand_total', 0, [], $grand['cells'], $grand['totals'], $headers, $columnCount, $calculatedFields, $pivot, $valueKeys, null);
        }

        return [
            'row_dimensions' => $this->dimensionColumns($dataset, $pivot->rows),
            'column_dimensions' => $this->dimensionColumns($dataset, $pivot->columns),
            'values' => $values,
            'column_headers' => array_map(static fn (array $tuple, int $index): array => ['index' => $index, 'tuple' => $tuple, 'label' => self::label($tuple)], $headers, array_keys($headers)),
            'has_total_column' => $columnCount > 0,
            'rows' => $matrix,
            'chart' => $pivot->chart['enabled'] ? $this->chart($pivot, $matrix, $headers, $values, $rowKeys, $columnKeys) : null,
            'meta' => ['dataset' => $dataset->key, 'truncated' => $truncated, 'layout' => $pivot->layout()],
        ];
    }

    /**
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function keys(array $tokens): array
    {
        return array_map(static fn (string $token): string => explode(':', $token)[0], $tokens);
    }

    /**
     * The rows of one level of totals, keyed by the JSON of their row tuple and column tuple.
     *
     * @param  list<string>  $rowKeys
     * @param  list<string>  $columnKeys
     * @return array<string, array{row: list<?string>, column: list<?string>, values: array<string, mixed>}>
     */
    private function level(ReportSpec $base, PivotDefinition $pivot, int $depth, bool $withColumns, array $rowKeys, array $columnKeys, User $user, bool &$truncated): array
    {
        $dimensions = [...array_slice($pivot->rows, 0, $depth), ...($withColumns ? $pivot->columns : [])];
        $input = [...$base->toArray(), 'dimensions' => $dimensions, 'sort' => [], 'limit' => null, 'compare' => 'none'];
        $spec = $this->engine->parse($base->dataset, $input, $user);
        $opened = $this->engine->openExport($spec, $user);
        $truncated = $truncated || (bool) $opened['meta']['truncated'];
        $result = [];

        foreach (($opened['rows'])() as $row) {
            $rowTuple = array_map(static fn (string $key): ?string => isset($row[$key]) ? (string) $row[$key] : null, array_slice($rowKeys, 0, $depth));
            $columnTuple = $withColumns ? array_map(static fn (string $key): ?string => isset($row[$key]) ? (string) $row[$key] : null, $columnKeys) : [];
            $values = [];

            foreach ($base->measures as $measure) {
                $values[$measure] = $row[$measure] ?? null;
            }

            $result[json_encode([$rowTuple, $columnTuple], JSON_THROW_ON_ERROR)] = ['row' => $rowTuple, 'column' => $columnTuple, 'values' => $values];
        }

        return $result;
    }

    /**
     * Distinct column tuples of the detail level, sorted with empty values last.
     *
     * @param  array<string, array{row: list<?string>, column: list<?string>, values: array<string, mixed>}>  $detail
     * @return list<list<?string>>
     */
    private function headers(array $detail, int $rowCount): array
    {
        $headers = [];

        foreach ($detail as $entry) {
            if ($entry['column'] !== []) {
                $headers[json_encode($entry['column'], JSON_THROW_ON_ERROR)] = $entry['column'];
            }
        }

        $headers = array_values($headers);
        usort($headers, self::compareTuples(...));

        return $headers === [] ? [] : $headers;
    }

    /**
     * @param  array<string, array{row: list<?string>, column: list<?string>, values: array<string, mixed>}>  $detail
     * @return list<list<?string>>
     */
    private function sortedRowTuples(array $detail, int $rowCount): array
    {
        $tuples = [];

        foreach ($detail as $entry) {
            $tuples[json_encode($entry['row'], JSON_THROW_ON_ERROR)] = $entry['row'];
        }

        $tuples = array_values($tuples);
        usort($tuples, self::compareTuples(...));

        return $tuples;
    }

    /**
     * Inserts a subtotal row after the last detail row of every group of the outer levels.
     *
     * @param  list<array{tuple: list<?string>, type: string}>  $rows
     * @return list<array{tuple: list<?string>, type: string}>
     */
    private function withSubtotals(array $rows, int $rowCount, bool $subtotals): array
    {
        if (! $subtotals || $rowCount < 2) {
            return $rows;
        }

        $out = [];
        $previous = null;

        foreach ($rows as $row) {
            if ($previous !== null) {
                for ($depth = $rowCount - 1; $depth >= 1; $depth--) {
                    if (array_slice($previous, 0, $depth) !== array_slice($row['tuple'], 0, $depth)) {
                        $out[] = ['tuple' => array_slice($previous, 0, $depth), 'type' => 'subtotal'];
                    }
                }
            }

            $out[] = $row;
            $previous = $row['tuple'];
        }

        if ($previous !== null) {
            for ($depth = $rowCount - 1; $depth >= 1; $depth--) {
                $out[] = ['tuple' => array_slice($previous, 0, $depth), 'type' => 'subtotal'];
            }
        }

        return $out;
    }

    /**
     * @param  list<?string>  $tuple
     * @param  array<string, array{row: list<?string>, column: list<?string>, values: array<string, mixed>}>  $cells
     * @param  array<string, array{row: list<?string>, column: list<?string>, values: array<string, mixed>}>  $totals
     * @param  list<list<?string>>  $headers
     * @param  list<array{key: string, label: string, formula: string, format: string}>  $calculatedFields
     * @param  list<string>  $valueKeys
     * @param  array<string, array{row: list<?string>, column: list<?string>, values: array<string, mixed>}>|null  $columnOnly
     * @return array<string, mixed>
     */
    private function row(string $type, int $depth, array $tuple, array $cells, array $totals, array $headers, int $columnCount, array $calculatedFields, PivotDefinition $pivot, array $valueKeys, ?array $columnOnly): array
    {
        $cellList = [];

        foreach ($headers as $header) {
            $found = $cells[json_encode([$tuple, $header], JSON_THROW_ON_ERROR)] ?? null;
            $cellList[] = $found === null ? null : $this->cell($found['values'], $calculatedFields, $pivot);
        }

        $totalSource = $columnCount > 0 ? ($totals[json_encode([$tuple, []], JSON_THROW_ON_ERROR)] ?? null) : ($cells[json_encode([$tuple, []], JSON_THROW_ON_ERROR)] ?? null);

        return [
            'type' => $type,
            'level' => $depth,
            'keys' => $tuple,
            'label' => self::label($tuple),
            'cells' => $columnCount > 0 ? $cellList : [],
            'total' => $totalSource === null ? null : $this->cell($totalSource['values'], $calculatedFields, $pivot),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<array{key: string, label: string, formula: string, format: string}>  $calculatedFields
     * @return array{values: array<string, mixed>, styles: array<string, array{background: string, text: string}>}
     */
    private function cell(array $values, array $calculatedFields, PivotDefinition $pivot): array
    {
        $values = $this->calculated->apply($values, $calculatedFields);
        $styles = [];

        foreach ($pivot->formatting as $rule) {
            $value = $values[$rule['target']] ?? null;

            if ($value !== null && $this->matches($rule, (string) $value)) {
                $styles[$rule['target']] = ['background' => $rule['background'], 'text' => $rule['text']];
            }
        }

        return ['values' => $values, 'styles' => $styles];
    }

    /**
     * @param  array{target: string, operator: string, value: string, value2: ?string, background: string, text: string}  $rule
     */
    private function matches(array $rule, string $value): bool
    {
        $compare = bccomp($value, $rule['value'], 6);

        return match ($rule['operator']) {
            'gt' => $compare > 0,
            'gte' => $compare >= 0,
            'lt' => $compare < 0,
            'lte' => $compare <= 0,
            'eq' => $compare === 0,
            'neq' => $compare !== 0,
            default => $compare >= 0 && bccomp($value, (string) $rule['value2'], 6) <= 0,
        };
    }

    /**
     * @param  list<array{key: string, label: string, formula: string, format: string}>  $calculatedFields
     * @return list<array{key: string, label: string, unit: string, format: string, calculated: bool}>
     */
    private function values(Dataset $dataset, ReportSpec $spec, array $calculatedFields): array
    {
        $values = [];

        foreach ($spec->measures as $key) {
            $measure = $dataset->measures[$key];
            $values[] = ['key' => $key, 'label' => $measure->label, 'unit' => $measure->unit, 'format' => self::Formats[$measure->unit], 'calculated' => false];
        }

        foreach ($calculatedFields as $field) {
            $values[] = ['key' => $field['key'], 'label' => $field['label'], 'unit' => 'number', 'format' => $field['format'], 'calculated' => true, 'formula' => $field['formula']];
        }

        return $values;
    }

    /**
     * @param  list<string>  $tokens
     * @return list<array<string, mixed>>
     */
    private function dimensionColumns(Dataset $dataset, array $tokens): array
    {
        return array_map(static function (string $token) use ($dataset): array {
            [$key, $granularity] = [...explode(':', $token), null];
            $dimension = $dataset->dimensions[$key];

            return ['key' => $key, 'label' => $dimension->label, 'type' => $dimension->type, 'granularity' => $dimension->type === 'date' ? ($granularity ?? 'day') : null];
        }, $tokens);
    }

    /**
     * @param  list<array<string, mixed>>  $matrix
     * @param  list<list<?string>>  $headers
     * @param  list<array{key: string, label: string, unit: string, format: string, calculated: bool}>  $values
     * @param  list<string>  $rowKeys
     * @param  list<string>  $columnKeys
     * @return array<string, mixed>
     */
    private function chart(PivotDefinition $pivot, array $matrix, array $headers, array $values, array $rowKeys, array $columnKeys): array
    {
        $config = $pivot->chart;
        $selected = $config['series'] === [] ? array_column($values, 'key') : $config['series'];
        $labels = array_column($values, 'label', 'key');
        $detail = array_values(array_filter($matrix, static fn (array $row): bool => $row['type'] === 'data'));
        $transposed = $config['xAxis'] !== null && in_array($config['xAxis'], $columnKeys, true) && $headers !== [];
        $series = [];

        if ($transposed) {
            $categories = array_map(static fn (array $tuple): string => self::label($tuple), $headers);

            foreach ($detail as $row) {
                foreach ($selected as $key) {
                    $series[] = ['name' => $labels[$key].' · '.$row['label'], 'data' => array_map(static fn (?array $cell): ?string => $cell['values'][$key] ?? null, $row['cells'])];
                }
            }
        } else {
            $detail = array_slice($detail, 0, self::MaxChartCategories);
            $categories = array_column($detail, 'label');

            foreach ($selected as $key) {
                if ($headers === []) {
                    $series[] = ['name' => $labels[$key], 'data' => array_map(static fn (array $row): ?string => $row['total']['values'][$key] ?? null, $detail)];

                    continue;
                }

                foreach ($headers as $index => $header) {
                    $series[] = ['name' => $labels[$key].' · '.self::label($header), 'data' => array_map(static fn (array $row): ?string => $row['cells'][$index]['values'][$key] ?? null, $detail)];
                }
            }
        }

        return ['type' => $config['chartType'], 'categories' => $categories, 'series' => $series, 'show_legend' => $config['showLegend'], 'show_data_labels' => $config['showDataLabels']];
    }

    /**
     * @param  list<?string>  $tuple
     */
    private static function label(array $tuple): string
    {
        return implode(' / ', array_map(static fn (?string $part): string => $part === null || $part === '' ? '-' : $part, $tuple));
    }

    /**
     * @param  list<?string>  $a
     * @param  list<?string>  $b
     */
    private static function compareTuples(array $a, array $b): int
    {
        foreach ($a as $index => $left) {
            $right = $b[$index] ?? null;

            if ($left === $right) {
                continue;
            }

            if ($left === null) {
                return 1;
            }

            if ($right === null) {
                return -1;
            }

            return strnatcasecmp($left, $right) ?: strcmp($left, $right);
        }

        return 0;
    }
}
