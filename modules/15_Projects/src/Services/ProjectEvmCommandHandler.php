<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Projects\Commands\AllocateOverheadToProjectsCommand;
use Modules\Projects\Commands\ComputeProjectEvmSnapshotCommand;
use Modules\Projects\Commands\SetManualEtcCommand;
use Modules\Projects\Commands\SyncProjectCostLedgerCommand;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectEtcOverride;
use Modules\Projects\Models\ProjectFinancialSnapshot;
use Modules\Projects\Models\ProjectTask;
use Modules\Projects\Support\Money;

/**
 * Write side of the profitability and earned value module (Mod 082): handlers of the four commands.
 */
final readonly class ProjectEvmCommandHandler
{
    public function __construct(
        private ProjectEvmSnapshotService $snapshots,
        private ProjectCostLedgerSyncService $ledger,
        private OverheadAllocationService $overhead,
    ) {}

    public function computeSnapshot(ComputeProjectEvmSnapshotCommand $command): ProjectFinancialSnapshot
    {
        return $this->snapshots->compute(Project::query()->findOrFail($command->projectId), $command->trigger, $command->asOf);
    }

    public function syncLedger(SyncProjectCostLedgerCommand $command): void
    {
        $this->ledger->sync(Project::query()->findOrFail($command->projectId));
    }

    /**
     * Allocates the pool, then recomputes the snapshot of every project that received a share.
     *
     * @return array{run_key: string, driver: string, pool_amount: string, allocated_amount: string, allocations: list<array{project_id: string, share_percent: string, amount: string}>}
     */
    public function allocateOverhead(AllocateOverheadToProjectsCommand $command): array
    {
        $result = $this->overhead->allocate($command);

        foreach ($result['allocations'] as $allocation) {
            $this->snapshots->compute(Project::query()->findOrFail($allocation['project_id']), 'overhead_allocation');
        }

        return $result;
    }

    /**
     * Sets or clears (null estimate) the manual ETC of the project or of one of its WBS nodes and recomputes the snapshot.
     *
     * @throws ValidationException when the node does not belong to the project
     */
    public function setManualEtc(SetManualEtcCommand $command): ProjectFinancialSnapshot
    {
        $project = Project::query()->findOrFail($command->projectId);

        if ($command->wbsNodeId !== null && ! ProjectTask::query()->where('project_id', $project->id)->whereKey($command->wbsNodeId)->exists()) {
            throw ValidationException::withMessages(['wbs_node_id' => [__('projects_errors.evm.node_not_in_project')]]);
        }

        DB::transaction(function () use ($command, $project): void {
            $existing = ProjectEtcOverride::query()->where('project_id', $project->id)
                ->when($command->wbsNodeId === null, fn ($query) => $query->whereNull('wbs_node_id'), fn ($query) => $query->where('wbs_node_id', $command->wbsNodeId));

            if ($command->estimateToComplete === null) {
                $existing->get()->each(static fn (ProjectEtcOverride $override): ?bool => $override->delete());

                return;
            }

            $override = $existing->first() ?? new ProjectEtcOverride(['tenant_id' => $project->tenant_id, 'company_id' => $project->company_id, 'project_id' => $project->id, 'wbs_node_id' => $command->wbsNodeId]);
            $override->fill(['estimate_to_complete' => Money::round($command->estimateToComplete, 4), 'reason' => $command->reason, 'set_by' => $command->userId])->save();
        });

        return $this->snapshots->compute($project, 'manual_etc');
    }
}
