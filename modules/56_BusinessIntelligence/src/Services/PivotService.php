<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Services;

use Illuminate\Validation\ValidationException;

/**
 * Turns the flat result of a query into a pivot table: the chosen dimensions become the rows and the columns, the chosen
 * measures become the cells. Cells that fall on the same row and column are added up when the measure is additive (sum or
 * count); the totals of a measure that cannot be added (average, distinct count, percentage, calculated measure) are null
 * instead of a wrong number. The layout only names columns of the result, so it can never reach anything the query did
 * not select.
 */
final class PivotService
{
    /**
     * Checks a pivot layout against the dimensions and measures of a query.
     *
     * @param  array<string, mixed>  $layout  `rows`, `columns` (dimension keys) and `values` (measure keys)
     * @param  list<string>  $dimensions  dimension keys of the query
     * @param  list<string>  $measures  measure keys of the query, calculated ones included
     * @return array{rows: list<string>, columns: list<string>, values: list<string>}
     *
     * @throws ValidationException
     */
    public function validateLayout(array $layout, array $dimensions, array $measures, string $errorPrefix = 'pivot.'): array
    {
        $errors = [];
        $clean = ['rows' => [], 'columns' => [], 'values' => []];
        $used = [];

        foreach (['rows' => $dimensions, 'columns' => $dimensions, 'values' => $measures] as $part => $allowed) {
            foreach ((array) ($layout[$part] ?? []) as $index => $key) {
                if (! is_string($key) || ! in_array($key, $allowed, true)) {
                    $errors["{$errorPrefix}{$part}.{$index}"][] = __($part === 'values' ? 'bi_errors.pivot.measure_missing' : 'bi_errors.pivot.dimension_missing');
                } elseif ($part !== 'values' && in_array($key, $used, true)) {
                    $errors["{$errorPrefix}{$part}.{$index}"][] = __('bi_errors.pivot.dimension_repeated', ['key' => $key]);
                } else {
                    $clean[$part][] = $key;

                    if ($part !== 'values') {
                        $used[] = $key;
                    }
                }
            }
        }

        if ($clean['values'] === []) {
            $errors["{$errorPrefix}values"][] = __('bi_errors.pivot.no_values');
        }

        if ($clean['rows'] === [] && $clean['columns'] === []) {
            $errors["{$errorPrefix}rows"][] = __('bi_errors.pivot.no_axis');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    /**
     * @param  array{columns: list<array<string, mixed>>, rows: list<array<string, mixed>>, meta: array<string, mixed>}  $result
     * @param  array{rows: list<string>, columns: list<string>, values: list<string>}  $layout  already validated
     * @param  list<string>  $additive  measure keys whose figures can be added up
     * @return array<string, mixed>
     *
     * @throws ValidationException when the pivot would be larger than the limit of cells
     */
    public function build(array $result, array $layout, array $additive): array
    {
        $labels = [];
        $units = [];

        foreach ($result['columns'] as $column) {
            $labels[(string) $column['key']] = (string) $column['label'];
            $units[(string) $column['key']] = $column['unit'] ?? null;
        }

        $columnIds = [];
        $columnMembers = [];
        $rowIds = [];
        $rowMembers = [];
        $cells = [];

        foreach ($result['rows'] as $row) {
            $rowMember = array_map(static fn (string $key): ?string => self::member($row[$key] ?? null), $layout['rows']);
            $columnMember = array_map(static fn (string $key): ?string => self::member($row[$key] ?? null), $layout['columns']);
            $rowId = json_encode($rowMember, JSON_THROW_ON_ERROR);
            $columnId = json_encode($columnMember, JSON_THROW_ON_ERROR);
            $rowMembers[$rowId] = $rowMember;
            $columnMembers[$columnId] = $columnMember;
            $rowIds[$rowId] = true;
            $columnIds[$columnId] = true;

            foreach ($layout['values'] as $measure) {
                $value = $row[$measure] ?? null;

                if (in_array($measure, $additive, true)) {
                    $cells[$rowId][$columnId][$measure] = $this->add($cells[$rowId][$columnId][$measure] ?? null, $value);
                } elseif (! array_key_exists($measure, $cells[$rowId][$columnId] ?? [])) {
                    $cells[$rowId][$columnId][$measure] = $value === null ? null : (string) $value;
                } else {
                    $cells[$rowId][$columnId][$measure] = null;
                }
            }
        }

        if (count($rowIds) * max(1, count($columnIds)) * count($layout['values']) > (int) config('business_intelligence.pivot_max_cells', 5000)) {
            throw ValidationException::withMessages(['pivot' => [__('bi_errors.pivot.too_large', ['max' => (int) config('business_intelligence.pivot_max_cells', 5000)])]]);
        }

        uasort($rowMembers, static fn (array $a, array $b): int => strnatcasecmp(implode("\x1f", array_map('strval', $a)), implode("\x1f", array_map('strval', $b))));
        uasort($columnMembers, static fn (array $a, array $b): int => strnatcasecmp(implode("\x1f", array_map('strval', $a)), implode("\x1f", array_map('strval', $b))));

        $columnIndex = array_flip(array_keys($columnMembers));
        $outRows = [];
        $columnTotals = [];
        $grand = array_fill_keys($layout['values'], null);

        foreach ($rowMembers as $rowId => $member) {
            $rowCells = [];
            $rowTotals = array_fill_keys($layout['values'], null);

            foreach ($columnMembers as $columnId => $_) {
                $values = $cells[$rowId][$columnId] ?? null;

                if ($values === null) {
                    continue;
                }

                $rowCells[(string) $columnIndex[$columnId]] = $values;

                foreach ($layout['values'] as $measure) {
                    if (! in_array($measure, $additive, true)) {
                        continue;
                    }

                    $rowTotals[$measure] = $this->add($rowTotals[$measure], $values[$measure] ?? null);
                    $columnTotals[$columnIndex[$columnId]][$measure] = $this->add($columnTotals[$columnIndex[$columnId]][$measure] ?? null, $values[$measure] ?? null);
                    $grand[$measure] = $this->add($grand[$measure], $values[$measure] ?? null);
                }
            }

            $outRows[] = ['members' => $member, 'cells' => (object) $rowCells, 'totals' => $rowTotals];
        }

        return [
            'row_dimensions' => array_map(static fn (string $key): array => ['key' => $key, 'label' => $labels[$key] ?? $key], $layout['rows']),
            'column_dimensions' => array_map(static fn (string $key): array => ['key' => $key, 'label' => $labels[$key] ?? $key], $layout['columns']),
            'values' => array_map(static fn (string $key): array => ['key' => $key, 'label' => $labels[$key] ?? $key, 'unit' => $units[$key] ?? null, 'additive' => in_array($key, $additive, true)], $layout['values']),
            'columns' => array_map(static fn (array $member): array => ['members' => $member], array_values($columnMembers)),
            'rows' => $outRows,
            'column_totals' => (object) array_combine(array_map('strval', array_keys($columnTotals)), array_values($columnTotals)),
            'grand_totals' => $grand,
            'meta' => $result['meta'],
        ];
    }

    private static function member(mixed $value): ?string
    {
        return $value === null ? null : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
    }

    /**
     * Adds two decimal figures, keeping the larger number of decimals of the two.
     */
    private function add(string|int|null $current, mixed $value): string|int|null
    {
        if ($value === null) {
            return $current;
        }

        if ($current === null) {
            return (string) $value;
        }

        $scale = max(strlen((string) substr((string) strrchr((string) $current, '.'), 1)), strlen((string) substr((string) strrchr((string) $value, '.'), 1)));

        return bcadd((string) $current, (string) $value, $scale);
    }
}
