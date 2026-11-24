<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services\Helpdesk;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\ServiceManagement\Enums\MessageVisibility;
use Modules\ServiceManagement\Enums\TicketStatus;
use Modules\ServiceManagement\Models\Macro;
use Modules\ServiceManagement\Models\Ticket;

/**
 * Applies a macro to a ticket in one transaction: field changes, assignment, tags, an optional reply with placeholders
 * and the status. If any step is refused nothing is changed.
 */
final readonly class HelpdeskMacroService
{
    public function __construct(
        private HelpdeskTicketService $tickets,
        private HelpdeskThreadService $thread,
        private TicketPlaceholderRenderer $renderer,
        private TicketActivityLog $activityLog,
    ) {}

    public function apply(Macro $macro, Ticket $ticket, User $agent): Ticket
    {
        return DB::transaction(function () use ($macro, $ticket, $agent): Ticket {
            $ticket = $this->tickets->lock($ticket->id);
            $actions = is_array($macro->actions) ? $macro->actions : [];
            $changes = [];

            if (isset($actions['set_priority'])) {
                $changes['priority'] = $actions['set_priority'];
            }

            if (array_key_exists('assign_team_id', $actions)) {
                $changes['assigned_team_id'] = $actions['assign_team_id'];
            }

            if (array_key_exists('assign_technician_id', $actions)) {
                $changes['assigned_technician_id'] = $actions['assign_technician_id'];
            }

            if (! empty($actions['add_tags']) || ! empty($actions['remove_tags'])) {
                $remove = $this->tickets->normalizeTags((array) ($actions['remove_tags'] ?? []));
                $changes['tags'] = array_values(array_diff($this->tickets->normalizeTags([...($ticket->tags ?? []), ...(array) ($actions['add_tags'] ?? [])]), $remove));
            }

            if ($changes !== []) {
                $ticket = $this->tickets->update($ticket, $changes, $agent->id);
            }

            $status = isset($actions['set_status']) ? TicketStatus::from($actions['set_status']) : null;

            if (! empty($actions['reply']['body'])) {
                $this->thread->addStaffMessage(
                    $ticket,
                    $agent,
                    $this->renderer->render((string) $actions['reply']['body'], $ticket, $agent),
                    MessageVisibility::tryFrom((string) ($actions['reply']['visibility'] ?? 'public')) ?? MessageVisibility::Public,
                    [],
                    $status !== null && $status !== $ticket->statusEnum() ? $status : null,
                );
            } elseif ($status !== null && $status !== $ticket->statusEnum()) {
                $this->tickets->applyStatus($this->tickets->lock($ticket->id), $status, $agent->id, "Macro {$macro->name}");
            }

            $ticket = $ticket->refresh();
            $this->activityLog->record($ticket, 'macro_applied', null, $macro->name, ['macro_id' => $macro->id], $agent->id);

            return $ticket;
        });
    }
}
