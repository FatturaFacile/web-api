<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Workflow\Actions\RecordDecisionAction;
use Modules\Workflow\DTOs\RecordDecisionDTO;
use Modules\Workflow\Http\Requests\RecordDecisionRequest;
use Modules\Workflow\Http\Resources\WorkflowInstanceStepResource;
use Modules\Workflow\Models\WorkflowInstanceStep;
use Modules\Workflow\Services\WorkflowInboxService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class WorkflowDecisionController extends Controller
{
    /**
     * Returns the pending workflow steps assigned to the authenticated user, directly or through one of the user roles.
     */
    #[OA\Get(
        path: '/api/v1/workflows/my-pending',
        operationId: 'getMyPendingWorkflow',
        description: 'Deprecated: use `GET /workflows/my-tasks`, which also lists the steps of colleagues who delegated to you and carries the deadline state. Returns the pending workflow steps assigned to the authenticated user directly or through the role the user holds in the active company (the same list as my-tasks without filters).',
        summary: 'List my pending approvals',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow My Pending'],
        deprecated: true,
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', default: 25)),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkflowInstanceStepResource')), new OA\Property(property: 'links', type: 'object'), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Retrieve user pending approvals worklist', tags: ['Workflow Engine'])]
    public function myPending(Request $request, WorkflowInboxService $inbox): JsonResponse
    {
        $steps = $inbox->tasks($request->user())->paginate(min(100, max(1, (int) $request->query('per_page', 25))));

        return response()->json(WorkflowInstanceStepResource::collection($steps)->response()->getData(true));
    }

    /**
     * Records the approval decision of the authenticated user on a workflow step.
     */
    #[OA\Post(
        path: '/api/v1/workflows/steps/{stepId}/decide',
        operationId: 'decideWorkflowsStep',
        description: 'Records the approval decision of the authenticated user on a workflow step. The idempotency key makes retries safe.',
        summary: 'Decide workflow step',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Steps'],
        parameters: [
            new OA\Parameter(name: 'stepId', in: 'path', required: true, description: 'Identifier of the step', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkflowRecordDecisionRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(ref: '#/components/schemas/WorkflowInstanceStepResource')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
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

        try {
            $step = $action->execute($dto);
        } catch (InvalidArgumentException $exception) {
            // The step was already decided, skipped or timed out, or its instance ended: nothing left to decide.
            throw new ConflictHttpException($exception->getMessage(), $exception);
        }

        return response()->json(new WorkflowInstanceStepResource($step));
    }
}
