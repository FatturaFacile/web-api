<?php

declare(strict_types=1);

namespace Modules\Governance\Services;

use Illuminate\Support\Carbon;
use Modules\Governance\Models\GovAction;
use Modules\Governance\Models\GovAudit;
use Modules\Governance\Models\GovAuditPlan;
use Modules\Governance\Models\GovControl;
use Modules\Governance\Models\GovControlTestRun;
use Modules\Governance\Models\GovFinding;
use Modules\Governance\Models\GovObligation;
use Modules\Governance\Models\GovRisk;

/**
 * Figures of the governance dashboard: the risk heatmap, open findings by severity, overdue actions and obligations, and
 * the pass rate of the control tests. Every query is scoped to the active company by the models.
 */
final class GovernanceDashboardService
{
    /** Months of control tests counted in the pass rate. */
    public const int PassRateMonths = 12;

    /**
     * @param  string  $basis  "residual" (the residual score where assessed, else the inherent one) or "inherent"
     * @return array<string, mixed>
     */
    public function summary(string $basis = 'residual', ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $todayDate = $today->toDateString();

        return [
            'as_of' => $todayDate,
            'basis' => $basis,
            'risks' => $this->risks($basis, $todayDate),
            'controls' => $this->controls($today),
            'findings' => $this->findings(),
            'actions' => $this->actions($today),
            'obligations' => $this->obligations($today),
            'audits' => $this->audits($today),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function risks(string $basis, string $today): array
    {
        $live = GovRisk::query()->whereIn('status', GovRisk::LiveStatuses)->get();
        $cells = [];

        foreach (range(5, 1) as $likelihood) {
            foreach (range(1, 5) as $impact) {
                $cells["{$likelihood}-{$impact}"] = ['likelihood' => $likelihood, 'impact' => $impact, 'score' => $likelihood * $impact, 'rating' => GovRisk::ratingOf($likelihood * $impact), 'count' => 0];
            }
        }

        $byRating = array_fill_keys(GovRisk::Ratings, 0);

        foreach ($live as $risk) {
            $useResidual = $basis === 'residual' && $risk->residual_likelihood !== null && $risk->residual_impact !== null;
            $likelihood = $useResidual ? $risk->residual_likelihood : $risk->likelihood;
            $impact = $useResidual ? $risk->residual_impact : $risk->impact;
            $cells["{$likelihood}-{$impact}"]['count']++;
            $byRating[GovRisk::ratingOf($likelihood * $impact)]++;
        }

        return [
            'total' => $live->count(),
            'by_status' => $this->countBy(GovRisk::query(), 'status', GovRisk::Statuses),
            'by_rating' => $byRating,
            'review_overdue' => $live->filter(static fn (GovRisk $risk): bool => $risk->next_review_date !== null && $risk->next_review_date->toDateString() < $today)->count(),
            'heatmap' => array_values($cells),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function controls(Carbon $today): array
    {
        $since = $today->copy()->subMonthsNoOverflow(self::PassRateMonths)->toDateString();
        $results = $this->countBy(GovControlTestRun::query()->whereDate('tested_on', '>=', $since), 'result', GovControlTestRun::Results);
        $tests = array_sum($results);

        return [
            'active' => GovControl::query()->where('status', 'active')->count(),
            'test_overdue' => GovControl::query()->where('status', 'active')->whereNotNull('next_test_due_date')->whereDate('next_test_due_date', '<', $today->toDateString())->count(),
            'never_tested' => GovControl::query()->where('status', 'active')->whereNull('last_tested_at')->count(),
            'tests_in_period' => $tests,
            'tests_by_result' => $results,
            'pass_rate' => $tests === 0 ? null : round($results['pass'] * 100 / $tests, 1),
            'pass_rate_months' => self::PassRateMonths,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function findings(): array
    {
        $open = GovFinding::query()->whereIn('status', GovFinding::OpenStatuses);

        return [
            'open_total' => (clone $open)->count(),
            'open_by_severity' => $this->countBy($open, 'severity', GovFinding::Severities),
            'risk_accepted' => GovFinding::query()->where('status', 'risk_accepted')->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function actions(Carbon $today): array
    {
        $open = GovAction::query()->whereIn('status', GovAction::OpenStatuses);

        return [
            'open' => (clone $open)->count(),
            'overdue' => (clone $open)->whereDate('due_date', '<', $today->toDateString())->count(),
            'due_next_30_days' => (clone $open)->whereDate('due_date', '>=', $today->toDateString())->whereDate('due_date', '<=', $today->copy()->addDays(30)->toDateString())->count(),
            'awaiting_verification' => GovAction::query()->where('status', 'completed')->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function obligations(Carbon $today): array
    {
        $open = GovObligation::query()->whereIn('status', GovObligation::OpenStatuses);

        return [
            'open' => (clone $open)->count(),
            'overdue' => (clone $open)->whereDate('due_date', '<', $today->toDateString())->count(),
            'due_next_30_days' => (clone $open)->whereDate('due_date', '>=', $today->toDateString())->whereDate('due_date', '<=', $today->copy()->addDays(30)->toDateString())->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function audits(Carbon $today): array
    {
        $plan = GovAuditPlan::query()->where('year', (int) $today->format('Y'))->first();
        $planned = $plan === null ? 0 : GovAudit::query()->where('audit_plan_id', $plan->id)->where('status', '!=', 'cancelled')->count();
        $closed = $plan === null ? 0 : GovAudit::query()->where('audit_plan_id', $plan->id)->where('status', 'closed')->count();

        return [
            'year' => (int) $today->format('Y'),
            'plan_status' => $plan?->status,
            'plan_audits' => $planned,
            'plan_closed' => $closed,
            'plan_completion_rate' => $planned === 0 ? null : round($closed * 100 / $planned, 1),
            'in_progress' => GovAudit::query()->whereIn('status', GovAudit::FieldworkStatuses)->count(),
            'late_to_start' => GovAudit::query()->where('status', 'planned')->whereDate('planned_start', '<', $today->toDateString())->count(),
        ];
    }

    /**
     * Counts of a query grouped by a column, with every expected value present (zero when missing).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     * @param  list<string>  $values
     * @return array<string, int>
     */
    private function countBy($query, string $column, array $values): array
    {
        $counts = array_fill_keys($values, 0);

        foreach ((clone $query)->selectRaw("{$column} as bucket, count(*) as total")->groupBy($column)->get() as $row) {
            $counts[(string) $row->getAttribute('bucket')] = (int) $row->getAttribute('total');
        }

        return $counts;
    }
}
