<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Enterprise\EventBus\Services\EventDispatcher;
use Modules\Projects\Contracts\EarnedValueCalculationServiceInterface;
use Modules\Projects\Contracts\ProjectForecastServiceInterface;
use Modules\Projects\Events\ProjectCashFlowForecastEvent;
use Modules\Projects\Events\ProjectForecastUpdatedEvent;
use Modules\Projects\Models\Project;

final readonly class ProjectForecastService implements ProjectForecastServiceInterface
{
    public function __construct(
        private EarnedValueCalculationServiceInterface $evmService,
        private EventDispatcher $dispatcher
    ) {}

    public function generateProjectForecast(Project $project): array
    {
        $project->loadMissing(['financialSummary', 'milestones', 'budget']);
        $summary = $project->financialSummary ?? $this->evmService->recalculateEVM($project);

        $contractValue = (float) $project->contract_value;
        $bac = (float) $summary->budget_at_completion_bac;
        $ac = (float) $summary->total_actual_cost;
        $cpi = (float) $summary->cost_performance_index_cpi;
        $spi = (float) $summary->schedule_performance_index_spi;
        $eac = (float) $summary->estimate_at_completion_eac;
        $etc = (float) $summary->estimate_to_complete_etc;
        $vac = (float) $summary->variance_at_completion_vac;

        $projectedMarginAmount = round($contractValue - $eac, 2);
        $projectedMarginPct = $contractValue > 0.0 ? round(($projectedMarginAmount / $contractValue) * 100.0, 2) : 0.0;

        // Cash inflows based on project billable milestones
        $cashInflows = [];
        $totalInflow = 0.0;
        foreach ($project->milestones as $milestone) {
            $amt = (float) $milestone->billing_amount;
            $cashInflows[] = [
                'milestone_name' => $milestone->name,
                'date' => $milestone->milestone_date?->toDateString() ?? now()->addMonth()->toDateString(),
                'amount' => $amt,
            ];
            $totalInflow += $amt;
        }

        // Cash outflows based on remaining work (ETC) and budget breakdown
        $cashOutflows = [
            [
                'category' => 'remaining_labour_outflow',
                'date' => now()->addWeeks(2)->toDateString(),
                'amount' => round($etc * 0.45, 2),
            ],
            [
                'category' => 'remaining_material_outflow',
                'date' => now()->addWeeks(4)->toDateString(),
                'amount' => round($etc * 0.35, 2),
            ],
            [
                'category' => 'remaining_supplier_outflow',
                'date' => now()->addWeeks(6)->toDateString(),
                'amount' => round($etc * 0.20, 2),
            ],
        ];

        $totalOutflow = $etc;
        $netCashFlow = round($totalInflow - ($ac + $totalOutflow), 2);

        // Dispatch domain events
        $this->dispatcher->publish(new ProjectForecastUpdatedEvent(
            projectId: $project->id,
            forecastCostAtCompletion: $eac,
            forecastRevenueAtCompletion: $contractValue,
            projectedMarginPct: $projectedMarginPct,
            forecastDate: now()->toDateString(),
            tenantId: (string) $project->tenant_id,
            companyId: (string) ($project->company_id ?? $project->tenant_id)
        ));

        $this->dispatcher->publish(new ProjectCashFlowForecastEvent(
            projectId: $project->id,
            forecastDate: now()->toDateString(),
            projectedInflows: $totalInflow,
            projectedOutflows: $totalOutflow,
            netCashPosition: $netCashFlow,
            tenantId: (string) $project->tenant_id,
            companyId: (string) ($project->company_id ?? $project->tenant_id)
        ));

        return [
            'project_id' => $project->id,
            'project_number' => $project->project_number,
            'bac' => $bac,
            'ac' => $ac,
            'cpi' => $cpi,
            'spi' => $spi,
            'eac' => $eac,
            'etc' => $etc,
            'vac' => $vac,
            'projected_margin_amount' => $projectedMarginAmount,
            'projected_margin_percentage' => $projectedMarginPct,
            'cash_inflows' => $cashInflows,
            'cash_outflows' => $cashOutflows,
            'net_cash_flow' => $netCashFlow,
        ];
    }
}
