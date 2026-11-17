<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\Access\SaveDelegationRequest;
use App\Http\Resources\Access\DelegationResource;
use App\Http\Responses\ApiResponse;
use App\Models\Access\AccessDelegation;
use App\Services\Tenancy\AccessDelegations;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Temporary delegations: for a period a member acts with the access of another one.
 */
class AccessDelegationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AccessDelegations $delegations,
    ) {}

    #[OA\Get(path: '/api/v1/settings/access/delegations', operationId: 'listAccessDelegations', description: 'Delegations of the active tenant with their status: active, scheduled, expired or revoked.', summary: 'List delegations', security: [['bearerAuth' => []]], tags: ['Settings', 'Access'], responses: [
        new OA\Response(response: 200, description: 'Delegations retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function index(): JsonResponse
    {
        return $this->successResponse(data: DelegationResource::collection($this->delegations->list($this->tenantContext->getTenant()))->resolve(), message: 'Delegations retrieved');
    }

    #[OA\Post(path: '/api/v1/settings/access/delegations', operationId: 'createAccessDelegation', description: 'The delegate acts with the access of the delegator between two dates, for every module or only the listed ones. The delegator is the signed-in user; an owner or administrator may name another one.', summary: 'Create a delegation', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['delegate_id', 'starts_at', 'ends_at'], properties: [
        new OA\Property(property: 'delegate_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'delegator_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'starts_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'ends_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'modules', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'reason', type: 'string'),
    ])), tags: ['Settings', 'Access'], responses: [
        new OA\Response(response: 201, description: 'Delegation created', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function store(SaveDelegationRequest $request): JsonResponse
    {
        try {
            $delegation = $this->delegations->create($this->tenantContext->getTenant(), $request->user(), $request->validated());
        } catch (DomainException $exception) {
            return $this->problemDetailsResponse(
                title: 'Delegation Refused',
                detail: $exception->getMessage(),
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
                invalidParams: [['name' => 'delegate_id', 'reason' => $exception->getMessage()]],
            );
        }

        return $this->successResponse(data: DelegationResource::make($delegation)->resolve(), message: 'Delegation created', statusCode: Response::HTTP_CREATED);
    }

    #[OA\Delete(path: '/api/v1/settings/access/delegations/{id}', operationId: 'revokeAccessDelegation', description: 'Ends a delegation at once; the record stays in the list as revoked.', summary: 'Revoke a delegation', security: [['bearerAuth' => []]], tags: ['Settings', 'Access'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Delegation revoked', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function destroy(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $this->delegations->revoke($tenant, AccessDelegation::query()->where('tenant_id', $tenant->id)->findOrFail($id));

        return $this->successResponse(message: 'Delegation revoked');
    }
}
