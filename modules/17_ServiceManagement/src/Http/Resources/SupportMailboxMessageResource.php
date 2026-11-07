<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ServiceManagement\Models\SupportMailboxMessage;

/**
 * @mixin SupportMailboxMessage
 */
final class SupportMailboxMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mailbox_id' => $this->mailbox_id,
            'uid' => (int) $this->uid,
            'message_id' => $this->message_id,
            'from_email' => $this->from_email,
            'subject' => $this->subject,
            'outcome' => $this->outcome,
            'ticket_id' => $this->ticket_id,
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
