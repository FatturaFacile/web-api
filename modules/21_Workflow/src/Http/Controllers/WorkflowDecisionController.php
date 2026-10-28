<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Workflow\Actions\RecordDecisionAction;
use Modules\Workflow\DTOs\RecordDecisionDTO;
use Modules\Workflow\Http\Requests\RecordDecisionRequest;
use Modules\Workflow\Http\Resources\WorkflowInstanceStepResource;
use Modules\Workflow\Models\WorkflowInstanceStep;

final class WorkflowDecisionController extends Controller
{
    #[EnterpriseEndpoint(summary: 'Retrieve user pending approvals worklist', tags: ['Workflow Engine'])]
    public function myPending(Request $request): JsonResponse
    {
        $userId = auth()->id();

        $steps = WorkflowInstanceStep::where('status', 'pending')
            ->where(function ($q) use ($userId): void {
                $q->where('assigned_user_id', $userId)
                    ->orWhereIn('assigned_role', auth()->user()?->roles->pluck('name') ?? []);
            })
            ->with(['instance.approvable', 'node'])
            ->orderBy('due_at', 'asc')
            ->paginate((int) $request->query('per_page', 25));

        return response()->json(WorkflowInstanceStepResource::collection($steps)->response()->getData(true));
    }

    #[EnterpriseEndpoint(summary: 'Submit approval decision on a workflow step', tags: ['Workflow Engine'])]
    public function decide(string $stepId, RecordDecisionRequest $request, RecordDecisionAction $action): JsonResponse
    {
        $step = WorkflowInstanceStep::findOrFail($stepId);
        $this->authorize('decide', $step);

        $dto = new RecordDecisionDTO(
            workflowInstanceStepId: $stepId,
            decision: $request->string('decision')->value(),
            userId: (string) auth()->id(),
            comment: $request->input('comment'),
            idempotencyKey: $request->input('idempotency_key'),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            signaturePayload: $request->input('signature_payload')
        );

        $step = $action->execute($dto);

        return response()->json(new WorkflowInstanceStepResource($step));
    }
}
