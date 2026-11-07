<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services\Helpdesk;

use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Models\TicketActivity;

/**
 * Writes the history of a ticket.
 */
final class TicketActivityLog
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(Ticket $ticket, string $type, ?string $from = null, ?string $to = null, array $payload = [], ?string $actorUserId = null): TicketActivity
    {
        return TicketActivity::query()->create([
            'tenant_id' => $ticket->tenant_id,
            'ticket_id' => $ticket->id,
            'actor_user_id' => $actorUserId,
            'type' => $type,
            'from_value' => $from === null ? null : mb_substr($from, 0, 120),
            'to_value' => $to === null ? null : mb_substr($to, 0, 120),
            'payload' => $payload === [] ? null : $payload,
        ]);
    }
}
