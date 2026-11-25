<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Domain\Support;

use Modules\CorporateConventions\Domain\Enums\RuleType;
use Modules\CorporateConventions\Domain\Enums\SubsidyType;
use Modules\CorporateConventions\Domain\ValueObjects\BeneficiaryBalance;
use Modules\CorporateConventions\Domain\ValueObjects\CartLine;
use Modules\CorporateConventions\Domain\ValueObjects\ConventionTerms;
use Modules\CorporateConventions\Domain\ValueObjects\DiscountRuleSpec;
use Modules\CorporateConventions\Domain\ValueObjects\PricingResult;

/**
 * Applies the discount matrix of a convention to a cart and splits the payable amount between employer and employee.
 *
 * Rules are tried in ascending priority. For each line:
 *  - a rule that targets the line (category, product family, price list, global; promotional items are skipped when the rule
 *    excludes them) and whose minimum cart amount is met is a candidate;
 *  - a non-cumulative rule never stacks: it is computed on the undiscounted line price and replaces the discount the line already
 *    carries only when it gives a lower price, and it ends the chain;
 *  - a cumulative rule is computed on the price left by the previous discounts and the chain goes on;
 *  - a rule never raises a price.
 * The annual spending cap limits the discounted spend of the beneficiary: when the cart exceeds what is left, the convention discount
 * is scaled to the part that fits. The employer subsidy is computed on the amount payable after the discount.
 */
final class DiscountMatrixEvaluator
{
    /**
     * @param  list<CartLine>  $lines
     * @param  list<DiscountRuleSpec>  $rules
     */
    public function evaluate(array $lines, array $rules, ConventionTerms $terms, BeneficiaryBalance $balance): PricingResult
    {
        usort($rules, static fn (DiscountRuleSpec $a, DiscountRuleSpec $b): int => $a->priority <=> $b->priority);

        $prepared = array_map(fn (CartLine $line): array => $this->prepare($line), $lines);
        $cartBase = '0';

        foreach ($prepared as $row) {
            $cartBase = Dec::add($cartBase, $row['before']);
        }

        foreach ($prepared as $index => $row) {
            $prepared[$index] = $this->applyRules($row, $rules, $cartBase);
        }

        $eligible = '0';

        foreach ($prepared as $row) {
            if (Dec::cmp($row['discount'], '0') > 0) {
                $eligible = Dec::add($eligible, $row['current']);
            }
        }

        $capRemaining = $terms->annualSpendingCap === null ? null : self::nonNegative(Dec::sub($terms->annualSpendingCap, $balance->usedAmount));
        [$ratio, $counted, $capReached] = $this->capRatio($eligible, $capRemaining);

        $resultLines = [];
        $gross = '0';
        $discount = '0';
        $cost = '0';

        foreach ($prepared as $row) {
            $lineDiscount = $ratio === null ? $row['discount'] : Dec::round(Dec::mul($row['discount'], $ratio));
            $net = Dec::round(Dec::sub($row['before'], $lineDiscount));
            $gross = Dec::add($gross, $row['before']);
            $discount = Dec::add($discount, $lineDiscount);
            $cost = Dec::add($cost, $row['cost']);
            $resultLines[] = [
                'line_id' => $row['line']->lineId,
                'gross' => Dec::round($row['before']),
                'existing_discount' => Dec::round($row['existing']),
                'convention_discount' => Dec::round($lineDiscount),
                'net' => $net,
                'cost' => Dec::round($row['cost']),
                'applied_rule_ids' => $ratio !== null && Dec::cmp($ratio, '0') === 0 ? [] : $row['rules'],
            ];
        }

        $gross = Dec::round($gross);
        $discount = Dec::round($discount);
        $payable = Dec::round(Dec::sub($gross, $discount));
        [$subsidy, $subsidyRemaining] = $this->subsidy($payable, $terms, $balance);

        return new PricingResult(
            $resultLines,
            $gross,
            $discount,
            $payable,
            $subsidy,
            Dec::round(Dec::sub($payable, $subsidy)),
            Dec::round($cost),
            $counted,
            $capRemaining,
            $capReached,
            $subsidyRemaining,
        );
    }

    /**
     * @return array{line: CartLine, gross: string, existing: string, before: string, current: string, discount: string, cost: string, rules: list<string>}
     */
    private function prepare(CartLine $line): array
    {
        $gross = Dec::round(Dec::mul($line->unitPrice, $line->quantity));
        $existing = Dec::round(Dec::mul($gross, Dec::div($line->existingDiscountPercent, '100')));
        $before = Dec::round(Dec::sub($gross, $existing));

        return [
            'line' => $line,
            'gross' => $gross,
            'existing' => $existing,
            'before' => $before,
            'current' => $before,
            'discount' => '0.00',
            'cost' => Dec::round(Dec::mul($line->unitCost, $line->quantity)),
            'rules' => [],
        ];
    }

    /**
     * @param  array{line: CartLine, gross: string, existing: string, before: string, current: string, discount: string, cost: string, rules: list<string>}  $row
     * @param  list<DiscountRuleSpec>  $rules
     * @return array{line: CartLine, gross: string, existing: string, before: string, current: string, discount: string, cost: string, rules: list<string>}
     */
    private function applyRules(array $row, array $rules, string $cartBase): array
    {
        $line = $row['line'];

        foreach ($rules as $rule) {
            if (! $rule->targets($line)) {
                continue;
            }

            if ($rule->minCartAmount !== null && Dec::cmp($cartBase, $rule->minCartAmount) < 0) {
                continue;
            }

            $candidate = $this->candidate($rule, $row);

            if (Dec::cmp($candidate, $row['current']) >= 0) {
                continue;
            }

            $row['current'] = $candidate;
            $row['rules'][] = $rule->id;

            if (! $rule->cumulativeWithOtherDiscounts) {
                break;
            }
        }

        $row['discount'] = Dec::round(Dec::sub($row['before'], $row['current']));

        return $row;
    }

    /**
     * @param  array{line: CartLine, gross: string, existing: string, before: string, current: string, discount: string, cost: string, rules: list<string>}  $row
     */
    private function candidate(DiscountRuleSpec $rule, array $row): string
    {
        $line = $row['line'];
        $factor = Dec::sub('1', Dec::div($rule->discountPercentage, '100'));

        if ($rule->type === RuleType::PriceList) {
            $listTotal = Dec::round(Dec::mul($line->priceListUnitPrices[$rule->targetPriceListId] ?? $line->unitPrice, $line->quantity));
            $base = $rule->cumulativeWithOtherDiscounts
                ? Dec::round(Dec::mul($listTotal, Dec::sub('1', Dec::div($line->existingDiscountPercent, '100'))))
                : $listTotal;

            return Dec::round(Dec::mul($base, $factor));
        }

        $base = $rule->cumulativeWithOtherDiscounts ? $row['current'] : $row['gross'];

        return Dec::round(Dec::mul($base, $factor));
    }

    /**
     * @return array{0: string|null, 1: string, 2: bool} scale applied to the discounts (null when uncapped), spend counted against the cap, cap reached
     */
    private function capRatio(string $eligible, ?string $remaining): array
    {
        if ($remaining === null) {
            return [null, Dec::round($eligible), false];
        }

        if (Dec::cmp($eligible, $remaining) <= 0) {
            return [null, Dec::round($eligible), Dec::cmp($eligible, $remaining) === 0 && Dec::cmp($eligible, '0') > 0];
        }

        $counted = Dec::round($remaining);

        return [Dec::div($counted, $eligible), $counted, true];
    }

    /**
     * @return array{0: string, 1: string|null} subsidy of this purchase and what was left of the annual subsidy cap before it
     */
    private function subsidy(string $payable, ConventionTerms $terms, BeneficiaryBalance $balance): array
    {
        $remaining = $terms->subsidyAnnualCap === null ? null : self::nonNegative(Dec::sub($terms->subsidyAnnualCap, $balance->usedSubsidy));

        if (! $terms->subsidyEnabled || ! $balance->subsidyEligible || $terms->subsidyType === null || $terms->subsidyValue === null || Dec::cmp($payable, '0') <= 0) {
            return ['0.00', $remaining];
        }

        $raw = $terms->subsidyType === SubsidyType::FixedAmount
            ? $terms->subsidyValue
            : Dec::mul($payable, Dec::div($terms->subsidyValue, '100'));
        $subsidy = Dec::round($raw);

        if (Dec::cmp($subsidy, $payable) > 0) {
            $subsidy = $payable;
        }

        if ($remaining !== null && Dec::cmp($subsidy, $remaining) > 0) {
            $subsidy = $remaining;
        }

        return [Dec::round($subsidy), $remaining];
    }

    private static function nonNegative(string $value): string
    {
        return Dec::cmp($value, '0') < 0 ? '0.00' : Dec::round($value);
    }
}
