<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services\Helpdesk;

use Illuminate\Database\Eloquent\Builder;
use Modules\ServiceManagement\Enums\TicketStatus;
use Modules\ServiceManagement\Models\Ticket;

/**
 * Filters of the ticket list and of saved views: status, priority, team, agent, tag, SLA state, customer, free text.
 *
 * SLA states: paused (waiting for the customer), breached (a target is due), warning (a warning was sent by the
 * scheduled SLA check and nothing is due yet), ok (active and none of the above).
 */
final class TicketListFilter
{
    /**
     * Keys a saved view may store.
     *
     * @var list<string>
     */
    public const array Keys = ['status', 'priority', 'team_id', 'agent_id', 'unassigned', 'tag', 'sla_state', 'customer_id', 'q', 'source', 'include_merged'];

    /**
     * @param  Builder<Ticket>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Ticket>
     */
    public function apply(Builder $query, array $filters): Builder
    {
        if (! filter_var($filters['include_merged'] ?? false, FILTER_VALIDATE_BOOL)) {
            $query->whereNull('merged_into_ticket_id');
        }

        foreach (['status' => 'status', 'priority' => 'priority', 'source' => 'source'] as $key => $column) {
            $values = $this->list($filters[$key] ?? null);

            if ($values !== []) {
                $query->whereIn($column, $values);
            }
        }

        if (! empty($filters['team_id'])) {
            $query->where('assigned_team_id', $filters['team_id']);
        }

        if (! empty($filters['agent_id'])) {
            $query->where('assigned_technician_id', $filters['agent_id']);
        }

        if (filter_var($filters['unassigned'] ?? false, FILTER_VALIDATE_BOOL)) {
            $query->whereNull('assigned_technician_id');
        }

        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        foreach ($this->list($filters['tag'] ?? null) as $tag) {
            $query->whereJsonContains('tags', mb_strtolower($tag));
        }

        if (! empty($filters['q'])) {
            $term = '%'.addcslashes((string) $filters['q'], '%_\\').'%';
            $query->where(fn (Builder $text) => $text->whereLike('subject', $term)->orWhereLike('ticket_number', $term)->orWhereLike('description', $term));
        }

        if (! empty($filters['sla_state'])) {
            $this->applySlaState($query, (string) $filters['sla_state']);
        }

        return $query;
    }

    /**
     * @param  Builder<Ticket>  $query
     */
    private function applySlaState(Builder $query, string $state): void
    {
        $now = now();
        $active = array_values(array_diff(TicketStatus::activeValues(), [TicketStatus::PendingCustomer->value]));

        $breached = fn (Builder $due) => $due
            ->where(fn (Builder $response) => $response->whereNull('first_responded_at')->whereNotNull('response_due_at')->where('response_due_at', '<=', $now))
            ->orWhere(fn (Builder $resolution) => $resolution->whereNotNull('resolution_due_at')->where('resolution_due_at', '<=', $now));

        match ($state) {
            TicketSlaClock::StatePaused => $query->where(fn (Builder $paused) => $paused->where('status', TicketStatus::PendingCustomer->value)->orWhereNotNull('sla_paused_at')),
            TicketSlaClock::StateBreached => $query->whereIn('status', $active)->whereNull('sla_paused_at')->where($breached),
            TicketSlaClock::StateWarning => $query->whereIn('status', $active)->whereNull('sla_paused_at')->whereNot($breached)
                ->where(fn (Builder $warned) => $warned->whereNotNull('response_warning_sent_at')->orWhereNotNull('resolution_warning_sent_at')),
            TicketSlaClock::StateOk => $query->whereIn('status', $active)->whereNull('sla_paused_at')->whereNot($breached)
                ->whereNull('response_warning_sent_at')->whereNull('resolution_warning_sent_at'),
            default => null,
        };
    }

    /**
     * @return list<string>
     */
    private function list(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        return is_array($value) ? array_values(array_filter(array_map(static fn (mixed $item): string => trim((string) $item), $value), static fn (string $item): bool => $item !== '')) : [];
    }
}
