<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services\Helpdesk;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\ServiceManagement\Enums\TicketStatus;
use Modules\ServiceManagement\Models\CSATSurvey;
use Modules\ServiceManagement\Models\Ticket;

/**
 * Helpdesk figures of one tenant for a period: SLA compliance, first response and resolution times (mean, median, 90th
 * percentile, in wall-clock minutes), backlog by status and age, volume by channel and category, agent workload and
 * CSAT by team. Tickets merged into others are left out: their work is counted on the target.
 */
final class HelpdeskReportService
{
    /**
     * @return array<string, mixed>
     */
    public function report(string $tenantId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = $from->startOfDay();
        $end = $to->endOfDay();

        return [
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'sla' => $this->sla($tenantId, $start, $end),
            'first_response_minutes' => $this->durations($this->tickets($tenantId)->whereBetween('created_at', [$start, $end])->whereNotNull('first_responded_at'), 'first_responded_at'),
            'resolution_minutes' => $this->durations($this->tickets($tenantId)->whereBetween('resolved_at', [$start, $end]), 'resolved_at'),
            'backlog' => $this->backlog($tenantId),
            'volume' => $this->volume($tenantId, $start, $end),
            'agent_workload' => $this->workload($tenantId, $start, $end),
            'csat_by_team' => $this->csatByTeam($tenantId, $start, $end),
        ];
    }

    /**
     * @return Builder<Ticket>
     */
    private function tickets(string $tenantId): Builder
    {
        return Ticket::query()->where('esm_tickets.tenant_id', $tenantId)->whereNull('esm_tickets.merged_into_ticket_id');
    }

    /**
     * @return array<string, mixed>
     */
    private function sla(string $tenantId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $response = $this->tickets($tenantId)
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull('response_due_at')
            ->selectRaw('count(*) as total')
            ->selectRaw('count(*) filter (where first_responded_at is not null and first_responded_at <= response_due_at) as met')
            ->selectRaw('count(*) filter (where (first_responded_at is not null and first_responded_at > response_due_at) or (first_responded_at is null and response_due_at < ?)) as breached', [now()])
            ->first();

        $resolution = $this->tickets($tenantId)
            ->whereBetween('resolved_at', [$start, $end])
            ->whereNotNull('resolution_due_at')
            ->selectRaw('count(*) as total')
            ->selectRaw('count(*) filter (where resolved_at <= resolution_due_at) as met')
            ->first();

        $rate = static fn (int $met, int $total): ?float => $total === 0 ? null : round($met * 100 / $total, 2);

        return [
            'first_response' => ['tickets' => (int) $response->total, 'met' => (int) $response->met, 'breached' => (int) $response->breached, 'compliance_percent' => $rate((int) $response->met, (int) $response->met + (int) $response->breached)],
            'resolution' => ['tickets' => (int) $resolution->total, 'met' => (int) $resolution->met, 'breached' => (int) $resolution->total - (int) $resolution->met, 'compliance_percent' => $rate((int) $resolution->met, (int) $resolution->total)],
        ];
    }

    /**
     * @param  Builder<Ticket>  $query
     * @return array{count: int, mean: ?float, p50: ?float, p90: ?float}
     */
    private function durations(Builder $query, string $column): array
    {
        $minutes = "extract(epoch from ({$column} - created_at)) / 60";

        $row = $query
            ->selectRaw('count(*) as total')
            ->selectRaw("avg({$minutes}) as mean")
            ->selectRaw("percentile_cont(0.5) within group (order by {$minutes}) as p50")
            ->selectRaw("percentile_cont(0.9) within group (order by {$minutes}) as p90")
            ->first();

        $round = static fn (mixed $value): ?float => $value === null ? null : round((float) $value, 1);

        return ['count' => (int) $row->total, 'mean' => $round($row->mean), 'p50' => $round($row->p50), 'p90' => $round($row->p90)];
    }

    /**
     * @return array{by_status: array<string, int>, by_age: array<string, int>, total: int}
     */
    private function backlog(string $tenantId): array
    {
        $active = $this->tickets($tenantId)->whereIn('status', TicketStatus::activeValues());

        $byStatus = (clone $active)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn (mixed $total): int => (int) $total)->all();

        // The application clock, not the database one, so the age follows the time the request runs at.
        $age = 'extract(epoch from (cast(? as timestamp) - created_at)) / 86400';
        $now = now()->toDateTimeString();
        $byAge = (clone $active)->selectRaw("case when {$age} < 1 then 'under_1_day' when {$age} < 3 then '1_to_3_days' when {$age} < 7 then '3_to_7_days' when {$age} < 30 then '7_to_30_days' else 'over_30_days' end as bucket, count(*) as total", array_fill(0, 4, $now))
            ->groupBy('bucket')->pluck('total', 'bucket')->map(fn (mixed $total): int => (int) $total)->all();

        $buckets = array_fill_keys(['under_1_day', '1_to_3_days', '3_to_7_days', '7_to_30_days', 'over_30_days'], 0);

        return ['by_status' => $byStatus, 'by_age' => array_merge($buckets, $byAge), 'total' => array_sum($byStatus)];
    }

    /**
     * @return array{by_channel: array<string, int>, by_category: list<array{category_id: ?string, name: ?string, tickets: int}>, total: int}
     */
    private function volume(string $tenantId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $created = $this->tickets($tenantId)->whereBetween('esm_tickets.created_at', [$start, $end]);

        $byChannel = (clone $created)->selectRaw('source, count(*) as total')->groupBy('source')->pluck('total', 'source')->map(fn (mixed $total): int => (int) $total)->all();

        $byCategory = (clone $created)
            ->leftJoin('esm_service_categories', 'esm_service_categories.id', '=', 'esm_tickets.category_id')
            ->selectRaw('esm_tickets.category_id, max(esm_service_categories.name) as name, count(*) as total')
            ->groupBy('esm_tickets.category_id')
            ->orderByDesc('total')
            ->get()
            ->map(fn (object $row): array => ['category_id' => $row->category_id, 'name' => $row->name, 'tickets' => (int) $row->total])
            ->values()
            ->all();

        return ['by_channel' => $byChannel, 'by_category' => $byCategory, 'total' => array_sum($byChannel)];
    }

    /**
     * @return list<array{technician_id: string, name: string, open_tickets: int, resolved_in_period: int, mean_resolution_minutes: ?float}>
     */
    private function workload(string $tenantId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $active = "'".implode("','", TicketStatus::activeValues())."'";

        return DB::table('esm_service_technicians as technicians')
            ->leftJoin('esm_tickets as tickets', function ($join) use ($tenantId): void {
                $join->on('tickets.assigned_technician_id', '=', 'technicians.id')
                    ->where('tickets.tenant_id', $tenantId)
                    ->whereNull('tickets.merged_into_ticket_id')
                    ->whereNull('tickets.deleted_at');
            })
            ->where('technicians.tenant_id', $tenantId)
            ->whereNull('technicians.deleted_at')
            ->groupBy('technicians.id', 'technicians.first_name', 'technicians.last_name')
            ->selectRaw('technicians.id, technicians.first_name, technicians.last_name')
            ->selectRaw("count(tickets.id) filter (where tickets.status in ({$active})) as open_tickets")
            ->selectRaw('count(tickets.id) filter (where tickets.resolved_at between ? and ?) as resolved_in_period', [$start, $end])
            ->selectRaw('avg(extract(epoch from (tickets.resolved_at - tickets.created_at)) / 60) filter (where tickets.resolved_at between ? and ?) as mean_resolution', [$start, $end])
            ->orderByDesc('open_tickets')
            ->get()
            ->map(fn (object $row): array => [
                'technician_id' => $row->id,
                'name' => trim($row->first_name.' '.$row->last_name),
                'open_tickets' => (int) $row->open_tickets,
                'resolved_in_period' => (int) $row->resolved_in_period,
                'mean_resolution_minutes' => $row->mean_resolution === null ? null : round((float) $row->mean_resolution, 1),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{team_id: ?string, name: ?string, responses: int, mean_score: ?float, satisfied_percent: ?float}>
     */
    private function csatByTeam(string $tenantId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return CSATSurvey::query()
            ->where('esm_csat_surveys.tenant_id', $tenantId)
            ->where('esm_csat_surveys.status', CSATSurvey::StatusCompleted)
            ->whereBetween('esm_csat_surveys.responded_at', [$start, $end])
            ->join('esm_tickets', 'esm_tickets.id', '=', 'esm_csat_surveys.ticket_id')
            ->leftJoin('esm_service_teams', 'esm_service_teams.id', '=', 'esm_tickets.assigned_team_id')
            ->groupBy('esm_tickets.assigned_team_id')
            ->selectRaw('esm_tickets.assigned_team_id as team_id, max(esm_service_teams.name) as name, count(*) as responses, avg(esm_csat_surveys.score) as mean_score')
            ->selectRaw('count(*) filter (where esm_csat_surveys.score >= 4) * 100.0 / count(*) as satisfied')
            ->orderByDesc('responses')
            ->get()
            ->map(fn (object $row): array => [
                'team_id' => $row->team_id,
                'name' => $row->name,
                'responses' => (int) $row->responses,
                'mean_score' => $row->mean_score === null ? null : round((float) $row->mean_score, 2),
                'satisfied_percent' => $row->satisfied === null ? null : round((float) $row->satisfied, 2),
            ])
            ->values()
            ->all();
    }
}
