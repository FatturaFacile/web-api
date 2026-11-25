<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\CpqConfigurator\Models\CpqCharacteristic;
use Modules\CpqConfigurator\Models\CpqCharacteristicOption;
use Modules\CpqConfigurator\Models\CpqModel;

/**
 * Lifecycle of a model and writes of everything it owns (characteristics, options, rules, mappings, formulas). Once a
 * model is published, every change raises its version: the hash of a configuration includes the version, so a changed
 * rule set can never hand out the part number (and the BOM) of the previous one.
 *
 * Errors are 422 validation errors whose messages are translation keys of the client.
 */
final class CpqModelService
{
    public function __construct(private readonly ModelDefinitionLoader $loader, private readonly ModelDiagnostics $diagnostics) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): CpqModel
    {
        $data['code'] = strtoupper((string) $data['code']);
        $data['part_number_prefix'] = strtoupper((string) ($data['part_number_prefix'] ?? $data['code']));

        return CpqModel::query()->create($data + ['status' => CpqModel::Draft, 'version' => 1])->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CpqModel $model, array $data): CpqModel
    {
        $this->assertEditable($model);

        if (isset($data['code'])) {
            $data['code'] = strtoupper((string) $data['code']);
        }

        if (isset($data['part_number_prefix'])) {
            $data['part_number_prefix'] = strtoupper((string) $data['part_number_prefix']);

            if ($model->part_number_sequence > 0 && $data['part_number_prefix'] !== $model->part_number_prefix) {
                throw ValidationException::withMessages(['part_number_prefix' => ['CPQ_CONFIGURATOR.ERR.PREFIX_LOCKED']]);
            }
        }

        $model->fill($data)->save();
        $this->bump($model);

        return $model->refresh();
    }

    public function delete(CpqModel $model): void
    {
        if ($model->instances()->exists()) {
            throw ValidationException::withMessages(['model' => ['CPQ_CONFIGURATOR.ERR.MODEL_HAS_INSTANCES']]);
        }

        DB::transaction(static function () use ($model): void {
            foreach (['characteristics', 'rules', 'bomMappings', 'routingMappings', 'pricingFormulas'] as $relation) {
                $model->{$relation}()->get()->each(static fn (Model $child) => $child->delete());
            }

            $model->delete();
        });
    }

    public function publish(CpqModel $model): CpqModel
    {
        if ($model->status === CpqModel::Archived) {
            throw ValidationException::withMessages(['status' => ['CPQ_CONFIGURATOR.ERR.MODEL_ARCHIVED']]);
        }

        $errors = array_filter($this->diagnostics($model), static fn (array $issue): bool => $issue['severity'] === 'error');

        if ($errors !== []) {
            throw ValidationException::withMessages(['status' => ['CPQ_CONFIGURATOR.ERR.MODEL_HAS_ISSUES']]);
        }

        $model->forceFill(['status' => CpqModel::Published, 'published_at' => now(), 'version' => $model->status === CpqModel::Published ? $model->version : $model->version + 1])->save();

        return $model->refresh();
    }

    public function archive(CpqModel $model): CpqModel
    {
        $model->forceFill(['status' => CpqModel::Archived])->save();

        return $model->refresh();
    }

    /**
     * @return list<array{severity: string, code: string, scope: string, params: array<string, mixed>}>
     */
    public function diagnostics(CpqModel $model): array
    {
        $model->unsetRelations();

        return $this->diagnostics->check($this->loader->load($model));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveCharacteristic(CpqModel $model, array $data, ?CpqCharacteristic $existing = null): CpqCharacteristic
    {
        $this->assertEditable($model);
        $record = $existing ?? new CpqCharacteristic;
        $record->fill($data + ($existing === null ? ['model_id' => $model->id] : []))->save();
        $this->bump($model);

        return $record->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveOption(CpqCharacteristic $characteristic, array $data, ?CpqCharacteristicOption $existing = null): CpqCharacteristicOption
    {
        $model = $this->owner((string) $characteristic->model_id);
        $this->assertEditable($model);

        if ($characteristic->type !== CpqCharacteristic::TypeList) {
            throw ValidationException::withMessages(['characteristic' => ['CPQ_CONFIGURATOR.ERR.NOT_A_LIST']]);
        }

        return DB::transaction(function () use ($characteristic, $data, $existing, $model): CpqCharacteristicOption {
            $record = $existing ?? new CpqCharacteristicOption;
            $record->fill($data + ($existing === null ? ['model_id' => $characteristic->model_id, 'characteristic_id' => $characteristic->id] : []))->save();

            if ($record->is_default) {
                CpqCharacteristicOption::query()->where('characteristic_id', $characteristic->id)->whereKeyNot($record->id)->update(['is_default' => false]);
            }

            $this->bump($model);

            return $record->refresh();
        });
    }

    /**
     * Rules, mappings and formulas: the same write for every child that belongs to a model.
     *
     * @param  class-string<Model>  $class
     * @param  array<string, mixed>  $data
     */
    public function saveChild(CpqModel $model, string $class, array $data, ?Model $existing = null): Model
    {
        $this->assertEditable($model);
        $record = $existing ?? new $class;
        $record->fill($data + ($existing === null ? ['model_id' => $model->id] : []))->save();
        $this->bump($model);

        return $record->refresh();
    }

    public function deleteChild(Model $child): void
    {
        $model = $this->owner((string) $child->getAttribute('model_id'));
        $this->assertEditable($model);

        DB::transaction(function () use ($child, $model): void {
            if ($child instanceof CpqCharacteristic) {
                $child->options()->get()->each(static fn (Model $option) => $option->delete());
            }

            $child->delete();
            $this->bump($model);
        });
    }

    public function owner(string $modelId): CpqModel
    {
        return CpqModel::query()->findOrFail($modelId);
    }

    private function assertEditable(CpqModel $model): void
    {
        if (! $model->isEditable()) {
            throw ValidationException::withMessages(['model' => ['CPQ_CONFIGURATOR.ERR.MODEL_ARCHIVED']]);
        }
    }

    private function bump(CpqModel $model): void
    {
        if ($model->status === CpqModel::Published) {
            $model->forceFill(['version' => $model->version + 1])->save();
        }
    }
}
