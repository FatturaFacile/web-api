<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services\Helpdesk;

use App\Models\User;
use Modules\ServiceManagement\Models\Ticket;

/**
 * Fills the placeholders of a canned response or macro reply. Unknown placeholders stay as written, so a typo is visible
 * to the agent instead of silently disappearing.
 */
final class TicketPlaceholderRenderer
{
    /**
     * @var list<string>
     */
    public const array Placeholders = [
        'ticket.number', 'ticket.subject', 'ticket.status', 'ticket.priority', 'ticket.created_at',
        'requester.name', 'requester.email', 'agent.name', 'agent.email', 'company.name',
    ];

    public function render(string $text, Ticket $ticket, ?User $agent = null): string
    {
        $values = [
            'ticket.number' => (string) $ticket->ticket_number,
            'ticket.subject' => (string) $ticket->subject,
            'ticket.status' => (string) $ticket->status,
            'ticket.priority' => (string) $ticket->priority,
            'ticket.created_at' => (string) $ticket->created_at?->format('Y-m-d'),
            'requester.name' => $ticket->requesterName(),
            'requester.email' => (string) $ticket->requesterEmail(),
            'agent.name' => (string) $agent?->name,
            'agent.email' => (string) $agent?->email,
            'company.name' => (string) ($ticket->tenant?->name ?? ''),
        ];

        return (string) preg_replace_callback('/\{\{\s*([a-z_]+\.[a-z_]+)\s*\}\}/', static fn (array $match): string => $values[$match[1]] ?? $match[0], $text);
    }
}
