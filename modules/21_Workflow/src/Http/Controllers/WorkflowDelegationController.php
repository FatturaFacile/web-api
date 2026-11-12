<?php

declare(strict_types=1);

namespace Modules\Workflow\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Carbon\Carbon;
use Enterprise\OpenApi\Attributes\EnterpriseEndpoint;
use Illuminate\Http\JsonResponse;
use Modules\Workflow\Actions\CreateDelegationAction;
use Modules\Workflow\DTOs\CreateDelegationDTO;
use Modules\Workflow\Http\Requests\CreateDelegationRequest;
use Modules\Workflow\Models\WorkflowDelegation;
use OpenApi\Attributes as OA;

final class WorkflowDelegationController extends Controller
{
    use ApiResponse;

    /**
     * Returns the list of delegations of the active tenant.
     */
    #[OA\Get(
        path: '/api/v1/workflows/delegations',
        operationId: 'listWorkflowsDelegations',
        description: 'Returns the list of delegations of the active tenant.',
        summary: 'List active user delegations',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Delegations'],
        responses: [
            new OA\Response(response: 200, description: 'List of delegations', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'List active user delegations', tags: ['Workflow Engine'])]
    public function index(): JsonResponse
    {
        $userId = auth()->id();

        $delegations = WorkflowDelegation::where('delegator_user_id', $userId)
            ->orWhere('delegate_user_id', $userId)
            ->with(['delegator', 'delegate'])
            ->orderBy('starts_at', 'desc')
            ->get();

        return $this->successResponse($delegations, 'Delegations retrieved');
    }

    /**
     * Creates a new delegation in the active tenant.
     */
    #[OA\Post(
        path: '/api/v1/workflows/delegations',
        operationId: 'createWorkflowsDelegation',
        description: 'Creates a new delegation in the active tenant.',
        summary: 'Create a new out-of-office workflow delegation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Delegations'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkflowCreateDelegationRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Delegation created', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Create a new out-of-office workflow delegation', tags: ['Workflow Engine'])]
    public function store(CreateDelegationRequest $request, CreateDelegationAction $action): JsonResponse
    {
        $dto = new CreateDelegationDTO(
            delegatorUserId: (string) auth()->id(),
            delegateUserId: $request->string('delegate_user_id')->value(),
            startsAt: Carbon::parse($request->string('starts_at')->value()),
            endsAt: Carbon::parse($request->string('ends_at')->value()),
            scope: $request->string('scope', 'all')->value(),
            reason: $request->input('reason')
        );

        $delegation = $action->execute($dto);

        return $this->successResponse($delegation, 'Delegation created', 201);
    }

    /**
     * Deletes a delegation of the active tenant.
     */
    #[OA\Delete(
        path: '/api/v1/workflows/delegations/{id}',
        operationId: 'deleteWorkflowsDelegation',
        description: 'Deletes a delegation of the active tenant.',
        summary: 'Revoke an existing workflow delegation',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Workflow Delegations'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the delegation', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Request completed successfully', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'message', type: 'string')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    #[EnterpriseEndpoint(summary: 'Revoke an existing workflow delegation', tags: ['Workflow Engine'])]
    public function destroy(string $id): JsonResponse
    {
        $delegation = WorkflowDelegation::where('delegator_user_id', auth()->id())->findOrFail($id);
        $delegation->update(['is_active' => false]);

        return response()->json(['message' => 'Delegation successfully revoked.']);
    }
}
