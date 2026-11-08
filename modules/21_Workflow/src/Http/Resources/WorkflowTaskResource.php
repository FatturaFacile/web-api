<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Workflow\Models\WorkflowInstanceStep;

/**
 * A pending approval in the inbox of the caller, with the record under approval, its deadline state and, when it reached
 * the caller through an out-of-office delegation, who delegated it.
 *
 * @mixin WorkflowInstanceStep
 */
final class WorkflowTaskResource extends JsonResource
{
    /**
     * Attributes tried in order to give the record under approval a readable label.
     *
     * @var list<string>
     */
    private const array LabelAttributes = ['po_number', 'requisition_number', 'invoice_number', 'quote_number', 'project_number', 'number', 'title', 'name', 'description'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $userId = (string) $request->user()?->id;
        $instance = $this->instance;
        $approvable = $instance?->approvable;
        $isDelegated = $this->assigned_user_id !== null && (string) $this->assigned_user_id !== $userId;
        $dueAt = $this->due_at;

        return [
            'step_id' => $this->id,
            'workflow_instance_id' => $this->workflow_instance_id,
            'workflow_code' => $instance?->definition?->code,
            'workflow_name' => $instance?->definition?->name,
            'node_name' => $this->node?->name,
            'approvable_type' => $instance?->approvable_type,
            'approvable_id' => $instance?->approvable_id,
            'approvable_label' => $this->labelOf($approvable),
            'initiated_by' => $instance?->created_by,
            'assigned_user_id' => $this->assigned_user_id,
            'assigned_role' => $this->assigned_role,
            'via_delegation_from' => $isDelegated ? $this->assigned_user_id : null,
            'tier_level' => $this->tier_level,
            'due_at' => $dueAt?->toIso8601String(),
            'is_overdue' => $dueAt !== null && $dueAt->isPast(),
            'hours_remaining' => $dueAt === null ? null : (string) round(now()->diffInMinutes($dueAt, false) / 60, 1),
            'escalation_level' => (int) $this->escalation_level,
            'escalated_at' => $this->escalated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function labelOf(mixed $approvable): ?string
    {
        foreach (self::LabelAttributes as $attribute) {
            $value = $approvable?->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return mb_substr($value, 0, 120);
            }
        }

        return null;
    }
}
