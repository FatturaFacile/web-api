<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CRM\Models\Activity;

/**
 * @mixin Activity
 */
final class ActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'type' => $this->type,
            'status' => $this->status,
            'priority' => $this->priority,
            'due_date' => $this->due_date?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'assigned_user_id' => $this->assigned_user_id,
            'related_type' => $this->related_type,
            'related_id' => $this->related_id,
            'duration_minutes' => $this->duration_minutes,
            'location' => $this->location,
            'outcome' => $this->outcome,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
