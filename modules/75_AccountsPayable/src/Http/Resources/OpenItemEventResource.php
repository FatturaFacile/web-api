<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AccountsPayable\Models\ApOpenItemEvent;

/**
 * One entry of the audit history of an open item.
 *
 * @mixin ApOpenItemEvent
 */
final class OpenItemEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'reason' => $this->reason,
            'actor_id' => $this->actor_id,
            'context' => $this->context,
            'occurred_at' => $this->occurred_at->toIso8601String(),
        ];
    }
}
