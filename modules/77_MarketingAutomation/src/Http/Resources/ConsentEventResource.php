<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\MarketingAutomation\Models\ConsentEvent;

/**
 * @mixin ConsentEvent
 */
final class ConsentEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contact_id' => $this->contact_id,
            'event_type' => $this->event_type,
            'lawful_basis' => $this->lawful_basis,
            'strategy_code' => $this->strategy_code,
            'source' => $this->source,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
        ];
    }
}
