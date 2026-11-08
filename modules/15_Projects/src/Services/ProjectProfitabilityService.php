<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Carbon\CarbonImmutable;
use Modules\Projects\Contracts\JobCostingAccumulationServiceInterface;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBudget;
use Modules\Projects\Models\ProjectMilestone;
use Modules\Projects\Models\ProjectRevenueRecognition;
use Modules\Projects\Models\ProjectTimesheet;
use Modules\Projects\Support\Money;

/**
 * Profitability and forecast of a project, computed on demand from the source records (no stored totals are trusted).
 *
 * Revenue to date is the recognised revenue for projects with a recognition method and the invoiced revenue for the
 * others. The forecast uses the estimate at completion of the earned value analysis; a negative forecast margin flags
 * a loss-making contract for the accountant.
 */
final readonly class ProjectProfitabilityService
{
    public function __construct(
        private JobCostingAccumulationServiceInterface $costing,
        private ProjectInvoiceReader $invoices,
        private ProjectBillingService $billing,
        private ProjectCommitmentService $commitments,
        private ProjectBudgetService $budgets,
        private EarnedValueCalculationService $evm,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(Project $project): array
    {
        $byCategory = $this->costing->actualCostByCategory($project);
        $actualCost = Money::add(...array_values($byCategory));
        $billed = $this->invoices->billedRevenue($project);
        $lastRecognition = ProjectRevenueRecognition::query()->where('project_id', $project->id)->orderByDesc('accounting_period_date')->first();
        $recognised = $project->revenue_recognition_method !== null ? Money::of($lastRecognition->cumulative_recognized_revenue ?? 0) : Money::of($billed);
        $grossMargin = Money::sub($recognised, $actualCost);
        $metrics = $this->evm->metrics($project, CarbonImmutable::today(), Money::amount($actualCost));
        $contractValue = Money::of($project->contract_value);
        $forecastRevenue = Money::max($contractValue, $billed);
        $forecastMargin = Money::sub($forecastRevenue, $metrics['estimate_at_completion']);
        $budget = ProjectBudget::query()->where('project_id', $project->id)->first();

        $hours = ProjectTimesheet::query()->where('project_id', $project->id)->where('status', 'approved')
            ->selectRaw('COALESCE(SUM(hours_worked), 0) as total_hours, COALESCE(SUM(CASE WHEN is_billable THEN hours_worked ELSE 0 END), 0) as billable_hours')
            ->first();
        $totalHours = Money::of((string) ($hours?->getAttribute('total_hours') ?? '0'));
        $billableHours = Money::of((string) ($hours?->getAttribute('billable_hours') ?? '0'));

        return [
            'project_id' => $project->id,
            'project_number' => $project->project_number,
            'currency' => $project->currency,
            'revenue_recognition_method' => $project->revenue_recognition_method,
            'contract_value' => Money::amount($contractValue),
            'budget' => [
                'status' => $budget?->status,
                'revenue' => Money::amount($budget->total_revenue_budget ?? 0),
                'cost' => Money::amount($budget->total_cost_budget ?? 0),
                'margin' => Money::amount($budget->projected_margin_amount ?? 0),
            ],
            'revenue' => [
                'billed' => $billed,
                'draft_billing' => $this->invoices->draftBilling($project),
                'recognised' => Money::amount($recognised),
                'unbilled_time_and_materials' => $this->billing->unbilledValue($project),
                'contract_asset_balance' => Money::amount($lastRecognition->contract_asset_balance ?? 0),
            ],
            'cost' => [
                'by_category' => array_map(static fn (string $value): string => Money::amount($value), $byCategory),
                'actual' => Money::amount($actualCost),
                'committed' => $this->commitments->openCommitments($project),
                'work_in_progress' => $project->revenue_recognition_method !== null ? Money::amount(Money::sub($actualCost, $lastRecognition->cumulative_cost_of_revenue ?? 0)) : '0.00',
            ],
            'margin' => [
                'gross_margin' => Money::amount($grossMargin),
                'gross_margin_percentage' => Money::percent(Money::div($grossMargin, $recognised)),
            ],
            'forecast' => [
                'estimate_at_completion' => $metrics['estimate_at_completion'],
                'estimate_to_complete' => $metrics['estimate_to_complete'],
                'forecast_revenue' => Money::amount($forecastRevenue),
                'forecast_margin' => Money::amount($forecastMargin),
                'forecast_margin_percentage' => Money::percent(Money::div($forecastMargin, $forecastRevenue)),
                'is_loss_forecast' => Money::isNegative($forecastMargin),
            ],
            'hours' => [
                'approved' => Money::amount($totalHours),
                'billable' => Money::amount($billableHours),
                'billable_ratio_percentage' => Money::percent(Money::div($billableHours, $totalHours)),
            ],
        ];
    }

    /**
     * Forecast to completion: EVM estimate, remaining budget by category and the expected billing still to come.
     *
     * @return array<string, mixed>
     */
    public function forecast(Project $project): array
    {
        $actualCost = Money::add(...array_values($this->costing->actualCostByCategory($project)));
        $metrics = $this->evm->metrics($project, CarbonImmutable::today(), Money::amount($actualCost));
        $billed = $this->invoices->billedRevenue($project);
        $draft = $this->invoices->draftBilling($project);
        $contractValue = Money::of($project->contract_value);
        $projectedMargin = Money::sub($contractValue, $metrics['estimate_at_completion']);

        $milestones = ProjectMilestone::query()
            ->where('project_id', $project->id)
            ->where('is_billed', false)
            ->orderBy('milestone_date')
            ->get()
            ->map(static fn (ProjectMilestone $milestone): array => [
                'milestone_id' => $milestone->id,
                'name' => $milestone->name,
                'date' => $milestone->milestone_date->toDateString(),
                'is_completed' => $milestone->is_completed,
                'amount' => Money::isPositive($milestone->billing_amount)
                    ? Money::amount($milestone->billing_amount)
                    : Money::amount(Money::div(Money::mul($contractValue, $milestone->billing_percentage), 100)),
            ])->values()->all();

        return [
            'project_id' => $project->id,
            'project_number' => $project->project_number,
            'evm' => $metrics,
            'projected_margin_amount' => Money::amount($projectedMargin),
            'projected_margin_percentage' => Money::percent(Money::div($projectedMargin, $contractValue)),
            'remaining_budget' => $this->budgets->categoryReport($project),
            'inflows' => [
                'contract_value_to_bill' => Money::amount(Money::max(Money::sub(Money::sub($contractValue, $billed), $draft), 0)),
                'draft_invoices' => $draft,
                'unbilled_time_and_materials' => $this->billing->unbilledValue($project),
                'milestones' => $milestones,
            ],
            'outflows' => [
                'open_commitments' => $this->commitments->openCommitments($project),
                'estimate_to_complete' => $metrics['estimate_to_complete'],
            ],
        ];
    }
}
