<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Support;

use Modules\CpqConfigurator\Models\CpqPricingFormula;

/**
 * Removes what only who may see the industrial figures is allowed to read: costs, margins and hourly rates.
 * Prices, discounts, quantities and lead times stay.
 */
final class CostRedactor
{
    /** @var list<string> */
    private const array TotalKeys = ['base_cost', 'bom_cost', 'routing_cost', 'industrial_cost', 'margin_amount', 'margin_percent', 'target_margin_applied'];

    /**
     * @param  array<string, mixed>  $evaluation
     * @return array<string, mixed>
     */
    public static function evaluation(array $evaluation): array
    {
        foreach (self::TotalKeys as $key) {
            unset($evaluation['totals'][$key]);
        }

        $evaluation['bom'] = array_map(static fn (array $line): array => array_diff_key($line, array_flip(['unit_cost', 'line_cost'])), $evaluation['bom'] ?? []);
        $evaluation['routing'] = array_map(static fn (array $step): array => array_diff_key($step, array_flip(['hourly_rate', 'cost'])), $evaluation['routing'] ?? []);
        $evaluation['pricing']['components'] = array_values(array_filter($evaluation['pricing']['components'] ?? [], static fn (array $component): bool => $component['effect'] !== CpqPricingFormula::AddCost));
        $evaluation['pricing']['errors'] = [];

        return $evaluation;
    }
}
