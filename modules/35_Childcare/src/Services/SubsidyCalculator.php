<?php

declare(strict_types=1);

namespace Modules\Childcare\Services;

use App\Exceptions\BusinessRuleException;
use Modules\Childcare\Models\Child;
use Modules\Childcare\Models\SubsidyRule;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Applies the parameters of a verified cantonal subsidy rule to a child and a month. It implements three generic
 * methods only; the amounts, brackets and inputs are the parameters of the rule, entered and verified by the
 * kindergarten against the official source of its canton.
 *
 * Parameters by method:
 * - fixed_per_day: {amount_per_day}
 * - income_brackets_per_day: {input, brackets: [{up_to|null, amount_per_day}]}
 * - income_brackets_percentage: {input, brackets: [{up_to|null, percentage}]} on the gross fees
 * - any method: optional monthly_cap
 */
final class SubsidyCalculator
{
    public function calculate(SubsidyRule $rule, Child $child, int $attendedDays, string $grossFees): string
    {
        if (! $rule->isVerified()) {
            throw new BusinessRuleException("The subsidy rule {$rule->name} is not verified.");
        }

        $parameters = $rule->parameters;
        $amount = match ($rule->method) {
            'fixed_per_day' => Decimal::mul((string) $parameters['amount_per_day'], (string) $attendedDays),
            'income_brackets_per_day' => Decimal::mul((string) $this->bracket($parameters, $child)['amount_per_day'], (string) $attendedDays),
            'income_brackets_percentage' => Decimal::div(Decimal::mul($grossFees, (string) $this->bracket($parameters, $child)['percentage']), '100'),
            default => throw new BusinessRuleException("Unknown subsidy method {$rule->method}."),
        };

        if (isset($parameters['monthly_cap']) && Decimal::compare($amount, (string) $parameters['monthly_cap']) > 0) {
            $amount = (string) $parameters['monthly_cap'];
        }

        if (Decimal::compare($amount, $grossFees) > 0) {
            $amount = $grossFees;
        }

        return Decimal::round2($amount);
    }

    /**
     * Checks the shape of the parameters of a method.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function assertValidParameters(string $method, array $parameters): void
    {
        $isAmount = static fn (mixed $value): bool => is_numeric($value) && (float) $value >= 0;

        $valid = match ($method) {
            'fixed_per_day' => $isAmount($parameters['amount_per_day'] ?? null),
            'income_brackets_per_day', 'income_brackets_percentage' => is_string($parameters['input'] ?? null)
                && is_array($parameters['brackets'] ?? null) && $parameters['brackets'] !== []
                && collect($parameters['brackets'])->every(static fn (mixed $bracket): bool => is_array($bracket)
                    && (($bracket['up_to'] ?? null) === null || $isAmount($bracket['up_to']))
                    && $isAmount($bracket[$method === 'income_brackets_per_day' ? 'amount_per_day' : 'percentage'] ?? null)),
            default => false,
        };

        if (! $valid || (isset($parameters['monthly_cap']) && ! $isAmount($parameters['monthly_cap']))) {
            throw new BusinessRuleException("The parameters do not match the method {$method}.");
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function bracket(array $parameters, Child $child): array
    {
        $input = $child->subsidy_inputs[$parameters['input']] ?? null;

        if (! is_numeric($input)) {
            throw new BusinessRuleException("The child {$child->reference_code} has no value for {$parameters['input']}.");
        }

        $brackets = collect($parameters['brackets'])->sortBy(static fn (array $bracket): float => $bracket['up_to'] === null ? INF : (float) $bracket['up_to']);

        foreach ($brackets as $bracket) {
            if ($bracket['up_to'] === null || Decimal::compare((string) $input, (string) $bracket['up_to']) <= 0) {
                return $bracket;
            }
        }

        return ['amount_per_day' => '0', 'percentage' => '0'];
    }
}
