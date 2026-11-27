<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\SwissEbill\Models\EbillTransmissionEvent;

/**
 * @mixin EbillTransmissionEvent
 */
final class EbillTransmissionEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->event_type,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'source' => $this->source,
            'gateway_event_id' => $this->gateway_event_id,
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'details' => $this->details,
        ];
    }
}
