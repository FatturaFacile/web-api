<?php

declare(strict_types=1);

namespace App\Actions\Mobile;

use App\Models\MobileDeviceSession;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Modules\Workflow\Actions\RecordDecisionAction;
use Modules\Workflow\DTOs\RecordDecisionDTO;
use Modules\Workflow\Models\WorkflowInstanceStep;

/** Command: ExecuteQuickApprovalFromPush */
final class ExecuteQuickApprovalFromPushAction
{
    public function __construct(private readonly RecordDecisionAction $decisions) {}

    public function execute(
        User $user,
        MobileDeviceSession $device,
        string $stepId,
        string $decision,
        ?string $comment = null,
        ?string $idempotencyKey = null,
        ?string $ip = null,
    ): WorkflowInstanceStep {
        $step = WorkflowInstanceStep::findOrFail($stepId);
        Gate::forUser($user)->authorize('decide', $step);

        return $this->decisions->execute(new RecordDecisionDTO(
            workflowInstanceStepId: $stepId,
            decision: $decision,
            userId: $user->id,
            comment: $comment,
            idempotencyKey: $idempotencyKey,
            ipAddress: $ip,
            userAgent: 'mobile:'.$device->platform.':'.$device->device_id,
        ));
    }
}
