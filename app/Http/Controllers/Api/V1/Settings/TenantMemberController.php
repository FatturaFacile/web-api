<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\SaveTenantMemberRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantMembershipService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Owners and administrators decide who belongs to their tenant, with which role and which module access.
 */
class TenantMemberController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantMembershipService $memberships,
    ) {}

    #[OA\Get(
        path: '/api/v1/settings/access-options',
        operationId: 'getAccessOptions',
        description: 'Roles that can be assigned, modules whose access can be configured per member, and the access levels.',
        summary: 'List assignable roles, modules and access levels',
        security: [['bearerAuth' => []]],
        tags: ['Settings', 'Users'],
        responses: [
            new OA\Response(response: 200, description: 'Options retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function options(): JsonResponse
    {
        return $this->successResponse(
            data: [
                'roles' => $this->memberships->roles(),
                'modules' => $this->memberships->modules(),
                'levels' => $this->memberships->levels(),
            ],
            message: 'Access options retrieved'
        );
    }

    #[OA\Post(
        path: '/api/v1/settings/users',
        operationId: 'addTenantUser',
        description: 'Gives an already registered user access to the active tenant, with a role and optional module levels.',
        summary: 'Add a member to the tenant',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'role', type: 'string', example: 'member'),
                    new OA\Property(property: 'modules', type: 'object', example: ['crm' => 'read', 'treasury' => 'blocked'], additionalProperties: new OA\AdditionalProperties(type: 'string', enum: ['default', 'blocked', 'read', 'write'])),
                ]
            )
        ),
        tags: ['Settings', 'Users'],
        responses: [
            new OA\Response(response: 201, description: 'Member added', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Already a member', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(SaveTenantMemberRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->validated('email'))->first();

        if ($user === null) {
            return $this->problemDetailsResponse(
                title: 'Unknown User',
                detail: 'No registered user has this email address.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
                invalidParams: [['name' => 'email', 'reason' => 'No registered user has this email address.']],
            );
        }

        if ($this->tenantContext->getTenant()->users()->where('users.id', $user->id)->exists()) {
            return $this->problemDetailsResponse(
                title: 'Already A Member',
                detail: 'This user already belongs to the tenant.',
                status: Response::HTTP_CONFLICT,
            );
        }

        return $this->saveMember($request, $user, Response::HTTP_CREATED);
    }

    #[OA\Put(
        path: '/api/v1/settings/users/{userId}',
        operationId: 'updateTenantUser',
        description: 'Changes the role and the module levels of a member of the active tenant.',
        summary: 'Update a member of the tenant',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'role', type: 'string', example: 'accountant'),
                    new OA\Property(property: 'modules', type: 'object', example: ['crm' => 'write'], additionalProperties: new OA\AdditionalProperties(type: 'string', enum: ['default', 'blocked', 'read', 'write'])),
                ]
            )
        ),
        tags: ['Settings', 'Users'],
        parameters: [new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Member updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not a member', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Last owner or own membership', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(SaveTenantMemberRequest $request, string $userId): JsonResponse
    {
        $user = $this->tenantContext->getTenant()->users()->where('users.id', $userId)->firstOrFail();

        return $this->saveMember($request, $user, Response::HTTP_OK);
    }

    #[OA\Delete(
        path: '/api/v1/settings/users/{userId}',
        operationId: 'removeTenantUser',
        description: 'Removes the access of a member to the active tenant; the user account stays.',
        summary: 'Remove a member from the tenant',
        security: [['bearerAuth' => []]],
        tags: ['Settings', 'Users'],
        parameters: [new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Member removed', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not a member', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Last owner or own membership', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(Request $request, string $userId): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $user = $tenant->users()->where('users.id', $userId)->firstOrFail();

        if ($refusal = $this->refuseSelfOrOwnerChange($request, $user)) {
            return $refusal;
        }

        try {
            $this->memberships->remove($tenant, $user);
        } catch (DomainException $exception) {
            return $this->problemDetailsResponse(title: 'Last Owner', detail: $exception->getMessage(), status: Response::HTTP_CONFLICT);
        }

        return $this->successResponse(message: 'Member removed from the tenant');
    }

    private function saveMember(SaveTenantMemberRequest $request, User $user, int $status): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $role = $request->validated('role');

        if ($status === Response::HTTP_OK && ($refusal = $this->refuseSelfOrOwnerChange($request, $user))) {
            return $refusal;
        }

        if ($role === 'owner' && ! $this->actorIsOwner($request)) {
            return $this->problemDetailsResponse(title: 'Owner Role Reserved', detail: 'Only an owner can grant the owner role.', status: Response::HTTP_FORBIDDEN);
        }

        try {
            $this->memberships->save($tenant, $user, $role, $request->modules());
        } catch (DomainException $exception) {
            return $this->problemDetailsResponse(title: 'Last Owner', detail: $exception->getMessage(), status: Response::HTTP_CONFLICT);
        }

        $member = collect($this->memberships->members($tenant))->firstWhere('id', $user->id);

        return $this->successResponse(data: $member, message: 'Member saved', statusCode: $status);
    }

    /**
     * Nobody changes their own membership (a lock-out), and only an owner touches the membership of an owner.
     */
    private function refuseSelfOrOwnerChange(Request $request, User $member): ?JsonResponse
    {
        if ($member->is($request->user())) {
            return $this->problemDetailsResponse(title: 'Own Membership', detail: 'You cannot change your own membership.', status: Response::HTTP_CONFLICT);
        }

        if ($member->pivot?->role === 'owner' && ! $this->actorIsOwner($request)) {
            return $this->problemDetailsResponse(title: 'Owner Role Reserved', detail: 'Only an owner can change the membership of an owner.', status: Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    private function actorIsOwner(Request $request): bool
    {
        /** @var User $actor */
        $actor = $request->user();

        return $actor->is_platform_admin || $actor->getRoleInTenant((string) $this->tenantContext->getTenantId()) === 'owner';
    }
}
