<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ServiceManagement\Models\TicketActivity;

/**
 * @mixin TicketActivity
 */
final class HelpdeskTicketActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_id' => $this->ticket_id,
            'type' => $this->type,
            'from_value' => $this->from_value,
            'to_value' => $this->to_value,
            'payload' => $this->payload ?? (object) [],
            'actor_user_id' => $this->actor_user_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
