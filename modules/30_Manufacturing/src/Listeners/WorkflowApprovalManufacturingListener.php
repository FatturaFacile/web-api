<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Listeners;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Modules\Manufacturing\Models\MfgEngineeringChangeOrder;
use Modules\Manufacturing\Services\EngineeringChangeService;
use Modules\Workflow\Events\WorkflowInstanceCompletedEvent;
use Modules\Workflow\Events\WorkflowInstanceRejectedEvent;
use Modules\Workflow\Models\WorkflowInstance;

/**
 * Applies the decision of an approval workflow started for an engineering change: approval or rejection in the name
 * of the last user who acted on the workflow instance. Only changes still submitted are touched, so a redelivered event
 * changes nothing; nothing else (production orders, BOM revisions) is ever released by a workflow event.
 */
final readonly class WorkflowApprovalManufacturingListener implements ShouldQueueAfterCommit
{
    public function __construct(
        private EngineeringChangeService $changes,
        private TenantContext $tenantContext,
    ) {}

    /**
     * Approves the submitted change of a completed workflow.
     */
    public function handleCompleted(WorkflowInstanceCompletedEvent $event): void
    {
        $this->withChange($event->instanceId, $event->tenantId, function (MfgEngineeringChangeOrder $eco, string $actor): void {
            $this->changes->approve($eco, $actor);
        });
    }

    /**
     * Rejects the submitted change of a rejected workflow.
     */
    public function handleRejected(WorkflowInstanceRejectedEvent $event): void
    {
        $this->withChange($event->instanceId, $event->tenantId, function (MfgEngineeringChangeOrder $eco, string $actor): void {
            $this->changes->reject($eco, $actor, 'Rejected by workflow');
        });
    }

    /**
     * Runs the decision in the tenant of the event on the submitted change the workflow instance approves.
     *
     * @param  callable(MfgEngineeringChangeOrder, string): void  $decide
     */
    private function withChange(string $instanceId, string $tenantId, callable $decide): void
    {
        $tenant = $tenantId !== '' ? Tenant::query()->find($tenantId) : null;
        $run = function () use ($instanceId, $decide): void {
            $instance = WorkflowInstance::query()->find($instanceId);
            if (! $instance instanceof WorkflowInstance || $instance->getAttribute('approvable_type') !== MfgEngineeringChangeOrder::class) {
                return;
            }

            $eco = MfgEngineeringChangeOrder::query()->find($instance->getAttribute('approvable_id'));
            if (! $eco instanceof MfgEngineeringChangeOrder || $eco->status !== 'submitted') {
                return;
            }

            $decide($eco, (string) ($instance->getAttribute('updated_by') ?? $instance->getAttribute('created_by') ?? ''));
        };

        $tenant !== null ? $this->tenantContext->runWithTenant($tenant, $run) : $run();
    }
}
