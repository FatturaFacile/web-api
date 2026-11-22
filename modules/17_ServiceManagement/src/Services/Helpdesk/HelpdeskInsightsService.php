<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services\Helpdesk;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\ServiceManagement\Enums\TicketStatus;
use Modules\ServiceManagement\Models\SerializedEquipmentPassport;
use Modules\ServiceManagement\Models\ServiceContract;
use Modules\ServiceManagement\Models\SLAPolicy;
use Modules\ServiceManagement\Models\Ticket;

/**
 * Read models of the agent workbench: the queue of tickets at risk of breaching an SLA target, CSAT and mean times by
 * queue, priority or contract, and the 360 degree context of the customer of a ticket.
 */
final readonly class HelpdeskInsightsService
{
    public const array GroupBy = ['queue', 'priority', 'contract'];

    /**
     * Active tickets whose first response, next update or resolution target has used at least the given share of its
     * time, or has passed. A ticket waiting for the customer or on a stopped clock is not at risk. The most urgent first.
     *
     * @return list<array<string, mixed>>
     */
    public function breachRiskQueue(string $tenantId, int $riskPercent = 75, int $limit = 100, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        $tickets = Ticket::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', TicketStatus::activeValues())
            ->where('status', '!=', TicketStatus::PendingCustomer->value)
            ->whereNull('sla_paused_at')
            ->whereNull('merged_into_ticket_id')
            ->get();

        /** @var array<string, int> $intervals */
        $intervals = SLAPolicy::query()->where('tenant_id', $tenantId)->whereNotNull('next_update_interval_minutes')->pluck('next_update_interval_minutes', 'id')->map(static fn (mixed $minutes): int => (int) $minutes)->all();
        $rows = [];

        foreach ($tickets as $ticket) {
            $targets = [];

            foreach ($this->targetsOf($ticket, $intervals) as $target => [$start, $due]) {
                $percent = $this->elapsedPercent($start, $due, $now);

                if ($percent >= $riskPercent) {
                    $targets[] = [
                        'target' => $target,
                        'due_at' => $due->toIso8601String(),
                        'elapsed_percent' => $percent,
                        'state' => $percent >= 100 ? 'breached' : 'at_risk',
                    ];
                }
            }

            if ($targets !== []) {
                $rows[] = [
                    'ticket_id' => $ticket->id,
                    'ticket_number' => $ticket->ticket_number,
                    'subject' => $ticket->subject,
                    'priority' => $ticket->priority,
                    'status' => $ticket->status,
                    'customer_id' => $ticket->customer_id,
                    'assigned_team_id' => $ticket->assigned_team_id,
                    'assigned_technician_id' => $ticket->assigned_technician_id,
                    'risk_percent' => max(array_column($targets, 'elapsed_percent')),
                    'targets' => $targets,
                ];
            }
        }

        usort($rows, static fn (array $left, array $right): int => $right['risk_percent'] <=> $left['risk_percent'] ?: strcmp((string) $left['ticket_number'], (string) $right['ticket_number']));

        return array_slice($rows, 0, max(1, $limit));
    }

    /**
     * CSAT (average and 1-5 distribution), mean time to first response and to resolution (minutes) and SLA compliance, for
     * the tickets created in the period, in total and per group.
     *
     * @return array{period: array{from: string, to: string}, group_by: string, totals: array<string, mixed>, groups: list<array<string, mixed>>}
     */
    public function csatAndMttr(string $tenantId, CarbonImmutable $from, CarbonImmutable $to, string $groupBy = 'queue'): array
    {
        $tickets = Ticket::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$from, $to])
            ->whereNull('merged_into_ticket_id')
            ->get();

        $groups = $tickets->groupBy(fn (Ticket $ticket): string => $this->groupKey($ticket, $groupBy))
            ->map(fn (Collection $members, string $key): array => ['key' => $key === '' ? null : $key, ...$this->summarize($members)])
            ->sortBy(fn (array $group): string => (string) $group['key'])
            ->values()
            ->all();

        return [
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'group_by' => $groupBy,
            'totals' => $this->summarize($tickets),
            'groups' => $groups,
        ];
    }

    /**
     * Everything an agent wants to know about the customer while answering: prepaid hours left on the active contracts,
     * installed units, open invoices and the latest tickets.
     *
     * @return array<string, mixed>
     */
    public function customerContext(Ticket $ticket): array
    {
        $tenantId = (string) $ticket->tenant_id;
        $customerId = (string) $ticket->customer_id;
        $today = now()->toDateString();

        $contracts = ServiceContract::query()
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->where('status', 'active')
            ->whereDate('start_date', '<=', $today)
            ->where(fn ($window) => $window->whereNull('end_date')->orWhereDate('end_date', '>=', $today))
            ->orderBy('start_date')
            ->get()
            ->map(static fn (ServiceContract $contract): array => [
                'id' => $contract->id,
                'name' => $contract->getAttribute('name') ?? $contract->getAttribute('contract_number'),
                'kind' => bccomp((string) $contract->included_hours, '0', 2) > 0 ? 'prepaid_hours' : 'flat_rate',
                'included_hours' => (string) $contract->included_hours,
                'consumed_hours' => (string) $contract->consumed_hours,
                'remaining_hours' => $contract->remainingHours(),
                'end_date' => $contract->end_date?->toDateString(),
            ])->values()->all();

        $assets = SerializedEquipmentPassport::query()
            ->where('tenant_id', $tenantId)
            ->where('current_owner_customer_id', $customerId)
            ->orderBy('serial_number')
            ->limit(50)
            ->get()
            ->map(static fn (SerializedEquipmentPassport $passport): array => [
                'id' => $passport->id,
                'serial_number' => $passport->serial_number,
                'warranty_expiry_date' => $passport->warranty_expiry_date->toDateString(),
            ])->all();

        $openInvoices = SalesInvoice::query()
            ->where('tenant_id', $tenantId)
            ->where('contact_id', $customerId)
            ->where('payment_status', '!=', 'paid')
            ->whereNotIn('invoice_type', ['credit_note', 'proforma'])
            ->get(['id', 'total_gross', 'due_date']);

        $recent = Ticket::query()
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->whereKeyNot($ticket->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'ticket_number', 'subject', 'status', 'priority', 'created_at'])
            ->map(static fn (Ticket $other): array => [
                'id' => $other->id,
                'ticket_number' => $other->ticket_number,
                'subject' => $other->subject,
                'status' => $other->status,
                'priority' => $other->priority,
                'created_at' => $other->created_at?->toIso8601String(),
            ])->all();

        return [
            'customer_id' => $customerId,
            'contracts' => $contracts,
            'assets' => $assets,
            'open_invoices' => [
                'count' => $openInvoices->count(),
                'overdue_count' => $openInvoices->filter(static fn (SalesInvoice $invoice): bool => $invoice->due_date !== null && $invoice->due_date->toDateString() < $today)->count(),
                'total_gross' => number_format((float) $openInvoices->sum('total_gross'), 2, '.', ''),
            ],
            'recent_tickets' => $recent,
        ];
    }

    /**
     * Start and due of each target still running on the ticket.
     *
     * @param  array<string, int>  $intervals  next-update interval in minutes by SLA policy id
     * @return array<string, array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function targetsOf(Ticket $ticket, array $intervals): array
    {
        $created = CarbonImmutable::instance($ticket->created_at ?? now());
        $targets = [];

        if ($ticket->first_responded_at === null && $ticket->response_due_at !== null) {
            $targets['response'] = [$created, CarbonImmutable::instance($ticket->response_due_at)];
        }

        if ($ticket->next_update_due_at !== null) {
            $due = CarbonImmutable::instance($ticket->next_update_due_at);
            $interval = $intervals[is_array($ticket->metadata) ? (string) ($ticket->metadata['sla_policy_id'] ?? '') : ''] ?? null;
            // The target started one policy interval before it is due (at the last public reply of an agent).
            $targets['next_update'] = [$interval === null ? $created : $due->subMinutes($interval), $due];
        }

        if ($ticket->resolution_due_at !== null) {
            $targets['resolution'] = [$created, CarbonImmutable::instance($ticket->resolution_due_at)];
        }

        return $targets;
    }

    private function elapsedPercent(CarbonImmutable $start, CarbonImmutable $due, CarbonImmutable $now): int
    {
        $span = $due->getTimestamp() - $start->getTimestamp();

        if ($span <= 0) {
            return $now->greaterThanOrEqualTo($due) ? 100 : 0;
        }

        return max(0, (int) floor(($now->getTimestamp() - $start->getTimestamp()) * 100 / $span));
    }

    private function groupKey(Ticket $ticket, string $groupBy): string
    {
        return match ($groupBy) {
            'priority' => (string) $ticket->priority,
            'contract' => (string) $ticket->service_contract_id,
            default => (string) $ticket->assigned_team_id,
        };
    }

    /**
     * @param  Collection<int, Ticket>  $tickets
     * @return array<string, mixed>
     */
    private function summarize(Collection $tickets): array
    {
        $responded = $tickets->filter(fn (Ticket $ticket): bool => $ticket->first_responded_at !== null);
        $resolved = $tickets->filter(fn (Ticket $ticket): bool => $ticket->resolved_at !== null);
        $scores = $tickets->pluck('csat_score')->filter(static fn (mixed $score): bool => is_int($score) && $score >= 1 && $score <= 5)->values();

        $responseMet = $responded->filter(fn (Ticket $ticket): bool => $ticket->response_due_at === null || $ticket->first_responded_at <= $ticket->response_due_at)->count();
        $resolutionMet = $resolved->filter(fn (Ticket $ticket): bool => $ticket->resolution_due_at === null || $ticket->resolved_at <= $ticket->resolution_due_at)->count();

        $distribution = [];

        for ($score = 1; $score <= 5; $score++) {
            $distribution[(string) $score] = $scores->filter(static fn (int $value): bool => $value === $score)->count();
        }

        return [
            'tickets' => $tickets->count(),
            'mttfr_minutes' => $this->meanMinutes($responded, 'first_responded_at'),
            'mttr_minutes' => $this->meanMinutes($resolved, 'resolved_at'),
            'first_response_compliance_percent' => $responded->isEmpty() ? null : round($responseMet * 100 / $responded->count(), 2),
            'resolution_compliance_percent' => $resolved->isEmpty() ? null : round($resolutionMet * 100 / $resolved->count(), 2),
            'csat' => [
                'responses' => $scores->count(),
                'average' => $scores->isEmpty() ? null : round($scores->avg(), 2),
                'distribution' => $distribution,
            ],
        ];
    }

    /**
     * @param  Collection<int, Ticket>  $tickets
     */
    private function meanMinutes(Collection $tickets, string $column): ?float
    {
        if ($tickets->isEmpty()) {
            return null;
        }

        $total = $tickets->sum(fn (Ticket $ticket): float => max(0, $ticket->created_at->diffInSeconds($ticket->getAttribute($column), false)) / 60);

        return round($total / $tickets->count(), 1);
    }
}
