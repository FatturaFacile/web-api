<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services\Helpdesk;

use Modules\ServiceManagement\Models\RoutingRule;
use Modules\ServiceManagement\Models\ServiceTeam;
use Modules\ServiceManagement\Models\Ticket;

/**
 * Applies the routing rules of the tenant to a new ticket, then hands a ticket that has a team but no agent to the
 * team's automatic assignment. Runs inside the transaction that created the ticket.
 */
final readonly class TicketRoutingEngine
{
    public function __construct(
        private TicketAssignmentService $assignment,
        private TicketActivityLog $activityLog,
    ) {}

    /**
     * @return list<string> identifiers of the rules applied
     */
    public function route(Ticket $ticket, ?string $actorUserId = null): array
    {
        $applied = [];
        $rules = RoutingRule::query()->where('tenant_id', $ticket->tenant_id)->where('is_active', true)->orderBy('position')->orderBy('id')->get();

        foreach ($rules as $rule) {
            if (! $this->matches($rule, $ticket)) {
                continue;
            }

            $this->applyActions($rule, $ticket, $actorUserId);
            $applied[] = $rule->id;

            if ($rule->stop_processing) {
                break;
            }
        }

        if ($ticket->assigned_team_id !== null && $ticket->assigned_technician_id === null) {
            $team = ServiceTeam::query()->find($ticket->assigned_team_id);

            if ($team !== null && ($team->assignment_strategy ?? TicketAssignmentService::Manual) !== TicketAssignmentService::Manual) {
                $this->assignment->assign($ticket, $team->id, null, $actorUserId, null, 'auto');
            }
        }

        return $applied;
    }

    public function matches(RoutingRule $rule, Ticket $ticket): bool
    {
        $conditions = is_array($rule->conditions) ? $rule->conditions : [];

        $checks = [
            'category_ids' => fn (array $values): bool => in_array($ticket->category_id, $values, true),
            'sources' => fn (array $values): bool => in_array($ticket->source, $values, true),
            'customer_ids' => fn (array $values): bool => in_array($ticket->customer_id, $values, true),
            'priorities' => fn (array $values): bool => in_array($ticket->priority, $values, true),
            'keywords' => function (array $values) use ($ticket): bool {
                $text = mb_strtolower($ticket->subject.' '.$ticket->description);

                foreach ($values as $keyword) {
                    if (trim((string) $keyword) !== '' && str_contains($text, mb_strtolower(trim((string) $keyword)))) {
                        return true;
                    }
                }

                return false;
            },
        ];

        foreach ($checks as $key => $check) {
            $values = array_values(array_filter((array) ($conditions[$key] ?? []), static fn (mixed $value): bool => $value !== null && $value !== ''));

            if ($values !== [] && ! $check($values)) {
                return false;
            }
        }

        return true;
    }

    private function applyActions(RoutingRule $rule, Ticket $ticket, ?string $actorUserId): void
    {
        $actions = is_array($rule->actions) ? $rule->actions : [];

        if (isset($actions['set_priority']) && in_array($actions['set_priority'], ['low', 'medium', 'high', 'critical'], true)) {
            $ticket->priority = $actions['set_priority'];
        }

        if (! empty($actions['add_tags'])) {
            $tags = [...($ticket->tags ?? []), ...array_map(static fn (mixed $tag): string => mb_strtolower(trim((string) $tag)), (array) $actions['add_tags'])];
            $tags = array_values(array_unique(array_filter($tags)));
            sort($tags);
            $ticket->tags = $tags;
        }

        $ticket->save();

        $this->activityLog->record($ticket, 'routing_rule_applied', null, $rule->name, ['rule_id' => $rule->id, 'actions' => $actions], $actorUserId);

        if (! empty($actions['assign_technician_id']) || ! empty($actions['assign_team_id'])) {
            $this->assignment->assign(
                $ticket,
                $actions['assign_team_id'] ?? null,
                $actions['assign_technician_id'] ?? null,
                $actorUserId,
                $actions['assignment_strategy'] ?? null,
                'routing_rule'
            );
        }
    }
}
