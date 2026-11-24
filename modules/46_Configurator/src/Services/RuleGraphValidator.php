<?php

declare(strict_types=1);

namespace Modules\Configurator\Services;

use Modules\Configurator\Models\ConfiguratorOptionGroup;
use Modules\Configurator\Models\ConfiguratorRule;

/**
 * Save-time analysis of the rule set of a model. It looks at the rules as a graph over option codes and reports what
 * could never be satisfied or would loop:
 *
 * - unknown_option, unknown_group, condition_group_not_range, inactive_target: a rule points at something that does
 *   not exist, is not a range, or is switched off;
 * - self_reference: an option is part of the condition of the rule that requires, excludes or defaults it;
 * - requires_cycle: option A (through any chain of `requires` rules) needs itself;
 * - requires_excludes_conflict: one set of choices both requires and excludes the same option;
 * - default_excluded: a default rule enables an option that another rule excludes in the same situation;
 * - requires_same_group: one set of choices requires two different options of the same single-choice group.
 *
 * Two triggers are comparable when one is contained in the other, so that whenever the larger one holds the smaller one
 * holds too. Rules with a negated or numeric condition take part in the reference checks only: the other checks are
 * conservative and never report a conflict that may not happen.
 */
final class RuleGraphValidator
{
    /**
     * @param  list<array{id: string|null, name: string, type: string, match: string, conditions: list<array<string, mixed>>, target_options: list<string>}>  $rules  active rules, the one being saved included
     * @return list<array{code: string, message: string, rule_ids: list<string>}>
     */
    public function problems(ModelDefinition $definition, array $rules): array
    {
        $problems = [];

        foreach ($rules as $rule) {
            $this->checkReferences($definition, $rule, $problems);
        }

        $analysable = array_values(array_filter($rules, static fn (array $rule): bool => self::triggers($rule) !== null && in_array($rule['type'], [ConfiguratorRule::Requires, ConfiguratorRule::Excludes, ConfiguratorRule::Default], true)));

        foreach ($analysable as $rule) {
            $clash = array_intersect($rule['target_options'], self::positiveOptions($rule));

            if ($clash !== []) {
                $problems[] = $this->problem('self_reference', "Rule \"{$rule['name']}\" has the option ".implode(', ', $clash).' both in its condition and as its target.', [$rule]);
            }
        }

        $this->checkRequiresCycles($analysable, $problems);
        $this->checkConflicts($definition, $analysable, $problems);

        return $this->unique($problems);
    }

    /**
     * @param  array{id: string|null, name: string, type: string, match: string, conditions: list<array<string, mixed>>, target_options: list<string>}  $rule
     * @param  list<array{code: string, message: string, rule_ids: list<string>}>  $problems
     */
    private function checkReferences(ModelDefinition $definition, array $rule, array &$problems): void
    {
        foreach ($rule['conditions'] as $condition) {
            if (($condition['type'] ?? 'option') === 'range') {
                $group = (string) ($condition['group'] ?? '');

                if (! isset($definition->groups[$group])) {
                    $problems[] = $this->problem('unknown_group', "Rule \"{$rule['name']}\" refers to the unknown group {$group}.", [$rule]);
                } elseif ($definition->groups[$group]->type !== ConfiguratorOptionGroup::Range) {
                    $problems[] = $this->problem('condition_group_not_range', "Rule \"{$rule['name']}\" compares a number with the group {$group}, which is not a range.", [$rule]);
                }

                continue;
            }

            $option = (string) ($condition['option'] ?? '');

            if (! isset($definition->options[$option])) {
                $problems[] = $this->problem('unknown_option', "Rule \"{$rule['name']}\" refers to the unknown option {$option}.", [$rule]);
            }
        }

        foreach ($rule['target_options'] as $target) {
            if (! isset($definition->options[$target])) {
                $problems[] = $this->problem('unknown_option', "Rule \"{$rule['name']}\" refers to the unknown option {$target}.", [$rule]);
            } elseif (! $definition->options[$target]->is_active) {
                $problems[] = $this->problem('inactive_target', "Rule \"{$rule['name']}\" targets the option {$target}, which is switched off.", [$rule]);
            }
        }
    }

    /**
     * @param  list<array{id: string|null, name: string, type: string, match: string, conditions: list<array<string, mixed>>, target_options: list<string>}>  $rules
     * @param  list<array{code: string, message: string, rule_ids: list<string>}>  $problems
     */
    private function checkRequiresCycles(array $rules, array &$problems): void
    {
        $edges = [];

        foreach ($rules as $rule) {
            if ($rule['type'] !== ConfiguratorRule::Requires) {
                continue;
            }

            foreach (self::positiveOptions($rule) as $from) {
                foreach ($rule['target_options'] as $to) {
                    $edges[$from][$to][] = $rule;
                }
            }
        }

        $visited = [];

        foreach (array_keys($edges) as $start) {
            $path = [];
            $cycle = $this->findCycle($start, $edges, $visited, $path);

            if ($cycle !== null) {
                $involved = [];

                foreach ($cycle as $index => $node) {
                    $next = $cycle[($index + 1) % count($cycle)];
                    $involved = [...$involved, ...($edges[$node][$next] ?? [])];
                }

                $problems[] = $this->problem('requires_cycle', 'The requires rules form a cycle: '.implode(' -> ', [...$cycle, $cycle[0]]).'.', $involved);
            }
        }
    }

    /**
     * Depth-first search that returns the nodes of the first cycle reachable from the node, or null.
     *
     * @param  array<string, array<string, list<array<string, mixed>>>>  $edges
     * @param  array<string, bool>  $visited
     * @param  list<string>  $path
     * @return list<string>|null
     */
    private function findCycle(string $node, array $edges, array &$visited, array &$path): ?array
    {
        $position = array_search($node, $path, true);

        if ($position !== false) {
            return array_slice($path, (int) $position);
        }

        if (isset($visited[$node])) {
            return null;
        }

        $path[] = $node;

        foreach (array_keys($edges[$node] ?? []) as $next) {
            $cycle = $this->findCycle($next, $edges, $visited, $path);

            if ($cycle !== null) {
                return $cycle;
            }
        }

        array_pop($path);
        $visited[$node] = true;

        return null;
    }

    /**
     * @param  list<array{id: string|null, name: string, type: string, match: string, conditions: list<array<string, mixed>>, target_options: list<string>}>  $rules
     * @param  list<array{code: string, message: string, rule_ids: list<string>}>  $problems
     */
    private function checkConflicts(ModelDefinition $definition, array $rules, array &$problems): void
    {
        $count = count($rules);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i; $j < $count; $j++) {
                $first = $rules[$i];
                $second = $rules[$j];

                if (! $this->comparable($first, $second)) {
                    continue;
                }

                $types = [$first['type'], $second['type']];
                sort($types);
                $shared = array_values(array_intersect($first['target_options'], $second['target_options']));

                if ($shared !== [] && $i !== $j) {
                    if ($types === [ConfiguratorRule::Excludes, ConfiguratorRule::Requires]) {
                        $problems[] = $this->problem('requires_excludes_conflict', "Rules \"{$first['name']}\" and \"{$second['name']}\" both require and exclude ".implode(', ', $shared).'.', [$first, $second]);
                    }

                    if ($types === [ConfiguratorRule::Default, ConfiguratorRule::Excludes]) {
                        $problems[] = $this->problem('default_excluded', "Rules \"{$first['name']}\" and \"{$second['name']}\" default and exclude ".implode(', ', $shared).'.', [$first, $second]);
                    }
                }

                if ($first['type'] === ConfiguratorRule::Requires && $second['type'] === ConfiguratorRule::Requires) {
                    $this->checkSameGroup($definition, $first, $second, $problems);
                }
            }
        }
    }

    /**
     * @param  array{id: string|null, name: string, type: string, match: string, conditions: list<array<string, mixed>>, target_options: list<string>}  $first
     * @param  array{id: string|null, name: string, type: string, match: string, conditions: list<array<string, mixed>>, target_options: list<string>}  $second
     * @param  list<array{code: string, message: string, rule_ids: list<string>}>  $problems
     */
    private function checkSameGroup(ModelDefinition $definition, array $first, array $second, array &$problems): void
    {
        foreach ($first['target_options'] as $left) {
            foreach ($second['target_options'] as $right) {
                $group = $definition->groupOfOption[$left] ?? null;

                if ($left === $right || $group === null || ($definition->groupOfOption[$right] ?? null) !== $group) {
                    continue;
                }

                if (in_array($definition->groups[$group]->type, [ConfiguratorOptionGroup::Single, ConfiguratorOptionGroup::Boolean], true)) {
                    $problems[] = $this->problem('requires_same_group', "Rules \"{$first['name']}\" and \"{$second['name']}\" require two different options of the single-choice group {$group}.", [$first, $second]);
                }
            }
        }
    }

    /**
     * Whether some trigger of one rule contains the trigger of the other, so that the two rules can hold together.
     *
     * @param  array{id: string|null, name: string, type: string, match: string, conditions: list<array<string, mixed>>, target_options: list<string>}  $first
     * @param  array{id: string|null, name: string, type: string, match: string, conditions: list<array<string, mixed>>, target_options: list<string>}  $second
     */
    private function comparable(array $first, array $second): bool
    {
        foreach (self::triggers($first) ?? [] as $left) {
            foreach (self::triggers($second) ?? [] as $right) {
                if (array_diff($left, $right) === [] || array_diff($right, $left) === []) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The sets of options of which each one, when chosen, makes the rule apply; null when a condition is negated or
     * numeric and the rule cannot be reduced to such sets.
     *
     * @param  array{match: string, conditions: list<array<string, mixed>>}  $rule
     * @return list<list<string>>|null
     */
    private static function triggers(array $rule): ?array
    {
        if ($rule['conditions'] === []) {
            return [[]];
        }

        $codes = [];

        foreach ($rule['conditions'] as $condition) {
            if (($condition['type'] ?? 'option') !== 'option' || ($condition['negate'] ?? false) === true) {
                return null;
            }

            $codes[] = (string) ($condition['option'] ?? '');
        }

        return $rule['match'] === 'any' ? array_map(static fn (string $code): array => [$code], $codes) : [$codes];
    }

    /**
     * @param  array{conditions: list<array<string, mixed>>}  $rule
     * @return list<string>
     */
    private static function positiveOptions(array $rule): array
    {
        $codes = [];

        foreach ($rule['conditions'] as $condition) {
            if (($condition['type'] ?? 'option') === 'option' && ($condition['negate'] ?? false) !== true) {
                $codes[] = (string) ($condition['option'] ?? '');
            }
        }

        return $codes;
    }

    /**
     * @param  list<array{id: string|null, name: string}>  $rules
     * @return array{code: string, message: string, rule_ids: list<string>}
     */
    private function problem(string $code, string $message, array $rules): array
    {
        return ['code' => $code, 'message' => $message, 'rule_ids' => array_values(array_unique(array_filter(array_map(static fn (array $rule): ?string => $rule['id'] ?? null, $rules))))];
    }

    /**
     * @param  list<array{code: string, message: string, rule_ids: list<string>}>  $problems
     * @return list<array{code: string, message: string, rule_ids: list<string>}>
     */
    private function unique(array $problems): array
    {
        $seen = [];

        foreach ($problems as $problem) {
            $seen[$problem['code'].'|'.$problem['message']] = $problem;
        }

        return array_values($seen);
    }
}
