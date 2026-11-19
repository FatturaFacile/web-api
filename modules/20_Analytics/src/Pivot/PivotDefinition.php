<?php

declare(strict_types=1);

namespace Modules\Analytics\Pivot;

use Illuminate\Validation\ValidationException;
use Modules\Analytics\Semantic\ReportSpec;

/**
 * The presentation choices of a pivot that are not part of the data query: which dimensions go on rows and which on
 * columns, whether subtotals and grand totals are shown, the conditional formatting rules and the chart. Everything is
 * validated against fixed allow-lists; colors are named tokens the front end maps to its theme, never CSS.
 */
final class PivotDefinition
{
    public const array ChartTypes = ['bar', 'stacked_bar', 'line', 'area', 'pie', 'donut', 'combo'];

    public const array FormattingOperators = ['gt', 'gte', 'lt', 'lte', 'eq', 'neq', 'between'];

    public const array BackgroundTokens = ['none', 'red', 'orange', 'yellow', 'green', 'blue', 'purple', 'gray'];

    public const array TextTokens = ['default', 'white', 'black', 'red', 'green', 'blue'];

    public const int MaxRules = 20;

    /**
     * @param  list<string>  $rows
     * @param  list<string>  $columns
     * @param  list<array{target: string, operator: string, value: string, value2: ?string, background: string, text: string}>  $formatting
     * @param  array{enabled: bool, chartType: string, xAxis: ?string, series: list<string>, showLegend: bool, showDataLabels: bool}  $chart
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $columns,
        public readonly bool $subtotals,
        public readonly bool $grandTotals,
        public readonly array $formatting = [],
        public readonly array $chart = ['enabled' => false, 'chartType' => 'bar', 'xAxis' => null, 'series' => [], 'showLegend' => true, 'showDataLabels' => false],
    ) {}

    /**
     * Validates the layout `{rows, columns, subtotals, grand_totals}`.
     *
     * @param  array<string, mixed>  $layout
     * @param  list<array<string, mixed>>  $formatting
     * @param  array<string, mixed>  $chart
     * @param  list<string>  $valueKeys  measures and calculated fields that can be formatted or charted
     *
     * @throws ValidationException
     */
    public static function parse(array $layout, array $formatting, array $chart, array $valueKeys): self
    {
        $errors = [];
        $rows = self::list($layout['rows'] ?? [], 'pivot.rows', $errors);
        $columns = self::list($layout['columns'] ?? [], 'pivot.columns', $errors);

        if (count($rows) + count($columns) > ReportSpec::MaxDimensions) {
            $errors['pivot'][] = (string) __('analytics_errors.pivot.too_many_dimensions', ['max' => ReportSpec::MaxDimensions]);
        }

        $keys = array_map(static fn (string $token): string => explode(':', $token)[0], [...$rows, ...$columns]);

        if (count($keys) !== count(array_unique($keys))) {
            $errors['pivot'][] = (string) __('analytics_errors.pivot.layout_invalid');
        }

        $rules = [];

        if (count($formatting) > self::MaxRules) {
            $errors['conditional_formatting'][] = (string) __('analytics_errors.pivot.formatting_invalid');
        } else {
            foreach ($formatting as $index => $rule) {
                $parsed = self::rule($rule, $valueKeys);

                if ($parsed === null) {
                    $errors["conditional_formatting.{$index}"][] = (string) __('analytics_errors.pivot.formatting_invalid');
                } else {
                    $rules[] = $parsed;
                }
            }
        }

        $chartConfig = self::chart($chart, $valueKeys, $keys);

        if ($chartConfig === null) {
            $errors['chart_config'][] = (string) __('analytics_errors.pivot.chart_invalid');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new self($rows, $columns, (bool) ($layout['subtotals'] ?? true), (bool) ($layout['grand_totals'] ?? true), $rules, $chartConfig);
    }

    /**
     * @return array<string, mixed>
     */
    public function layout(): array
    {
        return ['rows' => $this->rows, 'columns' => $this->columns, 'subtotals' => $this->subtotals, 'grand_totals' => $this->grandTotals];
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return list<string>
     */
    private static function list(mixed $raw, string $path, array &$errors): array
    {
        if (! is_array($raw) || ! array_is_list($raw) || count($raw) > ReportSpec::MaxDimensions) {
            $errors[$path][] = (string) __('analytics_errors.pivot.layout_invalid');

            return [];
        }

        foreach ($raw as $token) {
            if (! is_string($token) || preg_match('/^[a-z][a-z0-9_]*(:[a-z]+)?$/', $token) !== 1) {
                $errors[$path][] = (string) __('analytics_errors.pivot.layout_invalid');

                return [];
            }
        }

        return $raw;
    }

    /**
     * @param  list<string>  $valueKeys
     * @return array{target: string, operator: string, value: string, value2: ?string, background: string, text: string}|null
     */
    private static function rule(mixed $rule, array $valueKeys): ?array
    {
        if (! is_array($rule) || ! in_array($rule['target'] ?? null, $valueKeys, true) || ! in_array($rule['operator'] ?? null, self::FormattingOperators, true)) {
            return null;
        }

        $number = static fn (mixed $value): ?string => is_numeric($value) && preg_match('/^-?\d{1,18}(\.\d{1,6})?$/', (string) $value) === 1 ? (string) $value : null;
        $value = $number($rule['value'] ?? null);
        $value2 = $number($rule['value2'] ?? null);
        $background = $rule['background'] ?? 'none';
        $text = $rule['text'] ?? 'default';

        if ($value === null || ($rule['operator'] === 'between' && $value2 === null) || ! in_array($background, self::BackgroundTokens, true) || ! in_array($text, self::TextTokens, true)) {
            return null;
        }

        return ['target' => $rule['target'], 'operator' => $rule['operator'], 'value' => $value, 'value2' => $value2, 'background' => $background, 'text' => $text];
    }

    /**
     * @param  array<string, mixed>  $chart
     * @param  list<string>  $valueKeys
     * @param  list<string>  $dimensionKeys
     * @return array{enabled: bool, chartType: string, xAxis: ?string, series: list<string>, showLegend: bool, showDataLabels: bool}|null
     */
    private static function chart(array $chart, array $valueKeys, array $dimensionKeys): ?array
    {
        if ($chart === []) {
            return ['enabled' => false, 'chartType' => 'bar', 'xAxis' => null, 'series' => [], 'showLegend' => true, 'showDataLabels' => false];
        }

        $type = $chart['chartType'] ?? 'bar';
        $xAxis = $chart['xAxis'] ?? null;
        $series = $chart['series'] ?? [];

        if (! in_array($type, self::ChartTypes, true) || ($xAxis !== null && ! in_array($xAxis, $dimensionKeys, true)) || ! is_array($series) || ! array_is_list($series)) {
            return null;
        }

        foreach ($series as $key) {
            if (! in_array($key, $valueKeys, true)) {
                return null;
            }
        }

        return ['enabled' => (bool) ($chart['enabled'] ?? false), 'chartType' => $type, 'xAxis' => $xAxis, 'series' => $series, 'showLegend' => (bool) ($chart['showLegend'] ?? true), 'showDataLabels' => (bool) ($chart['showDataLabels'] ?? false)];
    }
}
