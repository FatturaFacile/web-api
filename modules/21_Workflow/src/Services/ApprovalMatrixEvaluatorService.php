<?php

declare(strict_types=1);

namespace Modules\Workflow\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\Workflow\Contracts\ApprovalMatrixEvaluatorInterface;
use Modules\Workflow\Models\ApprovalMatrix;
use Modules\Workflow\Models\ApprovalMatrixRule;

final class ApprovalMatrixEvaluatorService implements ApprovalMatrixEvaluatorInterface
{
    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array{tier_level: int, approver_type: string, approver_value: string, approval_type: string}>
     */
    public function evaluate(ApprovalMatrix $matrix, Model $entity, array $context): array
    {
        $rules = $matrix->rules;

        foreach ($rules as $rule) {
            if (! $this->evaluateRule($rule, $entity, $context)) {
                // Rule failed, this matrix is not matching
                return [];
            }
        }

        $documentAmount = (float) ($context['amount'] ?? $entity->getAttribute('total_amount') ?? $entity->getAttribute('amount') ?? 0.0);

        $matchingTiers = [];
        foreach ($matrix->tiers as $tier) {
            $min = $tier->threshold_min !== null ? (float) $tier->threshold_min : null;
            $max = $tier->threshold_max !== null ? (float) $tier->threshold_max : null;

            if ($min !== null && $documentAmount < $min) {
                continue;
            }
            if ($max !== null && $documentAmount > $max) {
                continue;
            }

            $matchingTiers[] = [
                'tier_level' => $tier->tier_level,
                'approver_type' => $tier->approver_type,
                'approver_value' => $tier->approver_value,
                'approval_type' => $tier->approval_type,
            ];
        }

        return $matchingTiers;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function evaluateRule(ApprovalMatrixRule $rule, Model $entity, array $context): bool
    {
        $value = $context[$rule->field_name] ?? $entity->getAttribute($rule->field_name);

        if ($rule->cost_center_id !== null) {
            $costCenterId = (string) ($context['cost_center_id'] ?? $entity->getAttribute('cost_center_id'));
            if ($costCenterId !== (string) $rule->cost_center_id) {
                return false;
            }
        }

        return match ($rule->operator) {
            '=' => (string) $value === (string) ($rule->value_string ?? $rule->value_numeric),
            '!=' => (string) $value !== (string) ($rule->value_string ?? $rule->value_numeric),
            '>' => (float) $value > (float) $rule->value_numeric,
            '>=' => (float) $value >= (float) $rule->value_numeric,
            '<' => (float) $value < (float) $rule->value_numeric,
            '<=' => (float) $value <= (float) $rule->value_numeric,
            'in' => is_array($rule->value_json) && in_array($value, $rule->value_json, true),
            default => true,
        };
    }
}
