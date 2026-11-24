<?php

declare(strict_types=1);

namespace Modules\Configurator\Services;

use Modules\Configurator\Models\ConfiguratorOption;
use Modules\Configurator\Models\ConfiguratorOptionGroup;
use Modules\Configurator\Models\ConfiguratorRule;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Validates a selection against a model and resolves its defaults. Structural checks come first (known groups and
 * options, one option for a single choice, bounds and step of a range, required groups, minimum and maximum of a
 * multiple choice), then the rules: `requires` and `excludes` raise violations, `default` rules and the default options
 * fill the groups that have no selection yet. A group counts as answered as soon as the selection carries an entry for
 * it, even an empty one, so a customer who switches an optional feature off never gets it back.
 *
 * Violation codes: unknown_group, duplicate_group, unknown_option, option_not_in_group, inactive_option,
 * single_choice_exceeded, range_not_numeric, range_out_of_bounds, range_step_mismatch, required_group_missing,
 * multi_below_min, multi_above_max, requires, excludes.
 *
 * Defaults apply in this order: `default` rules triggered by what the customer chose (a rule is more specific than a
 * group), then the default option of each group still unanswered, then the default rules again for what those added.
 */
final class ConfigurationEvaluator
{
    /**
     * @param  list<array<string, mixed>>  $selections  entries of {group, options?, value?}
     * @return array{
     *     selections: list<array{group: string, options: list<string>, value: string|null}>,
     *     violations: list<array{code: string, severity: string, group: string|null, option: string|null, rule_id: string|null, message: string, params: array<string, mixed>}>,
     *     applied_defaults: list<array{group: string, option: string|null, source: string}>,
     *     selected: array<string, true>,
     *     ranges: array<string, string>
     * }
     */
    public function evaluate(ModelDefinition $definition, array $selections, bool $applyDefaults = true): array
    {
        $violations = [];
        $entries = $this->readEntries($definition, $selections, $violations);
        $appliedDefaults = [];

        if ($applyDefaults) {
            $this->applyDefaultRules($definition, $entries, $appliedDefaults);
            $this->applyGroupDefaults($definition, $entries, $appliedDefaults);
            $this->applyDefaultRules($definition, $entries, $appliedDefaults);
        }

        $this->checkGroups($definition, $entries, $violations);
        [$selected, $ranges] = $this->index($entries);
        $this->checkRules($definition, $selected, $ranges, $violations);

        $resolved = [];

        foreach (array_keys($definition->groups) as $groupCode) {
            if (isset($entries[$groupCode])) {
                $resolved[] = ['group' => $groupCode, 'options' => $entries[$groupCode]['options'], 'value' => $entries[$groupCode]['value']];
            }
        }

        return ['selections' => $resolved, 'violations' => $violations, 'applied_defaults' => $appliedDefaults, 'selected' => $selected, 'ranges' => $ranges];
    }

    /**
     * @param  list<array<string, mixed>>  $selections
     * @param  list<array<string, mixed>>  $violations
     * @return array<string, array{options: list<string>, value: string|null}>
     */
    private function readEntries(ModelDefinition $definition, array $selections, array &$violations): array
    {
        $entries = [];

        foreach ($selections as $entry) {
            $groupCode = (string) ($entry['group'] ?? '');

            if (! isset($definition->groups[$groupCode])) {
                $violations[] = $this->violation('unknown_group', "The group {$groupCode} does not exist in the model.", $groupCode, params: ['group' => $groupCode]);

                continue;
            }

            if (isset($entries[$groupCode])) {
                $violations[] = $this->violation('duplicate_group', "The group {$groupCode} is selected twice.", $groupCode, params: ['group' => $groupCode]);

                continue;
            }

            $group = $definition->groups[$groupCode];

            if ($group->type === ConfiguratorOptionGroup::Range) {
                $entries[$groupCode] = ['options' => [], 'value' => $this->readRangeValue($group, $entry['value'] ?? null, $violations)];

                continue;
            }

            $entries[$groupCode] = ['options' => $this->readOptions($definition, $group, (array) ($entry['options'] ?? []), $violations), 'value' => null];
        }

        return $entries;
    }

    /**
     * @param  list<array<string, mixed>>  $violations
     */
    private function readRangeValue(ConfiguratorOptionGroup $group, mixed $raw, array &$violations): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $text = is_float($raw) ? rtrim(rtrim(number_format($raw, 4, '.', ''), '0'), '.') : trim((string) $raw);

        if (preg_match('/^-?\d+(\.\d+)?$/', $text) !== 1) {
            $violations[] = $this->violation('range_not_numeric', "The value of {$group->name} is not a number.", $group->code, params: ['group' => $group->code, 'name' => $group->name]);

            return null;
        }

        $value = Decimal::normalize($text);
        $below = $group->range_min !== null && Decimal::compare($value, $group->range_min) < 0;
        $above = $group->range_max !== null && Decimal::compare($value, $group->range_max) > 0;

        if ($below || $above) {
            $violations[] = $this->violation('range_out_of_bounds', "The value of {$group->name} must be between {$this->bound($group->range_min)} and {$this->bound($group->range_max)}.", $group->code, params: [
                'group' => $group->code, 'name' => $group->name, 'min' => $this->bound($group->range_min), 'max' => $this->bound($group->range_max), 'value' => ConfiguratorMath::trim($value),
            ]);

            return $value;
        }

        if ($group->range_step !== null && ! ConfiguratorMath::isMultipleOf(Decimal::sub($value, $group->range_min ?? '0'), $group->range_step)) {
            $violations[] = $this->violation('range_step_mismatch', "The value of {$group->name} must be a multiple of {$this->bound($group->range_step)}.", $group->code, params: [
                'group' => $group->code, 'name' => $group->name, 'step' => $this->bound($group->range_step), 'value' => ConfiguratorMath::trim($value),
            ]);
        }

        return $value;
    }

    /**
     * @param  list<mixed>  $codes
     * @param  list<array<string, mixed>>  $violations
     * @return list<string>
     */
    private function readOptions(ModelDefinition $definition, ConfiguratorOptionGroup $group, array $codes, array &$violations): array
    {
        $valid = [];

        foreach (array_values(array_unique(array_map('strval', $codes))) as $code) {
            $option = $definition->options[$code] ?? null;

            if ($option === null) {
                $violations[] = $this->violation('unknown_option', "The option {$code} does not exist in the model.", $group->code, $code, params: ['option' => $code]);
            } elseif (($definition->groupOfOption[$code] ?? null) !== $group->code) {
                $violations[] = $this->violation('option_not_in_group', "The option {$option->name} does not belong to {$group->name}.", $group->code, $code, params: ['option' => $code, 'name' => $option->name, 'group' => $group->name]);
            } elseif (! $option->is_active) {
                $violations[] = $this->violation('inactive_option', "The option {$option->name} is no longer offered.", $group->code, $code, params: ['option' => $code, 'name' => $option->name]);
            } else {
                $valid[] = $code;
            }
        }

        if (in_array($group->type, [ConfiguratorOptionGroup::Single, ConfiguratorOptionGroup::Boolean], true) && count($valid) > 1) {
            $violations[] = $this->violation('single_choice_exceeded', "Only one option of {$group->name} can be chosen.", $group->code, params: ['group' => $group->code, 'name' => $group->name]);
            $valid = [$valid[0]];
        }

        return $valid;
    }

    /**
     * @param  array<string, array{options: list<string>, value: string|null}>  $entries
     * @param  list<array{group: string, option: string|null, source: string}>  $applied
     */
    private function applyGroupDefaults(ModelDefinition $definition, array &$entries, array &$applied): void
    {
        foreach ($definition->groups as $groupCode => $group) {
            if (isset($entries[$groupCode])) {
                continue;
            }

            if ($group->type === ConfiguratorOptionGroup::Range) {
                if ($group->default_value !== null) {
                    $entries[$groupCode] = ['options' => [], 'value' => $group->default_value];
                    $applied[] = ['group' => $groupCode, 'option' => null, 'source' => 'group_default'];
                }

                continue;
            }

            $defaults = array_values(array_filter($definition->optionsOf($groupCode), static fn (ConfiguratorOption $option): bool => $option->is_default && $option->is_active));

            if ($defaults === []) {
                continue;
            }

            $chosen = $group->type === ConfiguratorOptionGroup::Multi ? $defaults : [$defaults[0]];
            $entries[$groupCode] = ['options' => array_map(static fn (ConfiguratorOption $option): string => $option->code, $chosen), 'value' => null];

            foreach ($chosen as $option) {
                $applied[] = ['group' => $groupCode, 'option' => $option->code, 'source' => 'option_default'];
            }
        }
    }

    /**
     * @param  array<string, array{options: list<string>, value: string|null}>  $entries
     * @param  list<array{group: string, option: string|null, source: string}>  $applied
     */
    private function applyDefaultRules(ModelDefinition $definition, array &$entries, array &$applied): void
    {
        $rules = $definition->rulesOfType(ConfiguratorRule::Default);

        for ($pass = 0; $pass < (int) config('configurator.max_default_passes', 10); $pass++) {
            $changed = false;
            [$selected, $ranges] = $this->index($entries);

            foreach ($rules as $rule) {
                if (! RuleMatcher::matches($rule, $selected, $ranges)) {
                    continue;
                }

                foreach ($rule->target_options ?? [] as $code) {
                    $option = $definition->options[$code] ?? null;
                    $groupCode = $definition->groupOfOption[$code] ?? null;

                    if ($option === null || $groupCode === null || ! $option->is_active || isset($entries[$groupCode]) || $definition->groups[$groupCode]->type === ConfiguratorOptionGroup::Range) {
                        continue;
                    }

                    $entries[$groupCode] = ['options' => [$code], 'value' => null];
                    $applied[] = ['group' => $groupCode, 'option' => $code, 'source' => 'rule:'.$rule->id];
                    $changed = true;
                }
            }

            if (! $changed) {
                return;
            }
        }
    }

    /**
     * @param  array<string, array{options: list<string>, value: string|null}>  $entries
     * @param  list<array<string, mixed>>  $violations
     */
    private function checkGroups(ModelDefinition $definition, array $entries, array &$violations): void
    {
        foreach ($definition->groups as $groupCode => $group) {
            $entry = $entries[$groupCode] ?? ['options' => [], 'value' => null];
            $count = $group->type === ConfiguratorOptionGroup::Range ? ($entry['value'] === null ? 0 : 1) : count($entry['options']);

            if ($group->is_required && $count === 0) {
                $violations[] = $this->violation('required_group_missing', "{$group->name} is required.", $groupCode, params: ['group' => $groupCode, 'name' => $group->name]);

                continue;
            }

            if ($group->type !== ConfiguratorOptionGroup::Multi) {
                continue;
            }

            if ($group->min_select !== null && $count < $group->min_select && $count > 0) {
                $violations[] = $this->violation('multi_below_min', "Choose at least {$group->min_select} options of {$group->name}.", $groupCode, params: ['group' => $groupCode, 'name' => $group->name, 'min' => $group->min_select]);
            }

            if ($group->max_select !== null && $count > $group->max_select) {
                $violations[] = $this->violation('multi_above_max', "Choose at most {$group->max_select} options of {$group->name}.", $groupCode, params: ['group' => $groupCode, 'name' => $group->name, 'max' => $group->max_select]);
            }
        }
    }

    /**
     * @param  array<string, true>  $selected
     * @param  array<string, string>  $ranges
     * @param  list<array<string, mixed>>  $violations
     */
    private function checkRules(ModelDefinition $definition, array $selected, array $ranges, array &$violations): void
    {
        foreach ($definition->rules as $rule) {
            if (! in_array($rule->type, [ConfiguratorRule::Requires, ConfiguratorRule::Excludes], true) || ! RuleMatcher::matches($rule, $selected, $ranges)) {
                continue;
            }

            foreach ($rule->target_options ?? [] as $code) {
                $name = $definition->options[$code]->name ?? $code;
                $groupCode = $definition->groupOfOption[$code] ?? null;

                if ($rule->type === ConfiguratorRule::Requires && ! isset($selected[$code])) {
                    $violations[] = $this->violation('requires', $rule->message ?: "{$rule->name}: {$name} is required.", $groupCode, $code, $rule->id, ['rule' => $rule->name, 'option' => $code, 'name' => $name]);
                }

                if ($rule->type === ConfiguratorRule::Excludes && isset($selected[$code])) {
                    $violations[] = $this->violation('excludes', $rule->message ?: "{$rule->name}: {$name} cannot be combined with the other choices.", $groupCode, $code, $rule->id, ['rule' => $rule->name, 'option' => $code, 'name' => $name]);
                }
            }
        }
    }

    /**
     * @param  array<string, array{options: list<string>, value: string|null}>  $entries
     * @return array{0: array<string, true>, 1: array<string, string>}
     */
    private function index(array $entries): array
    {
        $selected = [];
        $ranges = [];

        foreach ($entries as $groupCode => $entry) {
            foreach ($entry['options'] as $code) {
                $selected[$code] = true;
            }

            if ($entry['value'] !== null) {
                $ranges[$groupCode] = $entry['value'];
            }
        }

        return [$selected, $ranges];
    }

    private function bound(?string $value): string
    {
        return $value === null ? '-' : ConfiguratorMath::trim($value);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{code: string, severity: string, group: string|null, option: string|null, rule_id: string|null, message: string, params: array<string, mixed>}
     */
    private function violation(string $code, string $message, ?string $group = null, ?string $option = null, ?string $ruleId = null, array $params = []): array
    {
        return ['code' => $code, 'severity' => 'error', 'group' => $group, 'option' => $option, 'rule_id' => $ruleId, 'message' => $message, 'params' => $params];
    }
}
