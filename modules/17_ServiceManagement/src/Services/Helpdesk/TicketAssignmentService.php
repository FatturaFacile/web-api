<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services\Helpdesk;

use Enterprise\EventBus\Services\EventDispatcher;
use Modules\ServiceManagement\Enums\TicketStatus;
use Modules\ServiceManagement\Events\HelpdeskTicketChanged;
use Modules\ServiceManagement\Events\TicketAssignedEvent;
use Modules\ServiceManagement\Exceptions\HelpdeskRuleViolation;
use Modules\ServiceManagement\Models\ServiceTeam;
use Modules\ServiceManagement\Models\ServiceTechnician;
use Modules\ServiceManagement\Models\Ticket;

/**
 * Hands a ticket to a team and an agent. Automatic choice inside a team is round robin or least loaded: the team row is
 * locked while choosing, so concurrent assignments in one team see each other's result.
 *
 * Every method expects to run inside the caller's transaction, on a ticket row the caller has locked.
 */
final readonly class TicketAssignmentService
{
    public const string Manual = 'manual';

    public const string RoundRobin = 'round_robin';

    public const string LeastLoaded = 'least_loaded';

    public function __construct(
        private TicketActivityLog $activityLog,
        private EventDispatcher $dispatcher,
    ) {}

    /**
     * @return list<string>
     */
    public static function strategies(): array
    {
        return [self::Manual, self::RoundRobin, self::LeastLoaded];
    }

    /**
     * Assigns a locked ticket. With a team and no agent, the agent is chosen with the given strategy, or the team's.
     */
    public function assign(Ticket $ticket, ?string $teamId, ?string $technicianId, ?string $actorUserId = null, ?string $strategy = null, string $reason = 'manual'): Ticket
    {
        if ($technicianId !== null) {
            $technician = ServiceTechnician::query()->where('tenant_id', $ticket->tenant_id)->find($technicianId)
                ?? throw new HelpdeskRuleViolation('The agent does not belong to this company.');
            $teamId ??= $technician->team_id;
        } elseif ($teamId !== null) {
            $team = ServiceTeam::query()->where('tenant_id', $ticket->tenant_id)->whereKey($teamId)->lockForUpdate()->first()
                ?? throw new HelpdeskRuleViolation('The team does not belong to this company.');
            $technicianId = $this->pickTechnician($team, $strategy ?? $team->assignment_strategy ?? self::Manual)?->id;
        }

        $before = ['team' => $ticket->assigned_team_id, 'agent' => $ticket->assigned_technician_id];

        if ($before['team'] === $teamId && $before['agent'] === $technicianId) {
            return $ticket;
        }

        $ticket->assigned_team_id = $teamId;
        $ticket->assigned_technician_id = $technicianId;

        if ($technicianId !== null && $ticket->statusEnum() === TicketStatus::New) {
            $ticket->status = TicketStatus::Open->value;
            $ticket->status_changed_at = now();
        }

        $ticket->save();

        $this->activityLog->record($ticket, 'assigned', $before['agent'] ?? $before['team'], $technicianId ?? $teamId, [
            'team_id' => $teamId,
            'technician_id' => $technicianId,
            'previous_team_id' => $before['team'],
            'previous_technician_id' => $before['agent'],
            'reason' => $reason,
        ], $actorUserId);

        if ($technicianId !== null) {
            $this->dispatcher->dispatch(new TicketAssignedEvent(
                ticketId: $ticket->id,
                ticketNumber: $ticket->ticket_number,
                assignedToId: $technicianId,
                serviceTeamId: $teamId,
                tenantId: $ticket->tenant_id,
                companyId: $ticket->company_id ?? $ticket->tenant_id
            ));
        }

        event(new HelpdeskTicketChanged($ticket->id, $ticket->tenant_id, HelpdeskTicketChanged::Assigned, $actorUserId, [
            'team_id' => $teamId,
            'technician_id' => $technicianId,
        ]));

        return $ticket;
    }

    /**
     * Chooses an available agent of a locked team, or none when the team assigns by hand or has nobody available.
     */
    public function pickTechnician(ServiceTeam $team, string $strategy): ?ServiceTechnician
    {
        if ($strategy === self::Manual) {
            return null;
        }

        $candidates = ServiceTechnician::query()
            ->where('tenant_id', $team->tenant_id)
            ->where('team_id', $team->id)
            ->where('is_available', true)
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        if ($strategy === self::LeastLoaded) {
            $load = Ticket::query()
                ->where('tenant_id', $team->tenant_id)
                ->whereIn('assigned_technician_id', $candidates->pluck('id'))
                ->whereIn('status', TicketStatus::activeValues())
                ->whereNull('merged_into_ticket_id')
                ->selectRaw('assigned_technician_id, count(*) as open_tickets')
                ->groupBy('assigned_technician_id')
                ->pluck('open_tickets', 'assigned_technician_id');

            return $candidates->sortBy(fn (ServiceTechnician $technician): string => sprintf('%010d-%s', (int) ($load[$technician->id] ?? 0), $technician->id))->first();
        }

        $cursor = $team->round_robin_cursor;
        $next = $cursor === null ? null : $candidates->first(fn (ServiceTechnician $technician): bool => strcmp($technician->id, $cursor) > 0);
        $chosen = $next ?? $candidates->first();

        $team->round_robin_cursor = $chosen->id;
        $team->save();

        return $chosen;
    }
}
