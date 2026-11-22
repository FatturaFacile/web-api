<?php

declare(strict_types=1);

namespace Modules\Projects\Queries;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Projects\Models\Project;

/**
 * Portfolio overview of the projects of the active company: a filtered, paginated list and KPIs computed over the whole
 * filtered set (not only the page shown).
 */
final readonly class GetProjectPortfolioQuery
{
    private const array ClosedStatuses = ['completed', 'closed', 'cancelled'];

    /**
     * @param  array<string, mixed>  $filters  status (comma separated), project_type, billing_method, contact_id,
     *                                         project_manager_user_id, portfolio_id, health_status, has_public_codes,
     *                                         search, from, to
     * @return array{items: LengthAwarePaginator<int, Project>, kpis: array<string, mixed>}
     */
    public function handle(array $filters, int $perPage): array
    {
        $items = $this->filtered($filters)->with('client')->orderByDesc('created_at')->gridPaginate($perPage);

        return ['items' => $items, 'kpis' => $this->kpis($filters)];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function kpis(array $filters): array
    {
        $totals = $this->filtered($filters)->toBase()->selectRaw(
            'COUNT(*) AS project_count, '
            .'COALESCE(SUM(contract_value), 0) AS contract_value, '
            .'COALESCE(SUM(planned_hours), 0) AS planned_hours, COALESCE(SUM(actual_hours), 0) AS actual_hours, '
            .'COALESCE(SUM(planned_cost), 0) AS planned_cost, COALESCE(SUM(actual_cost), 0) AS actual_cost, '
            .'COALESCE(SUM(planned_revenue), 0) AS planned_revenue, COALESCE(AVG(progress_percentage), 0) AS average_progress',
        )->first();

        $byStatus = $this->filtered($filters)->toBase()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->map(static fn (mixed $count): int => (int) $count)->all();
        $byHealth = $this->filtered($filters)->toBase()->selectRaw('health_status, COUNT(*) AS total')->groupBy('health_status')->pluck('total', 'health_status')->map(static fn (mixed $count): int => (int) $count)->all();
        $overdue = $this->filtered($filters)->whereNotIn('status', self::ClosedStatuses)->whereDate('planned_end_date', '<', now()->toDateString())->count();

        return [
            'project_count' => (int) ($totals->project_count ?? 0),
            'by_status' => $byStatus,
            'by_health' => $byHealth,
            'overdue_count' => $overdue,
            'at_risk_count' => ($byHealth['yellow'] ?? 0) + ($byHealth['red'] ?? 0),
            'contract_value' => number_format((float) ($totals->contract_value ?? 0), 2, '.', ''),
            'planned_hours' => number_format((float) ($totals->planned_hours ?? 0), 2, '.', ''),
            'actual_hours' => number_format((float) ($totals->actual_hours ?? 0), 2, '.', ''),
            'planned_cost' => number_format((float) ($totals->planned_cost ?? 0), 2, '.', ''),
            'actual_cost' => number_format((float) ($totals->actual_cost ?? 0), 2, '.', ''),
            'planned_revenue' => number_format((float) ($totals->planned_revenue ?? 0), 2, '.', ''),
            'average_progress' => number_format((float) ($totals->average_progress ?? 0), 2, '.', ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Project>
     */
    private function filtered(array $filters): Builder
    {
        $query = Project::query();

        if (! empty($filters['status'])) {
            $query->whereIn('status', array_filter(array_map('trim', explode(',', (string) $filters['status']))));
        }

        foreach (['project_type', 'billing_method', 'contact_id', 'project_manager_user_id', 'portfolio_id', 'health_status'] as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        if (! empty($filters['has_public_codes'])) {
            $query->where(fn (Builder $inner) => $inner->whereNotNull('cup_code')->orWhereNotNull('cig_code'));
        }

        if (! empty($filters['search'])) {
            $term = '%'.addcslashes((string) $filters['search'], '%_\\').'%';
            $query->where(fn (Builder $inner) => $inner->whereLike('project_number', $term)->orWhereLike('name', $term)->orWhereLike('cup_code', $term)->orWhereLike('cig_code', $term));
        }

        if (! empty($filters['from'])) {
            $query->whereDate('planned_end_date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('planned_start_date', '<=', $filters['to']);
        }

        return $query;
    }
}
