<?php

declare(strict_types=1);

namespace Modules\Intercompany\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Intercompany\Models\SyncEvent;

/**
 * @mixin SyncEvent
 */
final class SyncEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'transaction_id' => $this->transaction_id,
            'event' => $this->event,
            'message_key' => $this->message_key,
            'context' => $this->context ?? [],
            'amount' => $this->amount === null ? null : (string) $this->amount,
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'user_id' => $this->user_id,
        ];
    }
}
