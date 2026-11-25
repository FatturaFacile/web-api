<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DebtCollection\Models\Event;

/**
 * @mixin Event
 */
final class EventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'level' => $this->level,
            'amount' => $this->amount === null ? null : (string) $this->amount,
            'currency' => $this->currency,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'payload' => $this->payload ?? [],
            'actor_id' => $this->actor_id,
        ];
    }
}
