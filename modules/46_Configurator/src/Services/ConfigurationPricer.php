<?php

declare(strict_types=1);

namespace Modules\Configurator\Services;

use App\Services\Sales\DocumentTotalsCalculator;
use Illuminate\Support\Str;
use Modules\Configurator\Models\ConfigurableModel;
use Modules\Configurator\Models\ConfiguratorOptionGroup;
use Modules\Configurator\Models\ConfiguratorRule;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Contracts\PricingAndDiscountServiceInterface;

/**
 * Prices a resolved selection in decimal arithmetic.
 *
 * Per configured unit: base price + option price deltas + range value x unit price, plus the amount of every matching
 * price rule (a fixed amount, or a percentage of the component subtotal), all multiplied by the product of the option
 * multipliers. The result is split into one component line each (base, options, ranges, rule adjustments) whose
 * rounding residual goes to the base line, so the lines add up to the unit price exactly.
 *
 * Discounts apply per line as percentages that add up (capped at 100): the quantity break of the model, the discount
 * the company price engine gives a linked product (price lists and promotions) and the extra discount asked for the
 * configuration. A component that is linked to a product and flagged price_from_product takes its list price from that
 * engine instead of its own price delta. Totals come from the document calculator of the sales cycle, so they match
 * the quote the configuration becomes. The margin guard compares the net revenue with the cost.
 */
final readonly class ConfigurationPricer
{
    public function __construct(private PricingAndDiscountServiceInterface $pricing) {}

    /**
     * @param  array{selected: array<string, true>, ranges: array<string, string>}  $resolved
     * @param  array{quantity: string, contact_id?: string|null, price_list_id?: string|null, date?: string|null, discount_percent?: string|null}  $context
     * @return array<string, mixed>
     */
    public function price(ModelDefinition $definition, array $resolved, array $context): array
    {
        $model = $definition->model;
        $quantity = Decimal::normalize($context['quantity']);
        $extraPercent = Decimal::normalize($context['discount_percent'] ?? '0');
        $multiplier = '1';
        $unitCost = $model->base_cost;
        $components = [$this->baseComponent($model, $quantity, $context)];

        foreach ($definition->groups as $groupCode => $group) {
            if ($group->type === ConfiguratorOptionGroup::Range) {
                $value = $resolved['ranges'][$groupCode] ?? null;

                if ($value !== null) {
                    $unit = $group->unit !== null ? ' '.$group->unit : '';
                    $components[] = $this->component('range', $groupCode, "{$group->name}: ".ConfiguratorMath::trim($value).$unit, $group->product_id, ConfiguratorMath::round4(Decimal::mul($value, $group->unit_price_delta)), '0', 'group');
                    $unitCost = Decimal::add($unitCost, Decimal::mul($value, $group->unit_cost));
                }

                continue;
            }

            foreach ($definition->optionsOf($groupCode) as $option) {
                if (! isset($resolved['selected'][$option->code])) {
                    continue;
                }

                $price = $option->price_delta;
                $policyPercent = '0';
                $source = 'option';

                if ($option->price_from_product && $option->product_id !== null) {
                    [$price, $policyPercent, $source] = $this->productPrice($option->product_id, $quantity, $context);
                }

                $components[] = $this->component('option', $option->code, "{$group->name}: {$option->name}", $option->product_id, $price, $policyPercent, $source);
                $unitCost = Decimal::add($unitCost, $option->cost);
                $multiplier = Decimal::mul($multiplier, $option->price_multiplier);
            }
        }

        $subtotal = array_reduce($components, static fn (string $sum, array $component): string => Decimal::add($sum, $component['unit_price']), '0');
        $adjustments = $this->priceRuleAdjustments($definition, $resolved, $subtotal);
        $adjustmentTotal = array_reduce($adjustments, static fn (string $sum, array $adjustment): string => Decimal::add($sum, $adjustment['amount']), '0');
        $unitListPrice = ConfiguratorMath::round4(Decimal::mul(Decimal::add($subtotal, $adjustmentTotal), $multiplier));

        $lines = $this->scaleToLines($components, $adjustments, $multiplier, $unitListPrice);

        $volumePercent = $this->volumePercent($model, $quantity);
        $items = [];

        foreach ($lines as &$line) {
            $line['discount_percentage'] = ConfiguratorMath::round(self::capPercent(Decimal::add(Decimal::add($volumePercent, $extraPercent), $line['policy_percent'])), 2);
            $items[] = ['quantity' => $quantity, 'unit_price' => $line['unit_price'], 'tax_rate' => $model->tax_rate, 'discount_percentage' => $line['discount_percentage']];
        }
        unset($line);

        $totals = (new DocumentTotalsCalculator)->calculateDecimal($items);

        foreach ($lines as $index => &$line) {
            $line['quantity'] = $quantity;
            $line['line_total'] = $totals['items'][$index]['line_total'];
            $line['tax_rate'] = $model->tax_rate;
            unset($line['policy_percent']);
        }
        unset($line);

        $totalCost = Decimal::round2(Decimal::mul($unitCost, $quantity));
        $margin = Decimal::round2(Decimal::sub($totals['total_net'], $totalCost));
        $marginPercent = ConfiguratorMath::marginPercent($totals['total_net'], $totalCost);
        $minMargin = $model->min_margin_percent ?? Decimal::round2((string) config('configurator.default_min_margin_percent', '15'));
        $belowMinimum = $marginPercent === null ? Decimal::isPositive($totalCost) : Decimal::compare($marginPercent, $minMargin) < 0;

        return [
            'currency' => $model->currency,
            'quantity' => $quantity,
            'multiplier' => $multiplier,
            'unit_list_price' => $unitListPrice,
            'unit_net_price' => Decimal::isPositive($quantity) ? ConfiguratorMath::round4(Decimal::div($totals['total_net'], $quantity)) : '0.0000',
            'unit_cost' => ConfiguratorMath::round4($unitCost),
            'subtotal' => $totals['subtotal'],
            'discount_total' => $totals['discount_total'],
            'total_net' => $totals['total_net'],
            'total_tax' => $totals['total_tax'],
            'total_gross' => $totals['total_gross'],
            'total_cost' => $totalCost,
            'margin_amount' => $margin,
            'margin_percent' => $marginPercent,
            'min_margin_percent' => $minMargin,
            'below_minimum_margin' => $belowMinimum,
            'requires_approval' => $belowMinimum,
            'volume_discount_percent' => Decimal::round2($volumePercent),
            'extra_discount_percent' => Decimal::round2($extraPercent),
            'lines' => $lines,
            'applied_rules' => array_map(static fn (array $adjustment): array => ['rule_id' => $adjustment['rule_id'], 'name' => $adjustment['name'], 'amount' => ConfiguratorMath::round4($adjustment['amount'])], $adjustments),
        ];
    }

    /**
     * @param  array{quantity: string, contact_id?: string|null, price_list_id?: string|null, date?: string|null}  $context
     * @return array<string, mixed>
     */
    private function baseComponent(ConfigurableModel $model, string $quantity, array $context): array
    {
        $price = $model->base_price;
        $policyPercent = '0';
        $source = 'model';

        if ($model->base_price_from_product && $model->base_product_id !== null) {
            [$price, $policyPercent, $source] = $this->productPrice($model->base_product_id, $quantity, $context);
        }

        return $this->component('base', $model->code, $model->name, $model->base_product_id, $price, $policyPercent, $source);
    }

    /**
     * @return array<string, mixed>
     */
    private function component(string $kind, string $code, string $description, ?string $productId, string $price, string $policyPercent, string $source): array
    {
        return [
            'kind' => $kind,
            'code' => $code,
            'description' => Str::limit($description, 240, ''),
            'product_id' => $productId,
            'unit_price' => Decimal::normalize($price),
            'policy_percent' => Decimal::normalize($policyPercent),
            'price_source' => $source,
        ];
    }

    /**
     * The list price and discount percentage the sales price engine gives a product for the customer, quantity and date.
     *
     * @param  array{contact_id?: string|null, price_list_id?: string|null, date?: string|null}  $context
     * @return array{0: string, 1: string, 2: string}
     */
    private function productPrice(string $productId, string $quantity, array $context): array
    {
        $price = $this->pricing->determineLinePrice($productId, $quantity, $context['contact_id'] ?? null, $context['price_list_id'] ?? null, null, $context['date'] ?? null, null);

        return [(string) $price['unit_price'], (string) $price['discount_percentage'], (string) $price['price_source']];
    }

    /**
     * @param  array{selected: array<string, true>, ranges: array<string, string>}  $resolved
     * @return list<array{rule_id: string, name: string, amount: string}>
     */
    private function priceRuleAdjustments(ModelDefinition $definition, array $resolved, string $subtotal): array
    {
        $adjustments = [];

        foreach ($definition->rulesOfType(ConfiguratorRule::PriceRule) as $rule) {
            if ($rule->price_effect_value === null || ! RuleMatcher::matches($rule, $resolved['selected'], $resolved['ranges'])) {
                continue;
            }

            $amount = $rule->price_effect_type === 'percent' ? ConfiguratorMath::percentOf($subtotal, $rule->price_effect_value) : $rule->price_effect_value;
            $adjustments[] = ['rule_id' => $rule->id, 'name' => $rule->name, 'amount' => $amount];
        }

        return $adjustments;
    }

    /**
     * Multiplies every component and adjustment by the option multipliers, rounds each to 4 decimals and moves the
     * rounding residual onto the base line.
     *
     * @param  list<array<string, mixed>>  $components
     * @param  list<array{rule_id: string, name: string, amount: string}>  $adjustments
     * @return list<array<string, mixed>>
     */
    private function scaleToLines(array $components, array $adjustments, string $multiplier, string $target): array
    {
        $lines = [];
        $sum = '0';

        foreach ($components as $component) {
            $component['unit_price'] = ConfiguratorMath::round4(Decimal::mul($component['unit_price'], $multiplier));
            $sum = Decimal::add($sum, $component['unit_price']);
            $lines[] = $component;
        }

        foreach ($adjustments as $adjustment) {
            $price = ConfiguratorMath::round4(Decimal::mul($adjustment['amount'], $multiplier));
            $sum = Decimal::add($sum, $price);
            $lines[] = $this->component('adjustment', 'rule:'.$adjustment['rule_id'], $adjustment['name'], null, $price, '0', 'rule');
        }

        $lines[0]['unit_price'] = ConfiguratorMath::round4(Decimal::add($lines[0]['unit_price'], Decimal::sub($target, $sum)));

        return $lines;
    }

    /**
     * The discount of the highest quantity break reached by the quantity.
     */
    private function volumePercent(ConfigurableModel $model, string $quantity): string
    {
        $percent = '0';
        $reached = '-1';

        foreach ($model->volume_breaks ?? [] as $break) {
            $minimum = Decimal::normalize((string) $break['min_quantity']);

            if (Decimal::compare($quantity, $minimum) >= 0 && Decimal::compare($minimum, $reached) > 0) {
                $reached = $minimum;
                $percent = Decimal::normalize((string) $break['discount_percent']);
            }
        }

        return $percent;
    }

    /**
     * A percentage capped at 100.
     */
    private static function capPercent(string $percent): string
    {
        return Decimal::compare($percent, '100') > 0 ? '100' : $percent;
    }
}
