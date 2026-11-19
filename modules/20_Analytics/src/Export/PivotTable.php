<?php

declare(strict_types=1);

namespace Modules\Analytics\Export;

use Modules\Analytics\Pivot\PivotService;

/**
 * Flattens a pivot matrix into the columns and rows every export format understands: one column per row dimension, one
 * column per column-header and value, a total column when the pivot has column dimensions. Subtotal and grand total rows
 * carry a `__type` marker and a translated caption. Pivot columns are never flagged additive, because the table already
 * contains its own subtotals and totals and a spreadsheet SUM over it would count them twice.
 */
final class PivotTable
{
    private const array Units = ['currency' => 'currency', 'integer' => 'count', 'number' => 'quantity', 'percentage' => 'percent'];

    /**
     * @param  array<string, mixed>  $pivot  result of {@see PivotService::execute()}
     * @return array{columns: list<array<string, mixed>>, rows: list<array<string, mixed>>}
     */
    public static function flatten(array $pivot, string $dataset, RegionalReportFormatter $regional): array
    {
        $language = $regional->language();
        $columns = [];

        foreach ($pivot['row_dimensions'] as $dimension) {
            $columns[] = ['key' => $dimension['key'], 'label' => $regional->header($dataset, $dimension), 'kind' => 'dimension', 'type' => $dimension['type'], 'granularity' => $dimension['granularity']];
        }

        if ($columns === []) {
            $columns[] = ['key' => '__label', 'label' => (string) trans('analytics.export.total', [], $language), 'kind' => 'dimension', 'type' => 'string'];
        }

        $valueLabels = [];

        foreach ($pivot['values'] as $value) {
            $valueLabels[$value['key']] = $value['calculated'] ? $value['label'] : $regional->header($dataset, ['key' => $value['key'], 'label' => $value['label']]);
        }

        foreach ($pivot['column_headers'] as $header) {
            foreach ($pivot['values'] as $value) {
                $columns[] = ['key' => "c{$header['index']}_{$value['key']}", 'label' => $valueLabels[$value['key']].' · '.$header['label'], 'kind' => 'measure', 'unit' => self::Units[$value['format']] ?? 'quantity'];
            }
        }

        if ($pivot['has_total_column'] || $pivot['column_headers'] === []) {
            foreach ($pivot['values'] as $value) {
                $columns[] = ['key' => "t_{$value['key']}", 'label' => $valueLabels[$value['key']].($pivot['has_total_column'] ? ' · '.trans('analytics.export.total', [], $language) : ''), 'kind' => 'measure', 'unit' => self::Units[$value['format']] ?? 'quantity'];
            }
        }

        $dimensionKeys = array_column($pivot['row_dimensions'], 'key');
        $rows = [];

        foreach ($pivot['rows'] as $matrixRow) {
            $row = ['__type' => $matrixRow['type']];

            foreach ($dimensionKeys as $index => $key) {
                $row[$key] = $matrixRow['keys'][$index] ?? null;
            }

            if ($dimensionKeys === []) {
                $row['__label'] = (string) trans('analytics.export.total', [], $language);
            } elseif ($matrixRow['type'] === 'grand_total') {
                $row[$dimensionKeys[0]] = (string) trans('analytics.export.grand_total', [], $language);
            } elseif ($matrixRow['type'] === 'subtotal') {
                $row[$dimensionKeys[count($matrixRow['keys'])] ?? $dimensionKeys[count($dimensionKeys) - 1]] = (string) trans('analytics.export.subtotal', [], $language);
            }

            foreach ($matrixRow['cells'] as $index => $cell) {
                foreach ($pivot['values'] as $value) {
                    $row["c{$index}_{$value['key']}"] = $cell['values'][$value['key']] ?? null;
                }
            }

            foreach ($pivot['values'] as $value) {
                $row["t_{$value['key']}"] = $matrixRow['total']['values'][$value['key']] ?? null;
            }

            $rows[] = $row;
        }

        return ['columns' => $columns, 'rows' => $rows];
    }
}
