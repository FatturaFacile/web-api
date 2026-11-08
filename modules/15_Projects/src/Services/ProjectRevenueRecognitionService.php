<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Projects\Contracts\JobCostingAccumulationServiceInterface;
use Modules\Projects\Contracts\ProjectRevenueRecognitionServiceInterface;
use Modules\Projects\DTOs\RevenueRecognitionDTO;
use Modules\Projects\Events\ProjectRevenueRecognitionReadyEvent;
use Modules\Projects\Events\ProjectRevenueRecognizedEvent;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectRevenueRecognition;
use Modules\Projects\Support\Money;
use Modules\Projects\Support\ProjectAuditTrail;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Revenue recognition of long-term contracts. Two methods are implemented:
 *
 *  percentage_of_completion  cost-to-cost: % complete = actual cost / estimated total cost, where the estimated total
 *                            cost is the larger of the budget at completion and the actual cost. Recognised revenue =
 *                            contract value x % complete; the whole actual cost is the cost of revenue. A manager may
 *                            state the % complete instead (for instance from a progress certificate, SAL).
 *  completed_contract        nothing is recognised until the project is completed; then the contract value is
 *                            recognised and the whole actual cost becomes the cost of revenue.
 *
 * Ledger mechanics: costs of these projects are capitalised into work in progress as they are approved (see
 * JobCostingAccumulationService). Each run releases the cost of revenue of the period from WIP and adjusts the contract
 * asset (unbilled revenue) by the change of recognised minus invoiced revenue, so the revenue in the result equals the
 * recognised revenue regardless of the invoices issued; a negative contract asset means billing in excess of
 * recognised revenue. Expected losses on onerous contracts are not provisioned automatically: the profitability report
 * shows a negative forecast margin for the accountant to assess. Runs are cumulative and must follow date order.
 */
final readonly class ProjectRevenueRecognitionService implements ProjectRevenueRecognitionServiceInterface
{
    public const string ContractAssetAccountCode = '1150';

    public const string RevenueAccountCode = '4000';

    public const string CostOfRevenueAccountCode = '5000';

    public function __construct(
        private EventDispatcher $dispatcher,
        private JobCostingAccumulationServiceInterface $costing,
        private ProjectBudgetService $budgets,
        private ProjectInvoiceReader $invoices,
        private ProjectAuditTrail $audit,
    ) {}

    /**
     * @throws ValidationException when the project has no method, the date is out of order or the override is not allowed
     * @throws ConflictHttpException when a run already exists for the date or the project is closed
     */
    public function calculateAndRecognize(RevenueRecognitionDTO $dto): ProjectRevenueRecognition
    {
        return DB::transaction(function () use ($dto): ProjectRevenueRecognition {
            $project = Project::query()->whereKey($dto->projectId)->lockForUpdate()->firstOrFail();
            $this->assertRunnable($project, $dto);

            $previous = ProjectRevenueRecognition::query()->where('project_id', $project->id)->orderByDesc('accounting_period_date')->first();
            $summary = $this->costing->rollupProjectCosts($project);
            $actualCost = Money::of($summary->total_actual_cost);
            $billed = $this->invoices->billedRevenue($project);
            $result = $this->recognise($project, $actualCost, $dto->overrideCompletionPercentage);

            $previousRevenue = Money::of($previous->cumulative_recognized_revenue ?? 0);
            $previousCost = Money::of($previous->cumulative_cost_of_revenue ?? 0);
            $previousAsset = Money::of($previous->contract_asset_balance ?? 0);
            $assetBalance = Money::sub($result['revenue'], $billed);
            $assetAdjustment = Money::amount(Money::sub($assetBalance, $previousAsset));
            $costRelease = Money::amount(Money::sub($result['cost_of_revenue'], $previousCost));
            $postsToLedger = ! Money::isZero($assetAdjustment) || ! Money::isZero($costRelease);

            $recognition = ProjectRevenueRecognition::create([
                'tenant_id' => $project->tenant_id,
                'company_id' => $project->company_id,
                'project_id' => $project->id,
                'accounting_period_date' => $dto->accountingPeriodDate,
                'recognition_method' => $project->revenue_recognition_method,
                'contract_value' => Money::amount($project->contract_value),
                'cumulative_actual_cost' => Money::amount($actualCost),
                'estimated_total_cost_eac' => Money::amount($result['estimated_total_cost']),
                'cost_completion_percentage' => Money::percent($result['ratio']) ?? '0.00',
                'cumulative_recognized_revenue' => Money::amount($result['revenue']),
                'period_revenue_adjustment' => Money::amount(Money::sub($result['revenue'], $previousRevenue)),
                'cumulative_billed_revenue' => $billed,
                'contract_asset_balance' => Money::amount($assetBalance),
                'period_contract_asset_adjustment' => $assetAdjustment,
                'cumulative_cost_of_revenue' => Money::amount($result['cost_of_revenue']),
                'period_cost_of_revenue' => $costRelease,
                'is_posted_to_gl' => $postsToLedger,
                'posted_at' => $postsToLedger ? now() : null,
                'recognized_by' => $dto->userId,
            ]);

            $summary->update(['total_recognized_revenue' => $recognition->cumulative_recognized_revenue]);
            $companyId = (string) ($project->company_id ?? $project->tenant_id);

            $this->dispatcher->publishAndDispatch(new ProjectRevenueRecognizedEvent(
                revenueRecognitionId: (string) $recognition->id,
                projectId: (string) $project->id,
                accountingPeriodDate: $dto->accountingPeriodDate,
                cumulativeActualCost: (float) $recognition->cumulative_actual_cost,
                costCompletionPercentage: (float) $recognition->cost_completion_percentage,
                cumulativeRecognizedRevenue: (float) $recognition->cumulative_recognized_revenue,
                periodRevenueAdjustment: (float) $recognition->period_revenue_adjustment,
                tenantId: (string) $project->tenant_id,
                companyId: $companyId
            ));

            if ($postsToLedger) {
                $this->dispatcher->publishAndDispatch(new ProjectRevenueRecognitionReadyEvent(
                    recognitionId: (string) $recognition->id,
                    projectId: (string) $project->id,
                    cumulativeRecognizedRevenue: (string) $recognition->cumulative_recognized_revenue,
                    periodRevenueAdjustment: $assetAdjustment,
                    costCompletionPercentage: (string) $recognition->cost_completion_percentage,
                    accountingPeriod: $dto->accountingPeriodDate,
                    debitAccountCode: self::ContractAssetAccountCode,
                    creditAccountCode: self::RevenueAccountCode,
                    tenantId: (string) $project->tenant_id,
                    companyId: $companyId,
                    occurredAt: $dto->accountingPeriodDate,
                    periodCostOfRevenue: $costRelease,
                    recognitionMethod: (string) $project->revenue_recognition_method,
                    costOfRevenueAccountCode: self::CostOfRevenueAccountCode,
                    workInProgressAccountCode: JobCostingAccumulationService::WipAccountCode,
                ));
            }

            $this->audit->record($project, $dto->userId ?? (string) $project->project_manager_user_id, 'revenue_recognized', "Recognised revenue {$recognition->cumulative_recognized_revenue} as of {$dto->accountingPeriodDate} ({$project->revenue_recognition_method})", [], $recognition->only(['cumulative_recognized_revenue', 'contract_asset_balance', 'cumulative_cost_of_revenue']), $recognition);

            return $recognition;
        });
    }

    /**
     * Cumulative revenue and cost of revenue the method recognises for the project now.
     *
     * @return array{ratio: string, revenue: string, cost_of_revenue: string, estimated_total_cost: string}
     */
    public function recognise(Project $project, string $actualCost, ?string $overridePercentage = null): array
    {
        $contractValue = Money::of($project->contract_value);
        $estimatedTotal = Money::max($this->budgets->budgetAtCompletion($project), $actualCost);

        if ($project->revenue_recognition_method === 'completed_contract') {
            $done = in_array($project->status, ['completed', 'closed'], true);

            return [
                'ratio' => $done ? '1' : '0',
                'revenue' => $done ? $contractValue : '0',
                'cost_of_revenue' => $done ? Money::of($actualCost) : '0',
                'estimated_total_cost' => $estimatedTotal,
            ];
        }

        $ratio = $overridePercentage !== null
            ? (Money::div($overridePercentage, 100) ?? '0')
            : Money::min(Money::div($actualCost, $estimatedTotal) ?? '0', 1);

        return [
            'ratio' => $ratio,
            'revenue' => Money::mul($contractValue, $ratio),
            'cost_of_revenue' => Money::of($actualCost),
            'estimated_total_cost' => $estimatedTotal,
        ];
    }

    private function assertRunnable(Project $project, RevenueRecognitionDTO $dto): void
    {
        if (! $project->isOpen()) {
            throw new ConflictHttpException("Project {$project->project_number} is {$project->status}: revenue is no longer recognised on it.");
        }

        if ($project->revenue_recognition_method === null) {
            throw ValidationException::withMessages(['project' => 'The project has no revenue recognition method: its revenue is the invoiced revenue.']);
        }

        if ($dto->overrideCompletionPercentage !== null && $project->revenue_recognition_method !== 'percentage_of_completion') {
            throw ValidationException::withMessages(['override_completion_percentage' => 'A completion percentage can be stated only under the percentage-of-completion method.']);
        }

        if ($project->revenue_recognition_method === 'percentage_of_completion' && $dto->overrideCompletionPercentage === null && Money::isZero($this->budgets->budgetAtCompletion($project))) {
            throw ValidationException::withMessages(['project' => 'Cost-to-cost recognition needs an approved budget to estimate the total cost.']);
        }

        $latest = ProjectRevenueRecognition::query()->where('project_id', $project->id)->max('accounting_period_date');

        if ($latest !== null && (string) $latest >= $dto->accountingPeriodDate) {
            $message = substr((string) $latest, 0, 10) === $dto->accountingPeriodDate
                ? 'Revenue was already recognised for this date.'
                : 'Revenue was already recognised for a later date: runs must follow date order.';

            throw new ConflictHttpException($message);
        }
    }
}
