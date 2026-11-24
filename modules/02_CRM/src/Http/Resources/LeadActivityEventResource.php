<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CRM\Models\LeadActivityEvent;

/**
 * @mixin LeadActivityEvent
 */
final class LeadActivityEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lead_id' => $this->lead_id,
            'type' => $this->type,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'metadata' => $this->metadata,
            'external_id' => $this->external_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
