<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HumanResources\Services\TimeTracking\SwissWorkingTimeRules;

final class TimeAuditEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->user_id,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'action' => $this->action,
            'before' => $this->before_values,
            'after' => $this->after_values,
            'reason' => $this->reason,
            'actor_id' => $this->actor_id,
            'actor_name' => $this->actor_name,
            'occurred_at' => SwissWorkingTimeRules::toLocal($this->occurred_at)->toIso8601String(),
        ];
    }
}
