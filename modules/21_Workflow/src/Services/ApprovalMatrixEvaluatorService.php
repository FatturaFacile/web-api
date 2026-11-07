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

        $documentAmount = (string) ($entity->getAttribute('total_amount') ?? $entity->getAttribute('amount') ?? $context['amount'] ?? '0');

        $matchingTiers = [];
        foreach ($matrix->tiers as $tier) {
            if ($tier->threshold_min !== null && bccomp($documentAmount, (string) $tier->threshold_min, 4) < 0) {
                continue;
            }
            if ($tier->threshold_max !== null && bccomp($documentAmount, (string) $tier->threshold_max, 4) > 0) {
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
     * @return array<int, array{tier_level: int, approver_type: string, approver_value: string, approval_type: string}>|null
     */
    public function tiersFor(string $tenantId, string $entityType, Model $entity, array $context): ?array
    {
        $matrices = ApprovalMatrix::where('tenant_id', $tenantId)
            ->where('target_entity_type', $entityType)
            ->where('is_active', true)
            ->with(['rules', 'tiers'])
            ->orderBy('code')
            ->get();

        if ($matrices->isEmpty()) {
            return null;
        }

        $costCentreFirst = $matrices->sortByDesc(static fn (ApprovalMatrix $matrix): bool => $matrix->rules->whereNotNull('cost_center_id')->isNotEmpty());

        foreach ($costCentreFirst as $matrix) {
            $tiers = $this->evaluate($matrix, $entity, $context);

            if ($tiers !== []) {
                return $tiers;
            }
        }

        return [];
    }

    /**
     * The attribute of the record when it has a value; the snapshot only fills in fields the record lacks or leaves empty
     * (a cost centre chosen at submission), so a snapshot sent
     * by a client cannot lower the amount or change a cost centre the record already has.
     *
     * @param  array<string, mixed>  $context
     */
    private function fieldValue(Model $entity, array $context, string $field): mixed
    {
        return $entity->getAttribute($field) ?? $context[$field] ?? null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function evaluateRule(ApprovalMatrixRule $rule, Model $entity, array $context): bool
    {
        $value = $this->fieldValue($entity, $context, (string) $rule->field_name);

        if ($rule->cost_center_id !== null) {
            $costCenterId = (string) $this->fieldValue($entity, $context, 'cost_center_id');
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
