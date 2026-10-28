<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Modules\Projects\Contracts\EarnedValueCalculationServiceInterface;
use Modules\Projects\Contracts\ProjectRevenueRecognitionServiceInterface;
use Modules\Projects\DTOs\RevenueRecognitionDTO;
use Modules\Projects\Events\ProjectRevenueReadyEvent;
use Modules\Projects\Events\ProjectRevenueRecognizedEvent;
use Modules\Projects\Events\ProjectWipReadyEvent;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectRevenueRecognition;

final class ProjectRevenueRecognitionService implements ProjectRevenueRecognitionServiceInterface
{
    public function __construct(
        private readonly EventDispatcher $dispatcher,
        private readonly EarnedValueCalculationServiceInterface $evmService
    ) {}

    public function calculateAndRecognize(RevenueRecognitionDTO $dto): ProjectRevenueRecognition
    {
        return DB::transaction(function () use ($dto): ProjectRevenueRecognition {
            /** @var Project $project */
            $project = Project::with('financialSummary')->findOrFail($dto->projectId);

            $summary = $project->financialSummary ?? $this->evmService->recalculateEVM($project);

            $actualCost = (float) $summary->total_actual_cost;
            $eac = (float) $summary->estimate_at_completion_eac;
            if ($eac <= 0.0) {
                $eac = (float) $summary->budget_at_completion_bac > 0.0 ? (float) $summary->budget_at_completion_bac : $actualCost;
            }

            // Cost completion percentage
            $costCompletionPct = $dto->overrideCompletionPercentage ?? ($eac > 0.0 ? min(100.0, round(($actualCost / $eac) * 100.0, 2)) : 0.0);

            $contractValue = (float) $project->contract_value;
            $cumulativeRevenue = round(($contractValue * $costCompletionPct) / 100.0, 2);

            // Previous cumulative recognized revenue
            $previouslyRecognized = (float) ProjectRevenueRecognition::where('project_id', $project->id)
                ->sum('period_revenue_adjustment');

            $periodAdjustment = round($cumulativeRevenue - $previouslyRecognized, 2);

            $recognition = ProjectRevenueRecognition::create([
                'tenant_id' => $project->tenant_id,
                'company_id' => $project->company_id,
                'project_id' => $project->id,
                'accounting_period_date' => $dto->accountingPeriodDate,
                'cumulative_actual_cost' => $actualCost,
                'estimated_total_cost_eac' => $eac,
                'cost_completion_percentage' => $costCompletionPct,
                'cumulative_recognized_revenue' => $cumulativeRevenue,
                'period_revenue_adjustment' => $periodAdjustment,
                'is_posted_to_gl' => true,
                'posted_at' => now(),
            ]);

            // Update financial summary recognized revenue
            $summary->update([
                'total_recognized_revenue' => $cumulativeRevenue,
            ]);

            // Publish Domain Events
            $this->dispatcher->publish(new ProjectRevenueRecognizedEvent(
                revenueRecognitionId: (string) $recognition->id,
                projectId: (string) $project->id,
                accountingPeriodDate: $dto->accountingPeriodDate,
                cumulativeActualCost: $actualCost,
                costCompletionPercentage: $costCompletionPct,
                cumulativeRecognizedRevenue: $cumulativeRevenue,
                periodRevenueAdjustment: $periodAdjustment,
                tenantId: (string) $project->tenant_id,
                companyId: (string) ($project->company_id ?? $project->tenant_id)
            ));

            $this->dispatcher->publish(new ProjectRevenueReadyEvent(
                recognitionId: (string) $recognition->id,
                projectId: (string) $project->id,
                recognizedAmount: $periodAdjustment,
                debitAccountCode: '1150-ACCRUED-UNBILLED-REVENUE',
                creditAccountCode: '4100-PROJECT-CONTRACT-REVENUE',
                accountingPeriod: $dto->accountingPeriodDate,
                tenantId: (string) $project->tenant_id,
                companyId: (string) ($project->company_id ?? $project->tenant_id)
            ));

            // WIP event (costs incurred vs billings)
            $wipBalance = round($actualCost - (float) $summary->total_invoiced_revenue, 2);
            $this->dispatcher->publish(new ProjectWipReadyEvent(
                projectId: (string) $project->id,
                wipBalance: $wipBalance,
                costsIncurred: $actualCost,
                billingsProgress: (float) $summary->total_invoiced_revenue,
                wipAssetAccountCode: '1300-WORK-IN-PROGRESS-ASSET',
                asOfDate: $dto->accountingPeriodDate,
                tenantId: (string) $project->tenant_id,
                companyId: (string) ($project->company_id ?? $project->tenant_id)
            ));

            return $recognition;
        });
    }
}
