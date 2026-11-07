<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services\Helpdesk;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Modules\ServiceManagement\Enums\TicketStatus;
use Modules\ServiceManagement\Exceptions\HelpdeskRuleViolation;
use Modules\ServiceManagement\Models\Macro;
use Modules\ServiceManagement\Models\Ticket;

/**
 * Applies one action to many tickets. Each ticket is changed in a transaction of its own with its row locked, so one
 * refused ticket (wrong status, locked by a workflow) does not undo the others; the result lists every outcome.
 */
final readonly class HelpdeskBulkService
{
    public const array Actions = ['status', 'priority', 'assign', 'add_tags', 'remove_tags', 'macro'];

    public function __construct(
        private HelpdeskTicketService $tickets,
        private HelpdeskMacroService $macros,
    ) {}

    /**
     * @param  list<string>  $ticketIds
     * @param  array<string, mixed>  $payload
     * @return list<array{ticket_id: string, ok: bool, error: ?string}>
     */
    public function apply(array $ticketIds, string $action, array $payload, User $agent): array
    {
        $results = [];
        $macro = $action === 'macro' ? Macro::query()->visibleTo($agent->id)->findOrFail($payload['macro_id'] ?? null) : null;

        foreach (Ticket::query()->whereIn('id', $ticketIds)->orderBy('id')->get() as $ticket) {
            if (! Gate::forUser($agent)->allows('update', $ticket)) {
                $results[] = ['ticket_id' => $ticket->id, 'ok' => false, 'error' => 'Not allowed.'];

                continue;
            }

            try {
                match ($action) {
                    'status' => $this->tickets->changeStatus($ticket, TicketStatus::from((string) $payload['status']), $agent->id, 'Bulk action'),
                    'priority' => $this->tickets->update($ticket, ['priority' => $payload['priority']], $agent->id),
                    'assign' => $this->tickets->update($ticket, array_intersect_key($payload, array_flip(['assigned_team_id', 'assigned_technician_id'])), $agent->id),
                    'add_tags' => $this->tickets->update($ticket, ['tags' => [...($ticket->tags ?? []), ...(array) $payload['tags']]], $agent->id),
                    'remove_tags' => $this->tickets->update($ticket, ['tags' => array_values(array_diff($ticket->tags ?? [], $this->tickets->normalizeTags((array) $payload['tags'])))], $agent->id),
                    'macro' => $this->macros->apply($macro, $ticket, $agent),
                };

                $results[] = ['ticket_id' => $ticket->id, 'ok' => true, 'error' => null];
            } catch (HelpdeskRuleViolation $violation) {
                $results[] = ['ticket_id' => $ticket->id, 'ok' => false, 'error' => $violation->getMessage()];
            }
        }

        return $results;
    }
}
