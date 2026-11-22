<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Projects\Commands\ApproveAndInvoiceSalCommand;
use Modules\Projects\Commands\BillContractMilestoneCommand;
use Modules\Projects\Commands\ConsumePrepaidRetainerCommand;
use Modules\Projects\Commands\CreateSalProgressCertificateCommand;
use Modules\Projects\Commands\GenerateTimeAndMaterialsInvoiceDraftCommand;
use Modules\Projects\Commands\ReleaseGuaranteeRetentionCommand;
use Modules\Projects\Commands\RunProjectWipRecognitionCommand;
use Modules\Projects\Commands\TopUpPrepaidRetainerCommand;
use Modules\Projects\Commands\UpsertProjectBillingContractCommand;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBillingContract;
use Modules\Projects\Models\ProjectMilestone;
use Modules\Projects\Models\ProjectRetainerLedgerEntry;
use Modules\Projects\Models\ProjectRetentionLedgerEntry;
use Modules\Projects\Models\ProjectSalCertificate;
use Modules\Projects\Models\ProjectWipValuationRun;

/**
 * Write side of project billing (Mod 081): handlers of the commands. Each handler loads the project of its command,
 * requires the billing contract where the operation needs one, and delegates the rules to a service.
 */
final readonly class ProjectBillingCommandHandler
{
    public function __construct(
        private ProjectBillingContractService $contracts,
        private ProjectTimeAndMaterialsBillingService $timeAndMaterials,
        private ProjectSalService $sal,
        private ProjectRetentionService $retention,
        private ProjectRetainerService $retainer,
        private ProjectMilestoneContractBillingService $milestones,
        private ProjectWipValuationService $wip,
    ) {}

    public function upsertContract(UpsertProjectBillingContractCommand $command): ProjectBillingContract
    {
        return $this->contracts->upsert($this->project($command->projectId), $command->attributes, $command->expectedVersion, $command->userId);
    }

    public function transitionContract(string $projectId, string $status, ?int $expectedVersion, string $userId): ProjectBillingContract
    {
        $project = $this->project($projectId);

        return $this->contracts->transition($project, $this->contract($project), $status, $expectedVersion, $userId);
    }

    public function generateTimeAndMaterialsInvoiceDraft(GenerateTimeAndMaterialsInvoiceDraftCommand $command): SalesInvoice
    {
        $project = $this->project($command->projectId);

        return $this->timeAndMaterials->draftInvoice($project, $this->contract($project), $command->upToDate, $command->taxRate, $command->userId);
    }

    public function billMilestone(BillContractMilestoneCommand $command): SalesInvoice
    {
        $project = $this->project($command->projectId);
        $milestone = ProjectMilestone::query()->where('project_id', $project->id)->findOrFail($command->milestoneId);

        return $this->milestones->bill($project, $this->contract($project), $milestone, $command->taxRate, $command->userId);
    }

    public function createSal(CreateSalProgressCertificateCommand $command): ProjectSalCertificate
    {
        $project = $this->project($command->projectId);

        return $this->sal->create($project, $this->contract($project), $command->periodStartDate, $command->periodEndDate, $command->cumulativeCompletionPercent, $command->notes, $command->userId);
    }

    public function submitSal(string $projectId, string $salId, string $userId): ProjectSalCertificate
    {
        $project = $this->project($projectId);

        return $this->sal->submit($project, $this->salOf($project, $salId), $userId);
    }

    public function approveAndInvoiceSal(ApproveAndInvoiceSalCommand $command): ProjectSalCertificate
    {
        $project = $this->project($command->projectId);

        return $this->sal->approveAndInvoice($project, $this->contract($project), $this->salOf($project, $command->salId), $command->taxRate, $command->approvedBy, $command->userId);
    }

    public function cancelSal(string $projectId, string $salId, ?string $reason, string $userId): ProjectSalCertificate
    {
        $project = $this->project($projectId);

        return $this->sal->cancel($project, $this->contract($project), $this->salOf($project, $salId), $reason, $userId);
    }

    public function releaseRetention(ReleaseGuaranteeRetentionCommand $command): ProjectRetentionLedgerEntry
    {
        $project = $this->project($command->projectId);

        return $this->retention->release($project, $this->contract($project), $command->amount, $command->releaseDate, $command->acceptanceConfirmed, $command->milestoneId, $command->reason, $command->userId);
    }

    public function topUpRetainer(TopUpPrepaidRetainerCommand $command): ProjectRetainerLedgerEntry
    {
        $project = $this->project($command->projectId);

        return $this->retainer->topUp($project, $this->contract($project), $command->hours, $command->amount, $command->date, $command->note, $command->userId);
    }

    /**
     * @return array{consumed_hours: string, consumed_amount: string, timesheet_ids: list<string>}
     */
    public function consumeRetainer(ConsumePrepaidRetainerCommand $command): array
    {
        $project = $this->project($command->projectId);

        return $this->retainer->consume($project, $this->contract($project), $command->upToDate, $command->userId);
    }

    /**
     * @return array{runs: list<array{run: ProjectWipValuationRun, replayed: bool}>, skipped: list<array{project_id: string, reason: string}>}
     */
    public function runWipRecognition(RunProjectWipRecognitionCommand $command): array
    {
        if ($command->projectId === null) {
            return $this->wip->runBatch($command->periodEndDate, $command->valuationMethod, $command->userId);
        }

        return ['runs' => [$this->wip->run($this->project($command->projectId), $command->periodEndDate, $command->valuationMethod, $command->userId)], 'skipped' => []];
    }

    private function project(string $projectId): Project
    {
        return Project::query()->findOrFail($projectId);
    }

    private function contract(Project $project): ProjectBillingContract
    {
        return ProjectBillingContract::query()->where('project_id', $project->id)->first()
            ?? throw (new ModelNotFoundException)->setModel(ProjectBillingContract::class, [$project->id]);
    }

    private function salOf(Project $project, string $salId): ProjectSalCertificate
    {
        return ProjectSalCertificate::query()->where('project_id', $project->id)->findOrFail($salId);
    }
}
