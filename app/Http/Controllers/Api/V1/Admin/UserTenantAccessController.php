<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\SodConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\SaveTenantMemberRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantMembershipService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform administrators decide to which tenants a user has access, with which role and module levels.
 */
class UserTenantAccessController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantMembershipService $memberships) {}

    #[OA\Get(
        path: '/api/v1/admin/access-options',
        operationId: 'adminGetAccessOptions',
        description: 'Roles that can be assigned, modules whose access can be configured per member, and the access levels.',
        summary: 'List assignable roles, modules and access levels',
        security: [['bearerAuth' => []]],
        tags: ['Platform Administration'],
        responses: [
            new OA\Response(response: 200, description: 'Options retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Not a platform administrator', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
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

    #[OA\Get(
        path: '/api/v1/admin/users',
        operationId: 'adminListUsers',
        description: 'Paginated users of the platform, searchable by name or email.',
        summary: 'List the users of the platform',
        security: [['bearerAuth' => []]],
        tags: ['Platform Administration'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string', maxLength: 100)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 25, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Users retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Not a platform administrator', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function users(Request $request): JsonResponse
    {
        $validated = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $search = $validated['search'] ?? null;

        $page = User::query()
            ->when($search, fn ($query) => $query->where(fn ($inner) => $inner->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('name')
            ->paginate((int) ($validated['per_page'] ?? 25), ['id', 'name', 'email', 'is_active']);

        return $this->successResponse(
            data: $page->items(),
            message: 'Users retrieved',
            meta: ['current_page' => $page->currentPage(), 'from' => $page->firstItem(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'to' => $page->lastItem(), 'total' => $page->total()],
        );
    }

    #[OA\Get(
        path: '/api/v1/admin/tenants',
        operationId: 'adminListTenants',
        description: 'Paginated tenants of the platform, searchable by name.',
        summary: 'List the tenants of the platform',
        security: [['bearerAuth' => []]],
        tags: ['Platform Administration'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string', maxLength: 100)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 50, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Tenants retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Not a platform administrator', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function tenants(Request $request): JsonResponse
    {
        $validated = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $search = $validated['search'] ?? null;

        $page = Tenant::query()
            ->when($search, fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate((int) ($validated['per_page'] ?? 50), ['id', 'name']);

        return $this->successResponse(data: $page->items(), message: 'Tenants retrieved');
    }

    #[OA\Get(
        path: '/api/v1/admin/users/{userId}/tenants',
        operationId: 'adminListUserTenants',
        description: 'The tenants a user can access, with the role and the module levels in each.',
        summary: 'List the tenant access of a user',
        security: [['bearerAuth' => []]],
        tags: ['Platform Administration'],
        parameters: [new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Tenant access retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Not a platform administrator', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Unknown user', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function userTenants(string $userId): JsonResponse
    {
        $user = User::query()->findOrFail($userId);

        return $this->successResponse(data: $this->memberships->tenantsOf($user), message: 'Tenant access retrieved');
    }

    #[OA\Put(
        path: '/api/v1/admin/users/{userId}/tenants/{tenantId}',
        operationId: 'adminSaveUserTenant',
        description: 'Gives a user access to a tenant or changes the role and the module levels of the existing access.',
        summary: 'Grant or change the tenant access of a user',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'role', type: 'string', example: 'member'),
                    new OA\Property(property: 'modules', type: 'object', example: ['crm' => 'read'], additionalProperties: new OA\AdditionalProperties(type: 'string', enum: ['default', 'blocked', 'read', 'write'])),
                ]
            )
        ),
        tags: ['Platform Administration'],
        parameters: [
            new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'tenantId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Tenant access saved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Not a platform administrator', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Unknown user or tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Last owner', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function saveUserTenant(SaveTenantMemberRequest $request, string $userId, string $tenantId): JsonResponse
    {
        $user = User::query()->findOrFail($userId);
        $tenant = Tenant::query()->findOrFail($tenantId);

        try {
            $this->memberships->save($tenant, $user, $request->validated('role'), $request->modules());
        } catch (SodConflictException $exception) {
            return $this->problemDetailsResponse(
                title: 'Segregation of Duties Conflict',
                detail: $exception->getMessage(),
                status: Response::HTTP_CONFLICT,
                invalidParams: array_map(static fn (array $conflict): array => ['name' => $conflict['rule_code'], 'reason' => $conflict['duty_a'].' / '.$conflict['duty_b']], $exception->conflicts),
            );
        } catch (DomainException $exception) {
            return $this->problemDetailsResponse(title: 'Last Owner', detail: $exception->getMessage(), status: Response::HTTP_CONFLICT);
        }

        return $this->successResponse(data: $this->memberships->tenantsOf($user), message: 'Tenant access saved');
    }

    #[OA\Delete(
        path: '/api/v1/admin/users/{userId}/tenants/{tenantId}',
        operationId: 'adminRemoveUserTenant',
        description: 'Removes the access of a user to a tenant; the user account stays.',
        summary: 'Remove the tenant access of a user',
        security: [['bearerAuth' => []]],
        tags: ['Platform Administration'],
        parameters: [
            new OA\Parameter(name: 'userId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'tenantId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Tenant access removed', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Not a platform administrator', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Unknown user or tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 409, description: 'Last owner', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function removeUserTenant(string $userId, string $tenantId): JsonResponse
    {
        $user = User::query()->findOrFail($userId);
        $tenant = Tenant::query()->findOrFail($tenantId);

        try {
            $this->memberships->remove($tenant, $user);
        } catch (DomainException $exception) {
            return $this->problemDetailsResponse(title: 'Last Owner', detail: $exception->getMessage(), status: Response::HTTP_CONFLICT);
        }

        return $this->successResponse(data: $this->memberships->tenantsOf($user), message: 'Tenant access removed');
    }
}
