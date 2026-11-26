<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Workflow\Models\ApprovalMatrix;
use Modules\Workflow\Models\ApprovalMatrixRule;
use Modules\Workflow\Models\ApprovalMatrixTier;
use Modules\Workflow\Services\ApprovableRegistry;

/**
 * @mixin ApprovalMatrix
 */
final class ApprovalMatrixResource extends JsonResource
{
    private const array OperatorNames = ['=' => 'eq', '!=' => 'neq', '>' => 'gt', '>=' => 'gte', '<' => 'lt', '<=' => 'lte'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $registry = app(ApprovableRegistry::class);

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'subject_type' => $registry->resolve((string) $this->target_entity_type) ?? $this->target_entity_type,
            'currency' => $this->currency,
            'priority' => (int) $this->priority,
            'is_active' => (bool) $this->is_active,
            'is_default' => (bool) $this->is_default,
            'auto_approve_below' => $this->auto_approve_below,
            'fallback_approver' => $this->fallback_approver_type === null ? null : [
                'approver_type' => $this->fallback_approver_type,
                'approver_value' => $this->fallback_approver_value,
            ],
            'conditions' => $this->whenLoaded('rules', fn (): array => $this->rules->map(static fn (ApprovalMatrixRule $rule): array => [
                'id' => $rule->id,
                'field' => $rule->field_name,
                'operator' => self::OperatorNames[$rule->operator] ?? $rule->operator,
                'value' => is_array($rule->value_json) ? $rule->value_json : ($rule->value_numeric !== null && in_array($rule->operator, ['>', '>=', '<', '<='], true) ? $rule->value_numeric : ($rule->value_string ?? $rule->value_numeric)),
            ])->values()->all()),
            'tiers' => $this->whenLoaded('tiers', fn (): array => $this->tiers->map(static fn (ApprovalMatrixTier $tier): array => [
                'id' => $tier->id,
                'tier_level' => (int) $tier->tier_level,
                'approver_type' => $tier->approver_type,
                'approver_value' => $tier->approver_value,
                'approval_type' => $tier->approval_type,
                'threshold_min' => $tier->threshold_min,
                'threshold_max' => $tier->threshold_max,
                'threshold_min_inclusive' => (bool) $tier->threshold_min_inclusive,
            ])->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
