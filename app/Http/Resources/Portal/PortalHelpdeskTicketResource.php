<?php

declare(strict_types=1);

namespace App\Http\Resources\Portal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ServiceManagement\Models\Ticket as HelpdeskTicket;
use Modules\ServiceManagement\Models\TicketMessage;

/**
 * A helpdesk ticket (esm_tickets) in the shape of PortalTicketResource: only public messages, the message text in
 * "message". The files of the conversation stay with the helpdesk, so "attachments" is empty.
 *
 * @mixin HelpdeskTicket
 */
class PortalHelpdeskTicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_number' => $this->ticket_number,
            'subject' => $this->subject,
            'description' => $this->description,
            'priority' => $this->priority,
            'status' => $this->status,
            'created_at' => $this->created_at?->toISOString(),
            'messages' => $this->whenLoaded('messages', fn (): array => $this->messages->map(fn (TicketMessage $message): array => [
                'id' => $message->id,
                'author_type' => $message->author_type,
                'author_name' => $message->author_name,
                'message' => $message->body,
                'created_at' => $message->created_at?->toISOString(),
            ])->values()->all()),
            'attachments' => [],
        ];
    }
}
