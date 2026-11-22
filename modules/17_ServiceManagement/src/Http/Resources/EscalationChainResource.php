<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ServiceManagement\Models\EscalationChain;
use Modules\ServiceManagement\Models\EscalationStep;

/**
 * @mixin EscalationChain
 */
final class EscalationChainResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'is_active' => (bool) $this->is_active,
            'steps' => $this->steps->map(static fn (EscalationStep $step): array => [
                'id' => $step->id,
                'target' => $step->target,
                'threshold_percent' => $step->threshold_percent,
                'action' => $step->action,
                'notify_user_ids' => $step->notify_user_ids ?? [],
                'notify_team_lead' => (bool) $step->notify_team_lead,
                'team_id' => $step->team_id,
                'priority' => $step->priority,
            ])->values()->all(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
