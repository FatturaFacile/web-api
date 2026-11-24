<?php

declare(strict_types=1);

namespace Modules\Workflow\Actions;

use App\Models\User;
use Modules\Workflow\Events\ApprovalTokenGeneratedEvent;
use Modules\Workflow\Models\WorkflowInstanceStep;
use Modules\Workflow\Services\ExternalActionTokenService;

final class GenerateRemoteApprovalTokensAction
{
    public function __construct(
        private readonly ExternalActionTokenService $tokenService
    ) {}

    /**
     * @return array{approve_url: string, reject_url: string}
     */
    public function execute(WorkflowInstanceStep $step, User $user): array
    {
        $approveUrl = $this->tokenService->generateActionToken($step, $user, 'approve');
        $rejectUrl = $this->tokenService->generateActionToken($step, $user, 'reject');

        event(new ApprovalTokenGeneratedEvent(
            tokenId: (string) $step->tokens()->latest()->first()?->id,
            stepId: $step->id,
            actionType: 'approve',
            actionUrl: $approveUrl,
            authorizedUserId: $user->id,
            tenantId: $step->tenant_id,
            companyId: (string) ($step->instance->company_id ?? $step->tenant_id)
        ));

        return [
            'approve_url' => $approveUrl,
            'reject_url' => $rejectUrl,
        ];
    }
}
