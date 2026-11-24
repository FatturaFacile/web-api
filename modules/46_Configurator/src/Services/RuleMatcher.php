<?php

declare(strict_types=1);

namespace Modules\Configurator\Services;

use Modules\Configurator\Models\ConfiguratorRule;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Decides whether the conditions of a rule hold for a selection. A rule without conditions always applies.
 */
final class RuleMatcher
{
    /**
     * @param  array<string, true>  $selected  option codes chosen
     * @param  array<string, string>  $ranges  numeric value by group code
     */
    public static function matches(ConfiguratorRule $rule, array $selected, array $ranges): bool
    {
        $conditions = $rule->conditions ?? [];

        if ($conditions === []) {
            return true;
        }

        $results = array_map(static fn (array $condition): bool => self::holds($condition, $selected, $ranges), $conditions);

        return $rule->match === 'any' ? in_array(true, $results, true) : ! in_array(false, $results, true);
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, true>  $selected
     * @param  array<string, string>  $ranges
     */
    public static function holds(array $condition, array $selected, array $ranges): bool
    {
        if (($condition['type'] ?? 'option') === 'range') {
            $group = (string) ($condition['group'] ?? '');

            if (! isset($ranges[$group])) {
                return false;
            }

            $comparison = Decimal::compare($ranges[$group], (string) ($condition['value'] ?? '0'));

            return match ($condition['operator'] ?? 'eq') {
                'gt' => $comparison > 0,
                'gte' => $comparison >= 0,
                'lt' => $comparison < 0,
                'lte' => $comparison <= 0,
                default => $comparison === 0,
            };
        }

        $present = isset($selected[(string) ($condition['option'] ?? '')]);

        return ($condition['negate'] ?? false) === true ? ! $present : $present;
    }
}
