<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TimeEntryCorrection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TimeEntryCorrection */
class TimeEntryCorrectionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'time_entry_id' => $this->time_entry_id,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'original_values' => $this->original_values,
            'proposed_values' => $this->proposed_values,
            'requested_by' => $this->requested_by,
            'reviewed_by' => $this->reviewed_by,
            'review_reason' => $this->review_reason,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
