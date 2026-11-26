<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Workflow\Actions\RecordDecisionAction;
use Modules\Workflow\DTOs\RecordDecisionDTO;
use Modules\Workflow\Exceptions\ApprovalTokenException;
use Modules\Workflow\Exceptions\StepAlreadyDecidedException;
use Modules\Workflow\Http\Requests\ConfirmRemoteApprovalRequest;
use Modules\Workflow\Http\Requests\ConsumeRemoteTokenRequest;
use Modules\Workflow\Models\WorkflowInstanceStep;
use Modules\Workflow\Services\ApprovableRegistry;
use Modules\Workflow\Services\ApprovalGuardService;
use Modules\Workflow\Services\ExternalActionTokenService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * One-click approval from email, push and Teams/Slack cards. The link opens a confirmation page ({@see show()}, which
 * changes nothing) and the decision is taken on confirmation ({@see confirm()}), with a fresh sign-in above the amount
 * threshold. A used or expired link answers 410, a link of another user 403.
 */
final class RemoteApprovalController extends Controller
{
    use ApiResponse;

    /**
     * What the confirmation page shows, without consuming the token.
     */
    #[OA\Get(
        path: '/api/v1/workflows/remote-approval/{token}',
        operationId: 'showWorkflowsRemoteApproval',
        description: 'Reads a one-click approval token for the confirmation page: what is asked, the amount, the requester and whether a re-authentication is required. Nothing is decided and the token stays usable. Public endpoint: the token is the credential.',
        summary: 'Preview a remote approval token',
        security: [],
        tags: ['Workflow Remote Approval'],
        parameters: [
            new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'action', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['approve', 'reject'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Preview', content: new OA\JsonContent(ref: '#/components/schemas/WorkflowRemoteApprovalPreview')),
            new OA\Response(response: 403, description: 'The link was issued to another user', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 410, description: 'The link is unknown, used or expired', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Preview a remote approval token', tags: ['Workflow Engine'])]
    public function show(Request $request, string $token, ExternalActionTokenService $tokens, ApprovableRegistry $registry, ApprovalGuardService $guard): JsonResponse
    {
        $record = $tokens->peek($token, $this->signedInUser($request), $request->query('action') !== null ? (string) $request->query('action') : null);
        /** @var WorkflowInstanceStep $step */
        $step = $record->step()->with(['instance.approvable'])->firstOrFail();
        $instance = $step->instance;
        $approvable = $instance->approvable;
        $attributes = $approvable === null ? [] : $registry->attributesOf($approvable);
        $requesterId = $guard->requesterOf($instance);

        return $this->successResponse([
            'requires_reauth' => (bool) $record->requires_reauth,
            'subject_label' => $registry->labelOf($approvable),
            'subject_type' => $registry->resolve((string) $instance->approvable_type),
            'amount' => $guard->amountOf($instance),
            'currency' => $attributes['currency'] ?? null,
            'requester_name' => $requesterId === null ? null : User::query()->whereKey($requesterId)->value('name'),
            'approver_name' => User::query()->whereKey($record->authorized_user_id)->value('name'),
            'action' => (string) $record->action_type,
            'expires_at' => $record->expires_at->toIso8601String(),
        ], 'Remote approval token read');
    }

    /**
     * Takes the decision after the approver confirmed it on the confirmation page.
     */
    #[OA\Post(
        path: '/api/v1/workflows/remote-approval/{token}/confirm',
        operationId: 'confirmWorkflowsRemoteApproval',
        description: 'Consumes the single-use token and records the decision it carries, after explicit confirmation. Above the configured amount the approver must be signed in as the token user and send the password (or the two-factor code). Public endpoint: the token is the credential.',
        summary: 'Confirm a remote approval',
        security: [],
        tags: ['Workflow Remote Approval'],
        parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkflowConfirmRemoteApprovalRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Decision recorded', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'step_id', type: 'string', format: 'uuid'), new OA\Property(property: 'status')])),
            new OA\Response(response: 403, description: 'Another user, or re-authentication missing or wrong', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'The step was already decided', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 410, description: 'The link is unknown, used or expired', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Confirm a remote approval', tags: ['Workflow Engine'])]
    public function confirm(ConfirmRemoteApprovalRequest $request, string $token, ExternalActionTokenService $tokens, RecordDecisionAction $decisions): JsonResponse
    {
        return $this->decide($request, $token, $request->string('action')->value(), $tokens, $decisions);
    }

    /**
     * Legacy entry point of the signed link: the same confirmation rules apply (`confirm` must be sent).
     */
    #[OA\Post(
        path: '/api/v1/workflows/remote-approval',
        operationId: 'consumeWorkflowsRemoteApproval',
        description: 'Verifies and atomically consumes a single-use remote approval token, then records the decision it carries. The decision must be confirmed (`confirm`), and amounts above the threshold need a fresh sign-in. Public endpoint: the token is the credential.',
        summary: 'Consume remote approval token',
        security: [],
        tags: ['Workflow Remote Approval'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkflowConsumeRemoteTokenRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'step_id', type: 'string', format: 'uuid'), new OA\Property(property: 'status')])),
            new OA\Response(response: 403, description: 'Another user, or re-authentication missing or wrong', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 410, description: 'The link is unknown, used or expired', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Consume cryptographic single-use remote approval token', tags: ['Workflow Engine'])]
    public function consume(ConsumeRemoteTokenRequest $request, ExternalActionTokenService $tokens, RecordDecisionAction $decisions): JsonResponse
    {
        return $this->decide($request, $request->string('token')->value(), $request->string('action')->value(), $tokens, $decisions);
    }

    private function decide(Request $request, string $rawToken, string $action, ExternalActionTokenService $tokens, RecordDecisionAction $decisions): JsonResponse
    {
        if (! $request->boolean('confirm')) {
            throw ApprovalTokenException::confirmationRequired();
        }

        // The token selects the tenant: the engine reads and writes inside it.
        $peek = $tokens->peek($rawToken, $this->signedInUser($request), $action);
        $tenant = Tenant::query()->find($peek->tenant_id);
        app(TenantContext::class)->setTenant($tenant);

        $token = $tokens->verifyAndConsumeToken($rawToken, $action, $this->signedInUser($request), $request->input('password'), $request->input('otp'));

        $dto = new RecordDecisionDTO(
            workflowInstanceStepId: $token->workflow_instance_step_id,
            decision: $action === 'approve' ? 'approved' : 'rejected',
            userId: $token->authorized_user_id,
            comment: $request->input('comment', 'Approved via single-use action token.'),
            approvalTokenId: $token->id,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent()
        );

        try {
            $step = $decisions->execute($dto);
        } catch (StepAlreadyDecidedException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage(), 'data' => $exception->existingDecision()], 409);
        } catch (InvalidArgumentException $exception) {
            throw new ConflictHttpException($exception->getMessage(), $exception);
        }

        return response()->json([
            'message' => "Workflow step successfully {$dto->decision} via remote token.",
            'step_id' => $step->id,
            'status' => $step->status,
        ]);
    }

    /**
     * The user signed in on the confirmation page, when there is one (session cookie or bearer token).
     */
    private function signedInUser(Request $request): ?User
    {
        $user = $request->user('sanctum');

        return $user instanceof User ? $user : null;
    }
}
