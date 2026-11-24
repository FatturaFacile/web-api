<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Controllers;

use App\Http\Controllers\Controller;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Modules\Workflow\Actions\RecordDecisionAction;
use Modules\Workflow\DTOs\RecordDecisionDTO;
use Modules\Workflow\Http\Requests\ConsumeRemoteTokenRequest;
use Modules\Workflow\Services\ExternalActionTokenService;
use OpenApi\Attributes as OA;

final class RemoteApprovalController extends Controller
{
    /**
     * Verifies and atomically consumes a single-use remote approval token, then records the decision it carries.
     */
    #[OA\Post(
        path: '/api/v1/workflows/remote-approval',
        operationId: 'consumeWorkflowsRemoteApproval',
        description: 'Verifies and atomically consumes a single-use remote approval token, then records the decision it carries. Public endpoint: the token is the credential.',
        summary: 'Consume remote approval token',
        security: [],
        tags: ['Workflow Remote Approval'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkflowConsumeRemoteTokenRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'step_id', type: 'string', format: 'uuid'), new OA\Property(property: 'status')])),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Consume cryptographic single-use remote approval token', tags: ['Workflow Engine'])]
    public function consume(
        ConsumeRemoteTokenRequest $request,
        ExternalActionTokenService $tokenService,
        RecordDecisionAction $decisionAction
    ): JsonResponse {
        $rawToken = $request->string('token')->value();
        $action = $request->string('action')->value();

        // 1. Verify and atomically consume single-use token
        $token = $tokenService->verifyAndConsumeToken($rawToken, $action);

        // 2. Record decision
        $dto = new RecordDecisionDTO(
            workflowInstanceStepId: $token->workflow_instance_step_id,
            decision: $action === 'approve' ? 'approved' : 'rejected',
            userId: $token->authorized_user_id,
            comment: $request->input('comment', 'Approved remotely via single-use action token.'),
            approvalTokenId: $token->id,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent()
        );

        $step = $decisionAction->execute($dto);

        return response()->json([
            'message' => "Workflow step successfully {$dto->decision} via remote token.",
            'step_id' => $step->id,
            'status' => $step->status,
        ]);
    }
}
