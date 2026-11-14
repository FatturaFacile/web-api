<?php

declare(strict_types=1);

namespace Modules\Configurator\Services;

use Modules\Configurator\Models\ConfigurableModel;
use Modules\Configurator\Models\ConfiguratorOption;
use Modules\Configurator\Models\ConfiguratorOptionGroup;
use Modules\Configurator\Models\ConfiguratorRule;

/**
 * Read-only view of a configurable model with its groups, options and active rules indexed by code, which is what the
 * evaluator, the pricer and the rule validator work on. Option and group codes are unique within a model.
 */
final readonly class ModelDefinition
{
    /**
     * @param  array<string, ConfiguratorOptionGroup>  $groups  by group code, in display order
     * @param  array<string, ConfiguratorOption>  $options  by option code
     * @param  array<string, string>  $groupOfOption  option code => group code
     * @param  list<ConfiguratorRule>  $rules  active rules by priority
     */
    private function __construct(
        public ConfigurableModel $model,
        public array $groups,
        public array $options,
        public array $groupOfOption,
        public array $rules,
    ) {}

    public static function from(ConfigurableModel $model, bool $activeRulesOnly = true): self
    {
        $model->loadMissing(['groups', 'options', 'rules']);

        $groupsById = [];
        $groups = [];

        foreach ($model->groups as $group) {
            $groups[$group->code] = $group;
            $groupsById[$group->id] = $group->code;
        }

        $options = [];
        $groupOfOption = [];

        foreach ($model->options as $option) {
            if (! isset($groupsById[$option->group_id])) {
                continue;
            }

            $options[$option->code] = $option;
            $groupOfOption[$option->code] = $groupsById[$option->group_id];
        }

        $rules = $model->rules->filter(static fn (ConfiguratorRule $rule): bool => ! $activeRulesOnly || $rule->is_active)->values()->all();

        return new self($model, $groups, $options, $groupOfOption, $rules);
    }

    /**
     * The options of a group in display order.
     *
     * @return list<ConfiguratorOption>
     */
    public function optionsOf(string $groupCode): array
    {
        return array_values(array_filter($this->options, fn (ConfiguratorOption $option): bool => ($this->groupOfOption[$option->code] ?? null) === $groupCode));
    }

    /**
     * @return list<ConfiguratorRule>
     */
    public function rulesOfType(string $type): array
    {
        return array_values(array_filter($this->rules, static fn (ConfiguratorRule $rule): bool => $rule->type === $type));
    }
}
