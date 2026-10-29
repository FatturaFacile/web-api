<?php

declare(strict_types=1);

namespace Modules\Projects\Actions;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Modules\Projects\Events\ProjectClosedEvent;
use Modules\Projects\Events\ProjectClosureReadyEvent;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectAuditEntry;
use Modules\Workflow\Contracts\WorkflowEngineInterface;
use Modules\Workflow\DTOs\StartWorkflowDTO;

final readonly class CloseProjectAction
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger,
        private WorkflowEngineInterface $workflowEngine
    ) {}

    public function execute(Project $project, string $userId, ?string $reason = null): Project
    {
        return DB::transaction(function () use ($project, $userId, $reason): Project {
            $project->loadMissing('financialSummary');
            $summary = $project->financialSummary;

            $finalCost = (float) ($summary?->total_actual_cost ?? 0.0);
            $finalContractValue = (float) $project->contract_value;
            $retainedEarnings = $finalContractValue - $finalCost;

            // Threshold: Projects with value > €5,000 require formal Workflow Engine V2 closure approval
            $requiresApproval = $finalContractValue > 5000.00;

            if ($requiresApproval) {
                try {
                    $this->workflowEngine->start(new StartWorkflowDTO(
                        workflowCode: 'PROJECT_CLOSURE_APPROVAL',
                        approvable: $project,
                        contextSnapshot: [
                            'project_id' => $project->id,
                            'project_number' => $project->project_number,
                            'contract_value' => $finalContractValue,
                            'final_cost' => $finalCost,
                            'closure_reason' => $reason,
                        ],
                        tenantId: (string) $project->tenant_id,
                        companyId: (string) ($project->company_id ?? $project->tenant_id),
                        initiatorUserId: $userId
                    ));

                    return $project;
                } catch (\Throwable $e) {
                    logger()->warning('Workflow engine could not start PROJECT_CLOSURE_APPROVAL: '.$e->getMessage());
                }
            }

            $project->update([
                'status' => 'closed',
                'actual_end_date' => now()->toDateString(),
            ]);

            // Emit Domain Events
            $this->dispatcher->publish(new ProjectClosedEvent(
                projectId: (string) $project->id,
                projectNumber: (string) $project->project_number,
                closedBy: $userId,
                closureReason: $reason ?? 'Direct closure approved',
                tenantId: (string) $project->tenant_id,
                companyId: (string) ($project->company_id ?? $project->tenant_id)
            ));

            $this->dispatcher->publish(new ProjectClosureReadyEvent(
                projectId: (string) $project->id,
                finalContractValue: $finalContractValue,
                finalCost: $finalCost,
                unbilledWipBalance: 0.00,
                retainedEarningsImpact: $retainedEarnings,
                fiscalYear: now()->format('Y'),
                tenantId: (string) $project->tenant_id,
                companyId: (string) ($project->company_id ?? $project->tenant_id)
            ));

            // Audit
            ProjectAuditEntry::create([
                'tenant_id' => $project->tenant_id,
                'company_id' => $project->company_id,
                'project_id' => $project->id,
                'actor_user_id' => $userId,
                'event_type' => 'project_closed',
                'description' => "Closed project {$project->project_number} with final cost €{$finalCost}",
                'before_state' => ['status' => $project->getOriginal('status')],
                'after_state' => $project->toArray(),
            ]);

            $this->auditLedger->record(
                action: 'updated',
                model: $project,
                payloadBefore: ['status' => $project->getOriginal('status')],
                payloadAfter: $project->toArray(),
                moduleCode: 'MODULE_15_PROJECTS'
            );

            return $project;
        });
    }
}
