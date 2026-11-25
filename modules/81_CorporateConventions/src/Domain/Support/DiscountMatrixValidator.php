<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Domain\Support;

use Modules\CorporateConventions\Domain\Enums\RuleType;
use Modules\CorporateConventions\Exceptions\ConventionException;

/**
 * Consistency of a discount matrix before it is stored: every rule has the target its type needs, percentages are within 0-100 and
 * two rules never share a priority (the order would be ambiguous).
 */
final class DiscountMatrixValidator
{
    /**
     * @param  list<array<string, mixed>>  $rules
     */
    public static function assertValid(array $rules): void
    {
        $priorities = [];

        foreach ($rules as $index => $rule) {
            $type = RuleType::tryFrom((string) ($rule['rule_type'] ?? ''));

            if ($type === null) {
                throw new ConventionException('discount_rule_type_invalid', 422, ['row' => $index + 1]);
            }

            $percentage = (string) ($rule['discount_percentage'] ?? '0');

            if (Dec::cmp($percentage, '0') < 0 || Dec::cmp($percentage, '100') > 0) {
                throw new ConventionException('discount_percentage_out_of_range', 422, ['row' => $index + 1]);
            }

            if ($type === RuleType::PriceList && empty($rule['target_price_list_id'])) {
                throw new ConventionException('discount_rule_target_missing', 422, ['row' => $index + 1, 'target' => 'price_list']);
            }

            if (in_array($type, [RuleType::CategoryPercentage, RuleType::ProductFamilyPercentage], true) && empty($rule['target_category_id'])) {
                throw new ConventionException('discount_rule_target_missing', 422, ['row' => $index + 1, 'target' => 'category']);
            }

            if ($type !== RuleType::PriceList && Dec::cmp($percentage, '0') === 0) {
                throw new ConventionException('discount_percentage_required', 422, ['row' => $index + 1]);
            }

            $priority = (int) ($rule['priority'] ?? 100);

            if (isset($priorities[$priority])) {
                throw new ConventionException('discount_priority_duplicated', 422, ['priority' => $priority]);
            }

            $priorities[$priority] = true;
        }
    }
}
