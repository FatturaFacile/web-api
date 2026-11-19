<?php

declare(strict_types=1);

namespace Modules\Analytics\Pivot;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Analytics\Calculation\CalculatedFields;
use Modules\Analytics\Models\DesignedReport;
use Modules\Analytics\Semantic\ReportEngine;
use Modules\Analytics\Semantic\ReportSpec;

/**
 * Validates the whole definition of a designed report (data specification, pivot layout, calculated fields, conditional
 * formatting, chart) and turns it into what the engine runs. The same code validates on save and re-validates on every
 * run, against the permissions of the user who runs it, so a field the user cannot see is refused whoever saved the report.
 */
final readonly class DesignedReportPlan
{
    public function __construct(private ReportEngine $engine, private CalculatedFields $calculated) {}

    /**
     * @param  array<string, mixed>  $definition  dataset, spec, pivot, calculated_fields, conditional_formatting, chart_config
     * @param  string  $specPrefix  prefix of the error keys of the data specification (`spec.` when the client nests it)
     * @return array{spec: ReportSpec, pivot: ?PivotDefinition, calculated: list<array{key: string, label: string, formula: string, format: string}>}
     *
     * @throws ValidationException
     */
    public function build(array $definition, User $user, string $specPrefix = ''): array
    {
        $dataset = (string) ($definition['dataset'] ?? '');
        $input = (array) ($definition['spec'] ?? []);
        $layout = (array) ($definition['pivot'] ?? []);
        $isPivot = ((array) ($layout['rows'] ?? [])) !== [] || ((array) ($layout['columns'] ?? [])) !== [];

        if ($isPivot) {
            $tokens = [...(array) ($layout['rows'] ?? []), ...(array) ($layout['columns'] ?? [])];
            $input['dimensions'] = array_values(array_filter($tokens, 'is_string'));
            $input['sort'] = [];
            $input['compare'] = 'none';
        }

        $spec = $this->engine->parse($dataset, $input, $user, $specPrefix);
        $fields = $this->validateCalculated($definition['calculated_fields'] ?? [], $spec);
        $valueKeys = [...$spec->measures, ...array_column($fields, 'key')];

        $pivot = null;

        if ($isPivot) {
            $pivot = PivotDefinition::parse($layout, (array) ($definition['conditional_formatting'] ?? []), (array) ($definition['chart_config'] ?? []), $valueKeys);
        } elseif ($fields !== []) {
            // Calculated fields live on aggregated cells, so a report that has them is shown and exported as a pivot of its dimensions.
            $tokens = array_map(static fn (array $dimension): string => $dimension['granularity'] === null ? $dimension['key'] : "{$dimension['key']}:{$dimension['granularity']}", $spec->dimensions);
            $pivot = PivotDefinition::parse(['rows' => $tokens, 'columns' => [], 'subtotals' => false, 'grand_totals' => true], (array) ($definition['conditional_formatting'] ?? []), (array) ($definition['chart_config'] ?? []), $valueKeys);
        }

        return ['spec' => $spec, 'pivot' => $pivot, 'calculated' => $fields];
    }

    /**
     * @return array{spec: ReportSpec, pivot: ?PivotDefinition, calculated: list<array{key: string, label: string, formula: string, format: string}>}
     */
    public function forReport(DesignedReport $report, User $user): array
    {
        return $this->build([
            'dataset' => $report->dataset,
            'spec' => $report->spec,
            'pivot' => $report->pivot,
            'calculated_fields' => $report->calculated_fields,
            'conditional_formatting' => $report->conditional_formatting,
            'chart_config' => $report->chart_config,
        ], $user);
    }

    /**
     * @return list<array{key: string, label: string, formula: string, format: string}>
     *
     * @throws ValidationException
     */
    private function validateCalculated(mixed $raw, ReportSpec $spec): array
    {
        try {
            return $this->calculated->validate($raw, $spec->measures, $spec->dimensions === [] ? [] : array_column($spec->dimensions, 'key'));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['calculated_fields' => [(string) __('analytics_errors.pivot.calculated_invalid', ['detail' => $exception->getMessage()])]]);
        }
    }
}
