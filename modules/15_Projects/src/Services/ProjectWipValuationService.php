<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\Projects\DTOs\RevenueRecognitionDTO;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBillingContract;
use Modules\Projects\Models\ProjectMilestone;
use Modules\Projects\Models\ProjectRevenueRecognition;
use Modules\Projects\Models\ProjectWipValuationRun;
use Modules\Projects\Services\Fiscal\ProjectFiscalBillingStrategyResolver;
use Modules\Projects\Support\BillingStatuses;
use Modules\Projects\Support\Money;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Period-end valuation of the work in progress of projects (Mod 081, lavori in corso su ordinazione).
 *
 * The valuation delegates the recognition and its double-entry posting (contract asset against revenue, cost of revenue
 * released from WIP) to ProjectRevenueRecognitionService and records the outcome as a run:
 *
 *  cost_to_cost_percentage_of_completion  ratio = actual cost / estimated total cost
 *  completed_contract                     nothing until the project is completed, then everything
 *  milestone_progress                     ratio = value of the completed milestones / contract value
 *
 * A run is idempotent per project and period end date: a second request returns the run and posts nothing again. The
 * difference between recognised and billed revenue is the contract asset (positive) or the contract liability (negative).
 * For an onerous contract the provision of the expected loss is reported for the accountant, in line with the recognition
 * service, which does not post it automatically.
 */
final readonly class ProjectWipValuationService
{
    public function __construct(
        private ProjectRevenueRecognitionService $recognition,
        private ProjectFiscalBillingStrategyResolver $strategies,
    ) {}

    /**
     * Values one project at the period end.
     *
     * @return array{run: ProjectWipValuationRun, replayed: bool}
     *
     * @throws ValidationException when the method does not fit the project
     * @throws HttpExceptionInterface when the recognition service refuses the run (project closed, date out of order)
     */
    public function run(Project $project, string $periodEndDate, ?string $method, string $actorUserId): array
    {
        $existing = ProjectWipValuationRun::query()->where('project_id', $project->id)->whereDate('period_end_date', $periodEndDate)->first();

        if ($existing !== null) {
            return ['run' => $existing, 'replayed' => true];
        }

        $method ??= $project->revenue_recognition_method === 'completed_contract' ? 'completed_contract' : 'cost_to_cost_percentage_of_completion';
        $override = $this->prepareProject($project, $method);

        $recognition = ProjectRevenueRecognition::query()->where('project_id', $project->id)->whereDate('accounting_period_date', $periodEndDate)->first()
            ?? $this->recognition->calculateAndRecognize(new RevenueRecognitionDTO($project->id, $periodEndDate, $override, $actorUserId));

        $contract = ProjectBillingContract::query()->where('project_id', $project->id)->first();
        $strategy = $contract !== null ? $this->strategies->forContract($contract) : $this->strategies->forJurisdiction($this->strategies->defaultJurisdictionForCurrency((string) $project->currency));
        $ratio = Money::of(Money::div($recognition->cost_completion_percentage, 100) ?? '0');

        $run = DB::transaction(fn (): ProjectWipValuationRun => ProjectWipValuationRun::create([
            'tenant_id' => $project->tenant_id,
            'company_id' => $project->company_id,
            'project_id' => $project->id,
            'revenue_recognition_id' => $recognition->id,
            'period_end_date' => $periodEndDate,
            'valuation_method' => $method,
            'accounting_standard' => $strategy->accountingStandard(),
            'cumulative_actual_cost_to_date' => Money::round($recognition->cumulative_actual_cost, 4),
            'estimated_total_cost_at_completion' => Money::round($recognition->estimated_total_cost_eac, 4),
            'completion_ratio' => Money::round($ratio, 6),
            'recognized_revenue_to_date' => Money::round($recognition->cumulative_recognized_revenue, 4),
            'billed_to_date' => Money::round($recognition->cumulative_billed_revenue, 4),
            'wip_asset_or_deferred_revenue_diff' => Money::round($recognition->contract_asset_balance, 4),
            'expected_loss_provision' => Money::round($strategy->expectedLossProvision((string) $recognition->contract_value, (string) $recognition->estimated_total_cost_eac, $ratio), 4),
            'gl_journal_entry_id' => $this->journalEntryId($project, (string) $recognition->id),
            'status' => BillingStatuses::WipPosted,
            'run_by' => $actorUserId,
        ]));

        return ['run' => $run, 'replayed' => false];
    }

    /**
     * Values every open project of the tenant that has a recognition method (the ledger capitalises the costs of those
     * only); the projects that cannot be valued are reported with the reason instead of stopping the batch.
     *
     * @return array{runs: list<array{run: ProjectWipValuationRun, replayed: bool}>, skipped: list<array{project_id: string, reason: string}>}
     */
    public function runBatch(string $periodEndDate, ?string $method, string $actorUserId): array
    {
        $runs = [];
        $skipped = [];
        $projects = Project::query()
            ->whereNotIn('status', Project::FinalStatuses)
            ->whereNotNull('revenue_recognition_method')
            ->orderBy('project_number')->get();

        foreach ($projects as $project) {
            try {
                $runs[] = $this->run($project, $periodEndDate, $method, $actorUserId);
            } catch (ValidationException $exception) {
                $skipped[] = ['project_id' => (string) $project->id, 'reason' => (string) collect($exception->errors())->flatten()->first()];
            } catch (HttpExceptionInterface $exception) {
                $skipped[] = ['project_id' => (string) $project->id, 'reason' => $exception->getMessage()];
            }
        }

        return ['runs' => $runs, 'skipped' => $skipped];
    }

    /**
     * Checks that the requested valuation method fits the recognition method of the project (set when the project was
     * created: it decides whether costs are capitalised into WIP) and returns the completion percentage to impose
     * (milestone progress), if any.
     *
     * @throws ValidationException
     */
    private function prepareProject(Project $project, string $method): ?string
    {
        if (! in_array($method, BillingStatuses::WipMethods, true)) {
            throw ValidationException::withMessages(['valuation_method' => [__('project_billing.errors.unknown_wip_method')]]);
        }

        $recognitionMethod = $method === 'completed_contract' ? 'completed_contract' : 'percentage_of_completion';

        if ($project->revenue_recognition_method === null) {
            throw ValidationException::withMessages(['project' => [__('project_billing.errors.wip_method_required')]]);
        }

        if ($project->revenue_recognition_method !== $recognitionMethod) {
            throw ValidationException::withMessages(['valuation_method' => [__('project_billing.errors.wip_method_mismatch', ['method' => $project->revenue_recognition_method])]]);
        }

        return $method === 'milestone_progress' ? $this->milestonePercent($project) : null;
    }

    /**
     * Completed milestone value as a percentage of the contract value.
     *
     * @throws ValidationException when the project has no milestone with a value
     */
    private function milestonePercent(Project $project): string
    {
        $contractValue = Money::of($project->contract_value);
        $milestones = ProjectMilestone::query()->where('project_id', $project->id)->get();
        $total = Money::of(0);
        $done = Money::of(0);

        foreach ($milestones as $milestone) {
            $value = Money::isPositive($milestone->billing_amount) ? Money::of($milestone->billing_amount) : Money::mul($contractValue, Money::div($milestone->billing_percentage, 100));
            $total = Money::add($total, $value);
            $done = $milestone->is_completed ? Money::add($done, $value) : $done;
        }

        if (! Money::isPositive($total) || ! Money::isPositive($contractValue)) {
            throw ValidationException::withMessages(['valuation_method' => [__('project_billing.errors.milestones_without_value')]]);
        }

        return Money::round(Money::mul(Money::min(Money::div($done, $contractValue) ?? '0', 1), 100), 4);
    }

    private function journalEntryId(Project $project, string $recognitionId): ?string
    {
        $id = JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $project->tenant_id)
            ->where('source_document_type', 'revenue_recognition')
            ->where('source_document_id', $recognitionId)
            ->whereNull('deleted_at')
            ->value('id');

        return $id !== null ? (string) $id : null;
    }
}
