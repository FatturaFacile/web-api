<?php

declare(strict_types=1);

namespace Modules\Quality\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Quality\Models\QmsCapaAction;

/**
 * A corrective or preventive action with its owner, due date and effectiveness check.
 *
 * @mixin QmsCapaAction
 */
final class CapaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nonconformity_id' => $this->nonconformity_id,
            'number' => $this->number,
            'type' => $this->type,
            'title' => $this->title,
            'description' => $this->description,
            'owner_id' => $this->owner_id,
            'due_date' => $this->due_date->toDateString(),
            'status' => $this->status,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'completed_by' => $this->completed_by,
            'effectiveness_check_due_date' => $this->effectiveness_check_due_date?->toDateString(),
            'effectiveness_checked_at' => $this->effectiveness_checked_at?->toIso8601String(),
            'effectiveness_checked_by' => $this->effectiveness_checked_by,
            'effectiveness_result' => $this->effectiveness_result,
            'effectiveness_notes' => $this->effectiveness_notes,
            'is_overdue' => $this->isOverdue(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
