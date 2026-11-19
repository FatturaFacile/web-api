<?php

declare(strict_types=1);

namespace Modules\Configurator\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Configurator\Models\ConfigurableModel;
use Modules\Configurator\Models\ConfiguratorOption;
use Modules\Configurator\Models\ConfiguratorOptionGroup;
use Modules\Configurator\Models\ConfiguratorRule;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Authoring of configurable models: groups, options and rules, the version that every structural change of a published
 * model raises, the checks that run when a rule is saved (RuleGraphValidator) and the ones that gate publication.
 */
final readonly class ConfigurableModelService
{
    /** @var list<string> */
    private const array PricedAttributes = ['base_product_id', 'base_price_from_product', 'base_price', 'base_cost', 'tax_rate', 'volume_breaks', 'min_margin_percent', 'currency'];

    public function __construct(private RuleGraphValidator $validator) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ConfigurableModel
    {
        return ConfigurableModel::query()->create($data + ['status' => ConfigurableModel::Draft, 'version' => 1])->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ConfigurableModel $model, array $data): ConfigurableModel
    {
        $this->assertEditable($model);
        $model->fill($data);

        if ($model->status === ConfigurableModel::Published && $model->isDirty(self::PricedAttributes)) {
            $model->version++;
        }

        $model->save();

        return $model->refresh();
    }

    public function delete(ConfigurableModel $model): void
    {
        if ($model->configurations()->exists()) {
            throw ValidationException::withMessages(['model' => ['The model has configurations: archive it instead of deleting it.']]);
        }

        DB::transaction(function () use ($model): void {
            $model->rules()->forceDelete();
            $model->options()->forceDelete();
            $model->groups()->forceDelete();
            $model->forceDelete();
        });
    }

    public function publish(ConfigurableModel $model): ConfigurableModel
    {
        $this->assertTransition($model, ConfigurableModel::Published);
        $problems = $this->publicationProblems($model);

        if ($problems !== []) {
            throw ValidationException::withMessages(['status' => $problems]);
        }

        $model->forceFill(['status' => ConfigurableModel::Published, 'published_at' => now(), 'version' => $model->version + 1])->save();

        return $model->refresh();
    }

    public function archive(ConfigurableModel $model): ConfigurableModel
    {
        $this->assertTransition($model, ConfigurableModel::Archived);
        $model->forceFill(['status' => ConfigurableModel::Archived])->save();

        return $model->refresh();
    }

    /**
     * Everything that makes the rule set of the model unsatisfiable or circular.
     *
     * @return list<array{code: string, message: string, rule_ids: list<string>}>
     */
    public function ruleProblems(ConfigurableModel $model): array
    {
        $model->unsetRelations();
        $definition = ModelDefinition::from($model);

        return $this->validator->problems($definition, array_map($this->ruleToArray(...), $definition->rules));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveGroup(ConfigurableModel $model, array $data, ?ConfiguratorOptionGroup $group = null): ConfiguratorOptionGroup
    {
        $this->assertEditable($model);
        $type = (string) ($data['type'] ?? $group?->type);

        if ($group !== null && $group->type !== $type && $type === ConfiguratorOptionGroup::Range && $group->options()->exists()) {
            throw ValidationException::withMessages(['type' => ['A group with options cannot become a range: delete its options first.']]);
        }

        if ($group !== null && $group->type !== $type && $type === ConfiguratorOptionGroup::Boolean && $group->options()->count() > 1) {
            throw ValidationException::withMessages(['type' => ['A boolean group owns exactly one option: delete the others first.']]);
        }

        $data = $this->normalizeGroup($data, $type);
        $rangeMin = $data['range_min'] ?? $group?->range_min;
        $rangeMax = $data['range_max'] ?? $group?->range_max;

        if ($type === ConfiguratorOptionGroup::Range && $rangeMin !== null && $rangeMax !== null && Decimal::compare((string) $rangeMax, (string) $rangeMin) < 0) {
            throw ValidationException::withMessages(['range_max' => ['The maximum cannot be below the minimum.']]);
        }

        if ($type === ConfiguratorOptionGroup::Multi && isset($data['min_select'], $data['max_select']) && $data['max_select'] < $data['min_select']) {
            throw ValidationException::withMessages(['max_select' => ['The maximum cannot be below the minimum.']]);
        }

        return DB::transaction(function () use ($model, $data, $group): ConfiguratorOptionGroup {
            $group = $group === null
                ? $model->groups()->create($data)
                : tap($group)->update($data);

            $this->bumpVersion($model);

            return $group->refresh();
        });
    }

    public function deleteGroup(ConfigurableModel $model, ConfiguratorOptionGroup $group): void
    {
        $this->assertEditable($model);
        $codes = [$group->code, ...$group->options()->pluck('code')->all()];
        $this->assertNotReferenced($model, $codes, "the group {$group->code}");

        DB::transaction(function () use ($model, $group): void {
            $group->options()->forceDelete();
            $group->forceDelete();
            $this->bumpVersion($model);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveOption(ConfigurableModel $model, ConfiguratorOptionGroup $group, array $data, ?ConfiguratorOption $option = null): ConfiguratorOption
    {
        $this->assertEditable($model);

        if ($group->type === ConfiguratorOptionGroup::Range) {
            throw ValidationException::withMessages(['group' => ['A range group has no options: it is priced per unit by the group itself.']]);
        }

        if ($option === null && $group->type === ConfiguratorOptionGroup::Boolean && $group->options()->exists()) {
            throw ValidationException::withMessages(['group' => ['A boolean group owns exactly one option.']]);
        }

        return DB::transaction(function () use ($model, $group, $data, $option): ConfiguratorOption {
            if ($option === null) {
                $option = $group->options()->create($data + ['model_id' => $model->id]);
            } else {
                $option->update($data);
            }

            $keepsOneDefault = ($data['is_default'] ?? false) === true && $group->type !== ConfiguratorOptionGroup::Multi;

            if ($keepsOneDefault) {
                $group->options()->whereKeyNot($option->id)->where('is_default', true)->get()->each(static fn (ConfiguratorOption $other): bool => $other->update(['is_default' => false]));
            }

            $this->bumpVersion($model);

            return $option->refresh();
        });
    }

    public function deleteOption(ConfigurableModel $model, ConfiguratorOption $option): void
    {
        $this->assertEditable($model);
        $this->assertNotReferenced($model, [$option->code], "the option {$option->code}");

        DB::transaction(function () use ($model, $option): void {
            $option->forceDelete();
            $this->bumpVersion($model);
        });
    }

    /**
     * Saves a rule after checking the rule set it would join: a rule that creates a cycle or a conflict is refused.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveRule(ConfigurableModel $model, array $data, ?ConfiguratorRule $rule = null): ConfiguratorRule
    {
        $this->assertEditable($model);
        $candidate = ($rule ?? new ConfiguratorRule)->forceFill($data + ['model_id' => $model->id]);

        if ($candidate->is_active ?? true) {
            $model->unsetRelations();
            $definition = ModelDefinition::from($model);
            $others = array_values(array_filter($definition->rules, static fn (ConfiguratorRule $other): bool => $rule === null || $other->id !== $rule->id));
            $existing = array_map($this->ruleToArray(...), $others);
            $before = array_column($this->validator->problems($definition, $existing), 'message');
            $after = $this->validator->problems($definition, [...$existing, $this->ruleToArray($candidate)]);
            $own = array_values(array_filter($after, static fn (array $problem): bool => ! in_array($problem['message'], $before, true)));

            if ($own !== []) {
                throw ValidationException::withMessages(['conditions' => array_values(array_unique(array_column($own, 'message')))]);
            }
        }

        return DB::transaction(function () use ($model, $rule, $data): ConfiguratorRule {
            $saved = $rule === null ? $model->rules()->create($data) : tap($rule)->update($data);
            $this->bumpVersion($model);

            return $saved->refresh();
        });
    }

    public function deleteRule(ConfigurableModel $model, ConfiguratorRule $rule): void
    {
        $this->assertEditable($model);

        DB::transaction(function () use ($model, $rule): void {
            $rule->forceDelete();
            $this->bumpVersion($model);
        });
    }

    /**
     * Raises the version of a published model: configurations priced before the change keep the version they saw.
     */
    public function bumpVersion(ConfigurableModel $model): void
    {
        if ($model->status === ConfigurableModel::Published) {
            $model->forceFill(['version' => $model->version + 1])->save();
        }
    }

    /**
     * @return list<string>
     */
    private function publicationProblems(ConfigurableModel $model): array
    {
        $model->unsetRelations();
        $definition = ModelDefinition::from($model);
        $problems = [];

        if ($definition->groups === []) {
            $problems[] = 'The model has no option group.';
        }

        foreach ($definition->groups as $code => $group) {
            $options = array_filter($definition->optionsOf($code), static fn (ConfiguratorOption $option): bool => $option->is_active);

            if ($group->type === ConfiguratorOptionGroup::Range) {
                if ($group->range_min === null || $group->range_max === null) {
                    $problems[] = "The range group {$code} needs a minimum and a maximum.";
                }
            } elseif ($options === []) {
                $problems[] = "The group {$code} has no active option.";
            } elseif ($group->type === ConfiguratorOptionGroup::Multi && $group->min_select !== null && $group->max_select !== null && $group->min_select > $group->max_select) {
                $problems[] = "The group {$code} has a minimum above its maximum.";
            }
        }

        foreach ($definition->options as $option) {
            if (! Decimal::isPositive($option->price_multiplier)) {
                $problems[] = "The option {$option->code} has a multiplier that is not positive.";
            }
        }

        foreach ($this->ruleProblems($model) as $problem) {
            $problems[] = $problem['message'];
        }

        return $problems;
    }

    /**
     * @param  list<string>  $codes
     */
    private function assertNotReferenced(ConfigurableModel $model, array $codes, string $subject): void
    {
        foreach ($model->rules()->get() as $rule) {
            $referenced = array_merge($rule->target_options ?? [], array_map(static fn (array $condition): string => (string) ($condition['option'] ?? $condition['group'] ?? ''), $rule->conditions ?? []));

            if (array_intersect($codes, $referenced) !== []) {
                throw ValidationException::withMessages(['rule' => ["Cannot delete {$subject}: the rule \"{$rule->name}\" uses it."]]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeGroup(array $data, string $type): array
    {
        if ($type === ConfiguratorOptionGroup::Range) {
            return $data + ['min_select' => null, 'max_select' => null];
        }

        return array_merge($data, ['range_min' => null, 'range_max' => null, 'range_step' => null, 'default_value' => null, 'unit' => null, 'unit_price_delta' => '0', 'unit_cost' => '0', 'product_id' => null]);
    }

    /**
     * @return array{id: string|null, name: string, type: string, match: string, conditions: list<array<string, mixed>>, target_options: list<string>}
     */
    private function ruleToArray(ConfiguratorRule $rule): array
    {
        return [
            'id' => $rule->exists ? $rule->id : null,
            'name' => (string) $rule->name,
            'type' => (string) $rule->type,
            'match' => (string) ($rule->match ?: 'all'),
            'conditions' => $rule->conditions ?? [],
            'target_options' => $rule->target_options ?? [],
        ];
    }

    private function assertEditable(ConfigurableModel $model): void
    {
        if (! $model->isEditable()) {
            throw ValidationException::withMessages(['model' => ['An archived model cannot be changed.']]);
        }
    }

    private function assertTransition(ConfigurableModel $model, string $status): void
    {
        if (! $model->canTransitionTo($status)) {
            throw ValidationException::withMessages(['status' => ["A {$model->status} model cannot become {$status}."]]);
        }
    }
}
