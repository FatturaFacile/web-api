<?php

declare(strict_types=1);

namespace Modules\WithholdingRecipients\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\WithholdingRecipients\Models\OccasionalInlNotification;

/**
 * @mixin OccasionalInlNotification
 */
final class InlNotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'recipient_profile_id' => $this->recipient_profile_id,
            'status' => $this->status,
            'work_start_date' => $this->work_start_date->toDateString(),
            'work_end_date' => $this->work_end_date->toDateString(),
            'activity_description' => $this->activity_description,
            'work_place' => $this->work_place,
            'estimated_compensation' => $this->estimated_compensation,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'sent_before_start' => $this->sent_at !== null && $this->sent_at->startOfDay()->lessThanOrEqualTo($this->work_start_date->startOfDay()),
            'protocol_reference' => $this->protocol_reference,
            'payload' => $this->payload,
        ];
    }
}
