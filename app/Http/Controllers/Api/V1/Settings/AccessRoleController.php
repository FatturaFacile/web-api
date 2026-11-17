<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\Access\SaveCustomRoleRequest;
use App\Http\Resources\Access\CustomRoleResource;
use App\Http\Responses\ApiResponse;
use App\Models\Access\TenantCustomRole;
use App\Services\Tenancy\CustomRoleService;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Custom roles of the tenant (a system role plus module levels) and the matrix of what each role can read and write.
 */
class AccessRoleController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CustomRoleService $roles,
    ) {}

    #[OA\Get(path: '/api/v1/settings/access/roles', operationId: 'listCustomRoles', description: 'Custom roles of the active tenant with the number of members that hold each, the system roles they can be based on, the modules and the access levels.', summary: 'List custom roles', security: [['bearerAuth' => []]], tags: ['Settings', 'Access'], responses: [
        new OA\Response(response: 200, description: 'Roles retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function index(): JsonResponse
    {
        $overview = $this->roles->overview($this->tenantContext->getTenant());
        $overview['roles'] = CustomRoleResource::collection($overview['roles'])->resolve();

        return $this->successResponse(data: $overview, message: 'Custom roles retrieved');
    }

    #[OA\Post(path: '/api/v1/settings/access/roles', operationId: 'createCustomRole', description: 'Creates a role based on a system role with module levels (blocked, read, write). The plan limits how many custom roles a tenant has.', summary: 'Create a custom role', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['name', 'base_role'], properties: [
        new OA\Property(property: 'name', type: 'string', example: 'Shop floor lead'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'base_role', type: 'string', example: 'member'),
        new OA\Property(property: 'modules', type: 'object', example: ['treasury' => 'blocked'], additionalProperties: new OA\AdditionalProperties(type: 'string', enum: ['default', 'blocked', 'read', 'write'])),
    ])), tags: ['Settings', 'Access'], responses: [
        new OA\Response(response: 201, description: 'Role created', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error or plan limit', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function store(SaveCustomRoleRequest $request): JsonResponse
    {
        $role = $this->roles->create($this->tenantContext->getTenant(), $request->validated());

        return $this->successResponse(data: CustomRoleResource::make($role)->resolve(), message: 'Custom role created', statusCode: Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/settings/access/roles/{id}', operationId: 'updateCustomRole', description: 'Changes a custom role and writes the new base role and module levels to every member that holds it.', summary: 'Update a custom role', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'base_role', type: 'string'),
        new OA\Property(property: 'modules', type: 'object', additionalProperties: new OA\AdditionalProperties(type: 'string', enum: ['default', 'blocked', 'read', 'write'])),
    ])), tags: ['Settings', 'Access'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Role updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 409, description: 'Would leave the tenant without an owner', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function update(SaveCustomRoleRequest $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $role = TenantCustomRole::query()->where('tenant_id', $tenant->id)->findOrFail($id);

        try {
            $role = $this->roles->update($tenant, $role, $request->validated());
        } catch (DomainException $exception) {
            return $this->problemDetailsResponse(title: 'Last Owner', detail: $exception->getMessage(), status: Response::HTTP_CONFLICT);
        }

        return $this->successResponse(data: CustomRoleResource::make($role)->resolve(), message: 'Custom role updated');
    }

    #[OA\Delete(path: '/api/v1/settings/access/roles/{id}', operationId: 'deleteCustomRole', description: 'Deletes a custom role that no member holds.', summary: 'Delete a custom role', security: [['bearerAuth' => []]], tags: ['Settings', 'Access'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Role deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 409, description: 'Members still hold the role', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function destroy(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $role = TenantCustomRole::query()->where('tenant_id', $tenant->id)->findOrFail($id);

        try {
            $this->roles->delete($tenant, $role);
        } catch (DomainException $exception) {
            return $this->problemDetailsResponse(title: 'Role In Use', detail: $exception->getMessage(), status: Response::HTTP_CONFLICT);
        }

        return $this->successResponse(message: 'Custom role deleted');
    }

    #[OA\Get(path: '/api/v1/settings/access/matrix', operationId: 'getAccessMatrix', description: 'Read and write access of every system and custom role to every module.', summary: 'Role and module matrix', security: [['bearerAuth' => []]], tags: ['Settings', 'Access'], responses: [
        new OA\Response(response: 200, description: 'Matrix retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function matrix(): JsonResponse
    {
        return $this->successResponse(data: $this->roles->matrix($this->tenantContext->getTenant()), message: 'Access matrix retrieved');
    }
}
