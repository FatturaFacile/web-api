<?php

declare(strict_types=1);

namespace Modules\CpqConfigurator\Services;

use Modules\CpqConfigurator\Exceptions\ExpressionException;
use Modules\CpqConfigurator\Models\CpqPricingFormula;
use Modules\CpqConfigurator\Services\Expression\ExpressionEvaluator;
use Modules\CpqConfigurator\Support\Localized;

/**
 * Resolves the dynamic super-BOM and routing of a configuration and prices it in real time: industrial cost, list
 * price, selling price, margin and lead time. Every quantity, hour and price is a formula over the configuration,
 * evaluated by the safe expression engine; a formula that fails is reported in `errors` and counts as zero.
 *
 * Lead time = base lead time + the longest purchasing lead time among the BOM lines in force (they run in parallel)
 * + the routing hours converted in working days of 8 hours.
 *
 * @phpstan-import-type Definition from ModelDefinitionLoader
 */
final class PricingEngine
{
    public const float HoursPerDay = 8.0;

    public function __construct(private readonly ExpressionEvaluator $evaluator = new ExpressionEvaluator) {}

    /**
     * @param  Definition  $definition
     * @param  array<string, mixed>  $variables  answers, attr.* and derived values of the solver
     * @return array{
     *     bom: list<array<string, mixed>>, routing: list<array<string, mixed>>, components: list<array<string, mixed>>, errors: list<array<string, string>>,
     *     totals: array<string, mixed>
     * }
     */
    public function price(array $definition, array $variables, float $quantity = 1.0, float $extraDiscountPercent = 0.0): array
    {
        $variables['quantity'] = $quantity;
        $errors = [];

        $bom = $this->bom($definition['bom'], $variables, $errors);
        $routing = $this->routing($definition['routing'], $variables, $errors);

        $bomCost = round(array_sum(array_column($bom, 'line_cost')), 4);
        $routingCost = round(array_sum(array_column($routing, 'cost')), 4);
        $cost = $definition['base_cost'] + $bomCost + $routingCost;
        $list = $definition['base_price'];
        $discount = 0.0;
        $components = [];

        foreach ($definition['pricing'] as $formula) {
            if (! $formula['active']) {
                continue;
            }

            $context = [...$variables, 'bom_cost' => $bomCost, 'routing_cost' => $routingCost, 'cost_subtotal' => round($cost, 4), 'list_subtotal' => round($list, 4)];

            try {
                if (! $this->evaluator->isTrue($formula['condition'], $context)) {
                    continue;
                }

                $value = $this->evaluator->number($formula['formula'], $context);
            } catch (ExpressionException $exception) {
                $errors[] = ['code' => (string) $formula['code'], 'message' => $exception->getMessage()];

                continue;
            }

            match ($formula['effect']) {
                CpqPricingFormula::AddCost => $cost += $value,
                CpqPricingFormula::AddPrice => $list += $value,
                CpqPricingFormula::SetPrice => $list = $value,
                CpqPricingFormula::MultiplyPrice => $list *= $value,
                CpqPricingFormula::DiscountPercent => $discount += $value,
                default => null,
            };

            $components[] = ['code' => $formula['code'], 'label' => Localized::pick($formula['label']), 'label_i18n' => $formula['label'], 'effect' => $formula['effect'], 'value' => round($value, 4)];
        }

        $cost = round($cost, 4);
        $markupApplied = false;

        if ($list <= 0 && $definition['target_margin_percent'] !== null && $definition['target_margin_percent'] < 100 && $cost > 0) {
            $list = $cost / (1 - $definition['target_margin_percent'] / 100);
            $markupApplied = true;
        }

        $list = round($list, 4);
        $discount = min(max($discount + $extraDiscountPercent, 0.0), $definition['max_discount_percent']);
        $selling = round($list * (1 - $discount / 100), 4);
        $margin = round($selling - $cost, 4);
        $marginPercent = $selling > 0 ? round($margin / $selling * 100, 2) : 0.0;

        $hours = array_sum(array_column($routing, 'hours'));
        $longestPurchase = $bom === [] ? 0 : max(array_column($bom, 'lead_time_days'));
        $leadTime = $definition['base_lead_time_days'] + (int) $longestPurchase + (int) ceil($hours / self::HoursPerDay - 1e-9);

        return [
            'bom' => $bom,
            'routing' => $routing,
            'components' => $components,
            'errors' => $errors,
            'totals' => [
                'currency' => $definition['currency'],
                'quantity' => $quantity,
                'base_cost' => round($definition['base_cost'], 4),
                'bom_cost' => $bomCost,
                'routing_cost' => $routingCost,
                'industrial_cost' => $cost,
                'list_price' => $list,
                'discount_percent' => round($discount, 2),
                'selling_price' => $selling,
                'margin_amount' => $margin,
                'margin_percent' => $marginPercent,
                'lead_time_days' => $leadTime,
                'routing_hours' => round($hours, 4),
                'total_selling_price' => round($selling * $quantity, 4),
                'target_margin_applied' => $markupApplied,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $mappings
     * @param  array<string, mixed>  $variables
     * @param  list<array<string, string>>  $errors
     * @return list<array<string, mixed>>
     */
    private function bom(array $mappings, array $variables, array &$errors): array
    {
        $lines = [];

        foreach ($mappings as $mapping) {
            if (! $mapping['active']) {
                continue;
            }

            try {
                if (! $this->evaluator->isTrue($mapping['condition'], $variables)) {
                    continue;
                }

                $quantity = $this->evaluator->number($mapping['quantity_formula'], $variables);

                if ($quantity <= 0) {
                    continue;
                }

                $unitCost = $mapping['unit_cost_formula'] !== null && trim((string) $mapping['unit_cost_formula']) !== ''
                    ? $this->evaluator->number($mapping['unit_cost_formula'], $variables)
                    : (float) $mapping['unit_cost'];
            } catch (ExpressionException $exception) {
                $errors[] = ['code' => (string) $mapping['code'], 'message' => $exception->getMessage()];

                continue;
            }

            $lines[] = [
                'code' => $mapping['code'],
                'item_code' => $mapping['item_code'],
                'name' => Localized::pick($mapping['item_name']),
                'name_i18n' => $mapping['item_name'],
                'uom' => $mapping['uom'],
                'quantity' => $quantity,
                'unit_cost' => round($unitCost, 4),
                'line_cost' => round($quantity * $unitCost, 4),
                'lead_time_days' => (int) $mapping['lead_time_days'],
                'auto_include_message' => $mapping['auto_include_message'] === null ? null : Localized::message($mapping['auto_include_message'], $variables),
            ];
        }

        return $lines;
    }

    /**
     * @param  list<array<string, mixed>>  $mappings
     * @param  array<string, mixed>  $variables
     * @param  list<array<string, string>>  $errors
     * @return list<array<string, mixed>>
     */
    private function routing(array $mappings, array $variables, array &$errors): array
    {
        $steps = [];

        foreach ($mappings as $mapping) {
            if (! $mapping['active']) {
                continue;
            }

            try {
                if (! $this->evaluator->isTrue($mapping['condition'], $variables)) {
                    continue;
                }

                $setup = max(0.0, $this->evaluator->number($mapping['setup_hours_formula'], $variables));
                $run = max(0.0, $this->evaluator->number($mapping['run_hours_formula'], $variables));
            } catch (ExpressionException $exception) {
                $errors[] = ['code' => (string) $mapping['code'], 'message' => $exception->getMessage()];

                continue;
            }

            if ($setup + $run <= 0) {
                continue;
            }

            $steps[] = [
                'code' => $mapping['code'],
                'operation' => Localized::pick($mapping['operation_name']),
                'operation_i18n' => $mapping['operation_name'],
                'work_center' => $mapping['work_center'],
                'setup_hours' => $setup,
                'run_hours' => $run,
                'hours' => round($setup + $run, 4),
                'hourly_rate' => round((float) $mapping['hourly_rate'], 4),
                'cost' => round(($setup + $run) * (float) $mapping['hourly_rate'], 4),
            ];
        }

        return $steps;
    }
}
