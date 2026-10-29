<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Projects\Contracts\EarnedValueCalculationServiceInterface;
use Modules\Projects\Events\ProjectMarginCalculatedEvent;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectFinancialSummary;

final class EarnedValueCalculationService implements EarnedValueCalculationServiceInterface
{
    public function __construct(
        private readonly EventDispatcher $dispatcher
    ) {}

    public function recalculateEVM(Project $project): ProjectFinancialSummary
    {
        return DB::transaction(function () use ($project): ProjectFinancialSummary {
            $summary = ProjectFinancialSummary::query()
                ->where('project_id', $project->id)
                ->lockForUpdate()
                ->first();

            if (! $summary) {
                $summary = ProjectFinancialSummary::create([
                    'tenant_id' => $project->tenant_id,
                    'company_id' => $project->company_id,
                    'project_id' => $project->id,
                    'total_contract_revenue' => $project->contract_value,
                ]);
            }

            $project->loadMissing('budget');
            $bac = (float) ($project->budget?->total_cost_budget ?? 0.0);
            $progressPct = (float) $project->progress_percentage / 100.0;

            // 1. Earned Value (EV) & Actual Cost (AC)
            $ev = round($bac * $progressPct, 2);
            $ac = (float) $summary->total_actual_cost;

            // 2. Planned Value (PV) based on elapsed timeline
            $start = Carbon::parse($project->planned_start_date);
            $end = Carbon::parse($project->planned_end_date);
            $totalDays = max(1, $start->diffInDays($end));
            $now = Carbon::now();
            $elapsedDays = $now->greaterThanOrEqualTo($start)
                ? min($totalDays, max(0, $start->diffInDays($now)))
                : 0;
            $plannedProgressPct = min(1.0, $elapsedDays / $totalDays);
            $pv = round($bac * $plannedProgressPct, 2);

            // 3. Efficiency Indices (CPI & SPI)
            $cpi = $ac > 0.0 ? round($ev / $ac, 2) : 1.00;
            $spi = $pv > 0.0 ? round($ev / $pv, 2) : 1.00;

            // 4. Forecast at Completion (EAC, ETC, VAC)
            $eac = $cpi > 0.0 ? round($bac / $cpi, 2) : $bac;
            $etc = round(max(0.0, $eac - $ac), 2);
            $vac = round($bac - $eac, 2);

            // 5. Health Status Evaluation
            $healthStatus = 'green';
            if ($cpi < 0.85 || $spi < 0.85) {
                $healthStatus = 'red';
            } elseif ($cpi < 0.95 || $spi < 0.95) {
                $healthStatus = 'yellow';
            }

            $project->update(['health_status' => $healthStatus]);

            $summary->update([
                'budget_at_completion_bac' => $bac,
                'planned_value_pv' => $pv,
                'earned_value_ev' => $ev,
                'cost_performance_index_cpi' => $cpi,
                'schedule_performance_index_spi' => $spi,
                'estimate_at_completion_eac' => $eac,
                'estimate_to_complete_etc' => $etc,
                'variance_at_completion_vac' => $vac,
                'last_recalculated_at' => Carbon::now(),
            ]);

            // Dispatch Margin Calculated Event
            $contractRev = (float) $summary->total_contract_revenue;
            $grossProfit = (float) $summary->gross_profit_amount;
            $marginPct = (float) $summary->gross_profit_percentage;

            $this->dispatcher->publish(new ProjectMarginCalculatedEvent(
                projectId: (string) $project->id,
                contractRevenue: $contractRev,
                totalActualCost: $ac,
                grossMarginAmount: $grossProfit,
                grossMarginPercentage: $marginPct,
                cpi: $cpi,
                spi: $spi,
                tenantId: (string) $project->tenant_id,
                companyId: (string) ($project->company_id ?? $project->tenant_id)
            ));

            return $summary;
        });
    }
}
