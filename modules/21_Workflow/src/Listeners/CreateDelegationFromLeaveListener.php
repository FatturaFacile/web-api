<?php

declare(strict_types=1);

namespace Modules\Workflow\Listeners;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\HumanResources\Models\Employee;
use Modules\LeaveManagement\Domain\Events\LeaveAbsenceApprovedEvent;
use Modules\Workflow\Actions\CreateDelegationAction;
use Modules\Workflow\DTOs\CreateDelegationDTO;
use Modules\Workflow\Models\WorkflowDelegation;
use Modules\Workflow\Services\WorkflowAssigneeResolver;
use Throwable;

/**
 * When an approved absence (Mod 074) starts for somebody who approves things, their reporting manager stands in for the
 * whole absence, if the tenant turned this on (`config('workflow.delegation_from_leave')`). The delegation is created
 * once per leave request and is a normal delegation: the manager sees the approvals, the audit trail names both.
 */
final readonly class CreateDelegationFromLeaveListener
{
    /**
     * Inject the collaborators.
     */
    public function __construct(private CreateDelegationAction $delegations, private WorkflowAssigneeResolver $assignees) {}

    /**
     * Creates the delegation for an approved absence.
     */
    public function handle(LeaveAbsenceApprovedEvent $event): void
    {
        if (! (bool) config('workflow.delegation_from_leave', false)) {
            return;
        }

        try {
            if (app(TenantContext::class)->getTenantId() === null) {
                app(TenantContext::class)->setTenant(Tenant::query()->find($event->tenantId));
            }

            $employee = Employee::withoutGlobalScopes()->where('tenant_id', $event->tenantId)->whereKey($event->employeeId)->first();
            $userId = $employee?->user_id !== null ? (string) $employee->user_id : null;

            if ($userId === null || WorkflowDelegation::withoutGlobalScopes()->where('tenant_id', $event->tenantId)->where('source_type', 'leave_request')->where('source_id', $event->leaveRequestId)->exists()) {
                return;
            }

            $delegate = $this->assignees->managerUserIdOf($userId, $event->tenantId);

            if ($delegate === null) {
                return;
            }

            $this->delegations->execute(new CreateDelegationDTO(
                delegatorUserId: $userId,
                delegateUserId: $delegate,
                startsAt: Carbon::parse($event->startDate)->startOfDay(),
                endsAt: Carbon::parse($event->endDate)->endOfDay(),
                scope: 'all',
                reason: 'Leave '.$event->requestNumber,
                tenantId: $event->tenantId,
                sourceType: 'leave_request',
                sourceId: $event->leaveRequestId,
            ));
        } catch (ValidationException) {
            // An overlapping or circular delegation exists already: the approver keeps what they set up themselves.
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
