<?php

declare(strict_types=1);

namespace Modules\BusinessIntelligence\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Analytics\Semantic\Dataset;
use Modules\Analytics\Semantic\ReportEngine;
use Modules\BusinessIntelligence\Models\BiCalculatedMeasure;
use Modules\BusinessIntelligence\Models\BiDashboardWidget;
use Modules\BusinessIntelligence\Models\BiSavedQuery;

/**
 * The calculated measures of a company. A measure is a formula over the base measures of one dataset; the service
 * guarantees that the formula parses, that it only reads measures the dataset declares, that its key does not collide
 * with the dataset, and that a measure still used by a saved query or a widget is not removed or changed in a way that
 * would break them.
 */
final readonly class CalculatedMeasureService
{
    /**
     * Inject the collaborators.
     */
    public function __construct(private ReportEngine $engine, private FormulaEvaluator $formulas) {}

    /**
     * The calculated measures of a dataset.
     *
     * @return Collection<int, BiCalculatedMeasure>
     */
    public function forDataset(string $dataset): Collection
    {
        return BiCalculatedMeasure::query()->where('dataset', $dataset)->orderBy('key')->get();
    }

    /**
     * @param  array<string, mixed>  $data  `dataset`, `key`, `label`, `formula`, `unit`, `scale`
     *
     * @throws ValidationException
     */
    public function create(string $tenantId, User $user, array $data): BiCalculatedMeasure
    {
        $dataset = $this->engine->datasetFor((string) $data['dataset'], $user);
        $key = (string) $data['key'];
        $this->assertKeyIsFree($dataset, $key);

        if (BiCalculatedMeasure::query()->where('dataset', $dataset->key)->count() >= (int) config('business_intelligence.max_calculated_measures_per_dataset', 25)) {
            throw ValidationException::withMessages(['key' => [__('bi_errors.calculated.limit', ['max' => (int) config('business_intelligence.max_calculated_measures_per_dataset', 25)])]]);
        }

        $this->assertFormula($dataset, (string) $data['formula']);

        return BiCalculatedMeasure::create([
            'tenant_id' => $tenantId,
            'dataset' => $dataset->key,
            'key' => $key,
            'label' => (string) $data['label'],
            'formula' => trim((string) $data['formula']),
            'unit' => (string) ($data['unit'] ?? 'number'),
            'scale' => (int) ($data['scale'] ?? 2),
            'created_by' => $user->id,
        ]);
    }

    /**
     * The key and the dataset never change, because saved queries refer to them.
     *
     * @param  array<string, mixed>  $data  `label`, `formula`, `unit`, `scale`
     *
     * @throws ValidationException
     */
    public function update(BiCalculatedMeasure $measure, User $user, array $data): BiCalculatedMeasure
    {
        $dataset = $this->engine->datasetFor($measure->dataset, $user);

        if (isset($data['formula'])) {
            $this->assertFormula($dataset, (string) $data['formula']);
            $data['formula'] = trim((string) $data['formula']);
        }

        $measure->update(array_intersect_key($data, array_flip(['label', 'formula', 'unit', 'scale'])));

        return $measure->refresh();
    }

    /**
     * @throws ValidationException when a saved query or a widget still uses the measure
     */
    public function delete(BiCalculatedMeasure $measure): void
    {
        $usedBy = $this->usages($measure);

        if ($usedBy !== []) {
            throw ValidationException::withMessages(['id' => [__('bi_errors.calculated.in_use', ['usage' => implode(', ', $usedBy)])]]);
        }

        $measure->delete();
    }

    /**
     * Names of the saved queries and widgets that use the measure.
     *
     * @return list<string>
     */
    public function usages(BiCalculatedMeasure $measure): array
    {
        $names = [];

        foreach (BiSavedQuery::query()->where('dataset', $measure->dataset)->get(['id', 'name', 'calculated_measures']) as $query) {
            if (in_array($measure->key, $query->calculated_measures ?? [], true)) {
                $names[] = $query->name;
            }
        }

        foreach (BiDashboardWidget::query()->where('dataset', $measure->dataset)->get(['id', 'title', 'calculated_measures']) as $widget) {
            if (in_array($measure->key, $widget->calculated_measures ?? [], true)) {
                $names[] = $widget->title;
            }
        }

        return $names;
    }

    /**
     * @throws ValidationException
     */
    private function assertKeyIsFree(Dataset $dataset, string $key): void
    {
        if ($dataset->measure($key) !== null || $dataset->dimension($key) !== null) {
            throw ValidationException::withMessages(['key' => [__('bi_errors.calculated.key_taken_by_dataset', ['key' => $key])]]);
        }

        if (BiCalculatedMeasure::query()->where('dataset', $dataset->key)->where('key', $key)->exists()) {
            throw ValidationException::withMessages(['key' => [__('bi_errors.calculated.key_exists', ['key' => $key])]]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertFormula(Dataset $dataset, string $formula): void
    {
        try {
            $references = $this->formulas->references($formula);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['formula' => [$exception->getMessage()]]);
        }

        if ($references === []) {
            throw ValidationException::withMessages(['formula' => [__('bi_errors.formula.no_reference')]]);
        }

        foreach ($references as $reference) {
            if ($dataset->measure($reference) === null) {
                throw ValidationException::withMessages(['formula' => [__('bi_errors.formula.unknown_measure', ['measure' => $reference])]]);
            }
        }
    }
}
