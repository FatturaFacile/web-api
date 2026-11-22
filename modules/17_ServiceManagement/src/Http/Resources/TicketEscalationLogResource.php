<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ServiceManagement\Models\TicketEscalationLog;

/**
 * @mixin TicketEscalationLog
 */
final class TicketEscalationLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'chain_id' => $this->chain_id,
            'step_id' => $this->step_id,
            'target' => $this->target,
            'threshold_percent' => $this->threshold_percent,
            'elapsed_percent' => $this->elapsed_percent,
            'action' => $this->action,
            'outcome' => $this->outcome ?? (object) [],
            'fired_at' => $this->fired_at?->toIso8601String(),
        ];
    }
}
