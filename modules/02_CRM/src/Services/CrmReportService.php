<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use Modules\CRM\Models\Activity;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadSource;
use Modules\CRM\Models\Opportunity;
use Modules\CRM\Models\OpportunityStage;

/**
 * Sales management reports computed in the database: the pipeline by stage and owner (weighted by probability),
 * lead and opportunity conversion, and activity statistics. All figures are restricted to the active tenant.
 */
final class CrmReportService
{
    /**
     * Open pipeline grouped by stage (in pipeline order) and by owner, with weighted amounts.
     *
     * @param  array{from?: ?string, to?: ?string, owner_user_id?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function pipeline(string $tenantId, array $filters): array
    {
        $base = fn () => Opportunity::where('crm_opportunities.tenant_id', $tenantId)
            ->where('crm_opportunities.status', 'open')
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('expected_close_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('expected_close_date', '<=', $to))
            ->when($filters['owner_user_id'] ?? null, fn ($query, $owner) => $query->where('owner_user_id', $owner));

        $byStage = $base()
            ->selectRaw('stage_id, COUNT(*) AS opportunities, COALESCE(SUM(amount), 0) AS amount, COALESCE(SUM(amount * probability / 100), 0) AS weighted_amount')->toBase()
            ->groupBy('stage_id')
            ->get()
            ->keyBy('stage_id');

        $stages = OpportunityStage::where('tenant_id', $tenantId)->orderBy('sequence')->get();

        $stageRows = $stages->map(fn (OpportunityStage $stage): array => [
            'stage_id' => (string) $stage->id,
            'code' => (string) $stage->code,
            'name' => (string) $stage->name,
            'probability' => (float) $stage->default_probability,
            'opportunities' => (int) ($byStage[$stage->id]->opportunities ?? 0),
            'amount' => round((float) ($byStage[$stage->id]->amount ?? 0), 2),
            'weighted_amount' => round((float) ($byStage[$stage->id]->weighted_amount ?? 0), 2),
        ])->values()->all();

        $byOwner = $base()
            ->selectRaw('owner_user_id, COUNT(*) AS opportunities, COALESCE(SUM(amount), 0) AS amount, COALESCE(SUM(amount * probability / 100), 0) AS weighted_amount')->toBase()
            ->groupBy('owner_user_id')
            ->orderByRaw('SUM(amount) DESC')
            ->get()
            ->map(fn ($row): array => [
                'owner_user_id' => $row->owner_user_id,
                'opportunities' => (int) $row->opportunities,
                'amount' => round((float) $row->amount, 2),
                'weighted_amount' => round((float) $row->weighted_amount, 2),
            ])->values()->all();

        return [
            'by_stage' => $stageRows,
            'by_owner' => $byOwner,
            'totals' => [
                'opportunities' => array_sum(array_column($stageRows, 'opportunities')),
                'amount' => round(array_sum(array_column($stageRows, 'amount')), 2),
                'weighted_amount' => round(array_sum(array_column($stageRows, 'weighted_amount')), 2),
            ],
        ];
    }

    /**
     * Lead funnel and opportunity win rate in a period.
     *
     * @param  array{from?: ?string, to?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function conversion(string $tenantId, array $filters): array
    {
        $leads = fn () => Lead::where('tenant_id', $tenantId)
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('created_at', '<=', $to.' 23:59:59'));

        $byStatus = $leads()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->map(fn ($total): int => (int) $total)->all();
        $total = array_sum($byStatus);
        $converted = $byStatus['converted'] ?? 0;
        $qualified = ($byStatus['qualified'] ?? 0) + $converted;
        $avgDays = $leads()->whereNotNull('converted_at')->selectRaw('AVG(EXTRACT(EPOCH FROM (converted_at - created_at)) / 86400) AS days')->value('days');

        $sources = LeadSource::where('tenant_id', $tenantId)->pluck('code', 'id');
        $bySource = $leads()
            ->selectRaw("lead_source_id, COUNT(*) AS total, SUM(CASE WHEN status = 'converted' THEN 1 ELSE 0 END) AS converted")->toBase()
            ->groupBy('lead_source_id')
            ->get()
            ->map(fn ($row): array => [
                'lead_source' => $row->lead_source_id !== null ? ($sources[$row->lead_source_id] ?? null) : null,
                'leads' => (int) $row->total,
                'converted' => (int) $row->converted,
                'conversion_rate' => $this->rate((int) $row->converted, (int) $row->total),
            ])->values()->all();

        $closed = Opportunity::where('tenant_id', $tenantId)
            ->whereIn('status', ['won', 'lost'])
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereRaw('COALESCE(actual_close_date, updated_at::date) >= ?', [$from]))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereRaw('COALESCE(actual_close_date, updated_at::date) <= ?', [$to]))
            ->selectRaw('status, COUNT(*) AS total, COALESCE(SUM(amount), 0) AS amount')->toBase()
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $won = (int) ($closed['won']->total ?? 0);
        $lost = (int) ($closed['lost']->total ?? 0);

        return [
            'leads' => [
                'total' => $total,
                'by_status' => $byStatus,
                'qualified_or_converted' => $qualified,
                'converted' => $converted,
                'qualification_rate' => $this->rate($qualified, $total),
                'conversion_rate' => $this->rate($converted, $total),
                'average_days_to_convert' => $avgDays !== null ? round((float) $avgDays, 1) : null,
                'by_source' => $bySource,
            ],
            'opportunities' => [
                'won' => $won,
                'lost' => $lost,
                'won_amount' => round((float) ($closed['won']->amount ?? 0), 2),
                'lost_amount' => round((float) ($closed['lost']->amount ?? 0), 2),
                'win_rate' => $this->rate($won, $won + $lost),
            ],
        ];
    }

    /**
     * Activity volume, completion and backlog by type and assignee in a period.
     *
     * @param  array{from?: ?string, to?: ?string, assigned_user_id?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function activities(string $tenantId, array $filters): array
    {
        $base = fn () => Activity::where('tenant_id', $tenantId)
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('created_at', '<=', $to.' 23:59:59'))
            ->when($filters['assigned_user_id'] ?? null, fn ($query, $user) => $query->where('assigned_user_id', $user));

        $aggregate = "COUNT(*) AS total, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed, SUM(CASE WHEN status IN ('pending', 'in_progress') AND due_date < ? THEN 1 ELSE 0 END) AS overdue";
        $now = now()->toDateTimeString();
        $row = fn ($item, array $extra): array => [
            ...$extra,
            'total' => (int) $item->total,
            'completed' => (int) $item->completed,
            'overdue' => (int) $item->overdue,
            'completion_rate' => $this->rate((int) $item->completed, (int) $item->total),
        ];

        $byType = $base()->selectRaw("type, {$aggregate}", [$now])->groupBy('type')->orderBy('type')->toBase()->get()
            ->map(fn ($item): array => $row($item, ['type' => $item->type]))->values()->all();
        $byAssignee = $base()->selectRaw("assigned_user_id, {$aggregate}", [$now])->groupBy('assigned_user_id')->toBase()->get()
            ->map(fn ($item): array => $row($item, ['assigned_user_id' => $item->assigned_user_id]))->values()->all();
        $byStatus = $base()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->map(fn ($total): int => (int) $total)->all();

        $total = array_sum($byStatus);
        $completed = $byStatus['completed'] ?? 0;

        return [
            'total' => $total,
            'completed' => $completed,
            'overdue' => array_sum(array_column($byType, 'overdue')),
            'completion_rate' => $this->rate($completed, $total),
            'by_status' => $byStatus,
            'by_type' => $byType,
            'by_assignee' => $byAssignee,
        ];
    }

    private function rate(int $part, int $whole): float
    {
        return $whole > 0 ? round($part * 100 / $whole, 2) : 0.0;
    }
}
