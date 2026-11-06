<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HumanResources\Services\TimeTracking\SwissWorkingTimeRules;

final class TimeClockEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->user_id,
            'occurred_at' => SwissWorkingTimeRules::toLocal($this->occurred_at)->toIso8601String(),
            'event_type' => $this->event_type->value,
            'source' => $this->source->value,
            'note' => $this->note,
            'created_by' => $this->created_by,
            'deleted_at' => $this->deleted_at?->toIso8601String(),
            'deleted_by' => $this->deleted_by,
            'deletion_reason' => $this->deletion_reason,
        ];
    }
}
