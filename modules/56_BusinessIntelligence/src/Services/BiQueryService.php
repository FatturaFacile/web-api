<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Services;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Analytics\Semantic\ReportEngine;
use Modules\Analytics\Semantic\ReportSpec;
use Modules\BusinessIntelligence\Models\BiCalculatedMeasure;
use Modules\BusinessIntelligence\Security\RowLevelSecurityRegistry;

/**
 * Runs the queries of the business intelligence module on the semantic layer of the Analytics module. It adds three
 * things to the report engine, and nothing else: the calculated measures of the company (computed on the aggregated
 * rows from base measures the engine already validated), the mandatory filters of the row-level security rules, and the
 * extra filters of a dashboard or a drill-down. Every identifier still goes through {@see ReportSpec::parse()}, so the
 * only door from user input to the database is the allow-list of the dataset, and every query is limited to the active
 * company by the engine.
 */
final readonly class BiQueryService
{
    /**
     * Inject the collaborators.
     */
    public function __construct(
        private ReportEngine $engine,
        private FormulaEvaluator $formulas,
        private RowLevelSecurityRegistry $security,
    ) {}

    /**
     * Validates a query without running it.
     *
     * @param  array<string, mixed>  $spec
     * @param  list<string>  $calculated
     *
     * @throws ValidationException when anything is outside the declaration of the dataset
     */
    public function validate(string $dataset, array $spec, array $calculated, User $user, string $errorPrefix = 'spec.'): void
    {
        $this->prepare($dataset, $spec, $calculated, $user, [], $errorPrefix);
    }

    /**
     * Runs a query and returns all its rows (up to the row limit of the widgets), with the calculated measures.
     *
     * @param  array<string, mixed>  $spec  specification of the semantic layer
     * @param  list<string>  $calculated  keys of the calculated measures to add to the result
     * @param  list<array{field: string, operator: string, value?: mixed}>  $extraFilters  filters of a dashboard or drill-down
     * @return array{columns: list<array<string, mixed>>, rows: list<array<string, mixed>>, meta: array<string, mixed>}
     *
     * @throws ValidationException when anything is outside the declaration of the dataset
     */
    public function run(string $dataset, array $spec, array $calculated, User $user, array $extraFilters = [], ?int $maxRows = null, string $errorPrefix = 'spec.'): array
    {
        [$parsed, $measures, $hidden] = $this->prepare($dataset, $spec, $calculated, $user, $extraFilters, $errorPrefix);
        $export = $this->engine->openExport($parsed, $user);
        $limit = max(1, min($maxRows ?? (int) config('business_intelligence.widget_max_rows', 1000), $this->engine->maxRows()));
        $truncated = (bool) ($export['meta']['truncated'] ?? false);
        $trees = array_values(array_map(fn (BiCalculatedMeasure $measure): array => $this->formulas->parse($measure->formula), $measures));
        $rows = [];

        foreach (($export['rows'])() as $row) {
            if (count($rows) >= $limit) {
                $truncated = true;

                break;
            }

            foreach (array_values($measures) as $index => $measure) {
                $row[$measure->key] = $this->formulas->evaluateTree($trees[$index], $row, $measure->scale);
            }

            foreach ($hidden as $key) {
                unset($row[$key], $row["{$key}__previous"], $row["{$key}__change"], $row["{$key}__change_percent"]);
            }

            $rows[] = $row;
        }

        $columns = array_values(array_filter(
            $export['columns'],
            static fn (array $column): bool => ! in_array($column['key'], $hidden, true) && ! in_array(preg_replace('/__(previous|change|change_percent)$/', '', (string) $column['key']), $hidden, true),
        ));

        foreach ($measures as $measure) {
            $columns[] = ['key' => $measure->key, 'label' => $measure->label, 'kind' => 'measure', 'unit' => $measure->unit, 'calculated' => true];
        }

        return [
            'columns' => $columns,
            'rows' => $rows,
            'meta' => [...$export['meta'], 'row_count' => count($rows), 'row_limit' => $limit, 'truncated' => $truncated, 'calculated' => array_keys($measures)],
        ];
    }

    /**
     * The measures of the dataset whose figures can be added up across rows: plain sums and counts. Averages, distinct
     * counts, percentages and calculated measures cannot.
     *
     * @return list<string>
     */
    public function additiveMeasures(string $dataset, User $user): array
    {
        $found = $this->engine->datasetFor($dataset, $user);
        $additive = [];

        foreach ($found->measures as $measure) {
            if ($measure->unit !== 'percent' && preg_match('/^\s*(SUM|COUNT)\((?!\s*DISTINCT)/i', $measure->sql) === 1) {
                $additive[] = $measure->key;
            }
        }

        return $additive;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  list<string>  $calculated
     * @param  list<array{field: string, operator: string, value?: mixed}>  $extraFilters
     * @return array{0: ReportSpec, 1: array<string, BiCalculatedMeasure>, 2: list<string>}
     */
    private function prepare(string $dataset, array $spec, array $calculated, User $user, array $extraFilters, string $errorPrefix): array
    {
        $found = $this->engine->datasetFor($dataset, $user);
        $measures = $this->calculatedMeasures($dataset, $calculated, $errorPrefix);
        $requested = array_values(array_filter((array) ($spec['measures'] ?? []), 'is_string'));
        $needed = [];

        foreach ($measures as $measure) {
            foreach ($this->formulas->references($measure->formula) as $key) {
                $needed[] = $key;
            }
        }

        $engineMeasures = array_values(array_unique([...$requested, ...$needed]));
        $hidden = array_values(array_diff($engineMeasures, $requested));
        $filters = array_values([...(array) ($spec['filters'] ?? []), ...$extraFilters, ...$this->security->filtersFor($user, $found)]);
        $parsed = $this->engine->parse($dataset, [...$spec, 'measures' => $engineMeasures, 'filters' => $filters], $user, $errorPrefix);

        return [$parsed, $measures, $hidden];
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, BiCalculatedMeasure>
     */
    private function calculatedMeasures(string $dataset, array $keys, string $errorPrefix): array
    {
        if ($keys === []) {
            return [];
        }

        $found = BiCalculatedMeasure::query()->where('dataset', $dataset)->whereIn('key', $keys)->get()->keyBy('key');
        $ordered = [];
        $errors = [];

        foreach (array_values(array_unique($keys)) as $index => $key) {
            if (! $found->has($key)) {
                $errors["calculated_measures.{$index}"][] = __('bi_errors.query.unknown_calculated');

                continue;
            }

            try {
                $this->formulas->parse($found[$key]->formula);
            } catch (InvalidArgumentException $exception) {
                $errors["calculated_measures.{$index}"][] = $exception->getMessage();

                continue;
            }

            $ordered[$key] = $found[$key];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $ordered;
    }
}
