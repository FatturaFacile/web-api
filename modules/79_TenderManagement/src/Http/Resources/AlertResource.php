<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\TenderManagement\Models\TenderAlert;

/**
 * @mixin TenderAlert
 */
final class AlertResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'alert_type' => $this->alert_type,
            'milestone' => $this->milestone,
            'severity' => $this->severity->value,
            'message_key' => $this->message_key,
            'message_params' => $this->message_params ?? [],
            'due_at' => $this->due_at?->toIso8601String(),
            'raised_at' => $this->raised_at->toIso8601String(),
            'acknowledged_at' => $this->acknowledged_at?->toIso8601String(),
        ];
    }
}
