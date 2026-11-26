<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ListScimLogsRequest;
use App\Http\Requests\Auth\UpsertScimGroupMappingRequest;
use App\Http\Resources\Auth\ScimGroupMappingResource;
use App\Http\Resources\Auth\ScimProvisioningLogResource;
use App\Http\Responses\ApiResponse;
use App\Models\ScimGroupMapping;
use App\Models\ScimProvisioningLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\Scim\ScimGroupMappings;
use App\Services\Auth\Sso\ProvisionableRoles;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * The SCIM group to role mappings of the active tenant and the log of the SCIM operations of its identity provider.
 * Reserved to owners and administrators (module `system`).
 */
class ScimGroupSettingsController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ScimGroupMappings $mappings,
    ) {}

    #[OA\Get(
        path: '/api/v1/settings/security/scim/group-mappings',
        operationId: 'listScimGroupMappings',
        description: 'The mappings of SCIM group names to tenant roles, with the number of members each group has now, and the roles a mapping may grant (meta.roles; the owner is never one of them).',
        summary: 'List SCIM group to role mappings',
        security: [['bearerAuth' => []]],
        tags: ['Settings'],
        responses: [
            new OA\Response(response: 200, description: 'The mappings', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ScimGroupMapping'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and administrators', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(): JsonResponse
    {
        $tenantId = (string) $this->activeTenant()->id;
        $counts = $this->mappings->memberCounts($tenantId);

        return $this->successResponse(
            data: $this->mappings->all($tenantId)->map(fn (ScimGroupMapping $mapping): array => (new ScimGroupMappingResource($mapping, $counts))->resolve())->values()->all(),
            meta: ['roles' => ProvisionableRoles::all()],
        );
    }

    #[OA\Put(
        path: '/api/v1/settings/security/scim/group-mappings',
        operationId: 'upsertScimGroupMapping',
        description: 'Creates the mapping of a SCIM group name (case-insensitive) or changes its role. The current members of the group get the role at once; owners are never touched. Members keep the role until the group, or the mapping, goes away: then the role they had before is restored (only if the mapping still produced it).',
        summary: 'Create or update a SCIM group to role mapping',
        security: [['bearerAuth' => []]],
        tags: ['Settings'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['group_name', 'role'], properties: [
            new OA\Property(property: 'group_name', type: 'string', example: 'ERP Accounting'),
            new OA\Property(property: 'role', type: 'string', example: 'accountant'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ScimGroupMapping')])),
            new OA\Response(response: 201, description: 'Created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ScimGroupMapping')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and administrators', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Unknown role or owner role', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function upsert(UpsertScimGroupMappingRequest $request): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $tenantId = (string) $this->activeTenant()->id;

        [$mapping, $created] = $this->mappings->upsert($tenantId, (string) $request->validated('group_name'), (string) $request->validated('role'), (string) $admin->id);

        return $this->successResponse(
            data: (new ScimGroupMappingResource($mapping, $this->mappings->memberCounts($tenantId)))->resolve(),
            message: __('advanced_auth.messages.scim_mapping_saved'),
            statusCode: $created ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    #[OA\Delete(
        path: '/api/v1/settings/security/scim/group-mappings/{id}',
        operationId: 'deleteScimGroupMapping',
        description: 'Removes a mapping. The members of the group lose the role it granted, if it is still the one the mapping gave them (the role they had before is restored).',
        summary: 'Delete a SCIM group to role mapping',
        security: [['bearerAuth' => []]],
        tags: ['Settings'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and administrators', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'No such mapping in this tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(Request $request, string $id): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        abort_unless($this->mappings->delete((string) $this->activeTenant()->id, $id, (string) $admin->id), Response::HTTP_NOT_FOUND);

        return $this->successResponse(message: __('advanced_auth.messages.scim_mapping_removed'));
    }

    #[OA\Get(
        path: '/api/v1/settings/security/scim/logs',
        operationId: 'listScimProvisioningLogs',
        description: 'The recent SCIM operations of the identity provider (users and groups; creates, replaces, patches and deletes), newest first, paginated. Filters: resource (user|group), status (success|failure), operation.',
        summary: 'SCIM provisioning log',
        security: [['bearerAuth' => []]],
        tags: ['Settings'],
        parameters: [
            new OA\Parameter(name: 'resource', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['user', 'group'])),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['success', 'failure'])),
            new OA\Parameter(name: 'operation', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['create', 'replace', 'patch', 'delete'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 5, maximum: 100, default: 25)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'A page of log entries', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ScimProvisioningLog'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Only owners and administrators', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function logs(ListScimLogsRequest $request): JsonResponse
    {
        $query = ScimProvisioningLog::withoutGlobalScopes()->where('tenant_id', (string) $this->activeTenant()->id);

        foreach (['resource', 'status', 'operation'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, (string) $request->query($filter));
            }
        }

        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate((int) $request->integer('per_page', 25));

        return $this->successResponse(
            data: ScimProvisioningLogResource::collection($page->getCollection())->resolve($request),
            meta: [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        );
    }

    private function activeTenant(): Tenant
    {
        $tenant = $this->tenantContext->getTenant();
        abort_if($tenant === null, Response::HTTP_BAD_REQUEST, 'Missing tenant context.');

        return $tenant;
    }
}
