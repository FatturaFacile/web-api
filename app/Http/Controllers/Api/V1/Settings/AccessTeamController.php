<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Settings;

use App\Exceptions\SodConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\Access\SaveTeamRequest;
use App\Http\Requests\Settings\Access\SetTeamMembersRequest;
use App\Http\Resources\Access\AccessTeamResource;
use App\Http\Responses\ApiResponse;
use App\Models\Access\AccessTeam;
use App\Services\Tenancy\AccessTeams;
use App\Services\Tenancy\TenantContext;
use DomainException;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Teams of the tenant and their members.
 */
class AccessTeamController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AccessTeams $teams,
    ) {}

    #[OA\Get(path: '/api/v1/settings/access/teams', operationId: 'listAccessTeams', description: 'Teams of the active tenant with the ids of their members.', summary: 'List teams', security: [['bearerAuth' => []]], tags: ['Settings', 'Access'], responses: [
        new OA\Response(response: 200, description: 'Teams retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function index(): JsonResponse
    {
        return $this->successResponse(data: AccessTeamResource::collection($this->teams->list($this->tenantContext->getTenant()))->resolve(), message: 'Teams retrieved');
    }

    #[OA\Post(path: '/api/v1/settings/access/teams', operationId: 'createAccessTeam', description: 'Creates a team, optionally with a custom role that members added to it receive.', summary: 'Create a team', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['name'], properties: [
        new OA\Property(property: 'name', type: 'string', example: 'Sales'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'custom_role_id', type: 'string', format: 'uuid', nullable: true),
    ])), tags: ['Settings', 'Access'], responses: [
        new OA\Response(response: 201, description: 'Team created', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function store(SaveTeamRequest $request): JsonResponse
    {
        $team = $this->teams->create($this->tenantContext->getTenant(), $request->validated());

        return $this->successResponse(data: AccessTeamResource::make($team)->resolve(), message: 'Team created', statusCode: Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/settings/access/teams/{id}', operationId: 'updateAccessTeam', description: 'Changes the name, the description or the custom role of a team.', summary: 'Update a team', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'description', type: 'string', nullable: true),
        new OA\Property(property: 'custom_role_id', type: 'string', format: 'uuid', nullable: true),
    ])), tags: ['Settings', 'Access'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Team updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function update(SaveTeamRequest $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $team = AccessTeam::query()->where('tenant_id', $tenant->id)->findOrFail($id);

        return $this->successResponse(data: AccessTeamResource::make($this->teams->update($tenant, $team, $request->validated()))->resolve(), message: 'Team updated');
    }

    #[OA\Put(path: '/api/v1/settings/access/teams/{id}/members', operationId: 'setAccessTeamMembers', description: 'Replaces the members of a team. Every user must be a member of the tenant.', summary: 'Set the members of a team', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['user_ids'], properties: [
        new OA\Property(property: 'user_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid')),
    ])), tags: ['Settings', 'Access'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Members saved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 409, description: 'Segregation of duties conflict', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'A user is not a member of the tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function members(SetTeamMembersRequest $request, string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $team = AccessTeam::query()->where('tenant_id', $tenant->id)->findOrFail($id);

        try {
            $team = $this->teams->setMembers($tenant, $team, $request->validated('user_ids'));
        } catch (SodConflictException $exception) {
            return $this->problemDetailsResponse(title: 'Segregation of Duties Conflict', detail: $exception->getMessage(), status: Response::HTTP_CONFLICT);
        } catch (DomainException $exception) {
            return $this->problemDetailsResponse(
                title: 'Not A Member',
                detail: $exception->getMessage(),
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
                invalidParams: [['name' => 'user_ids', 'reason' => $exception->getMessage()]],
            );
        }

        return $this->successResponse(data: AccessTeamResource::make($team)->resolve(), message: 'Team members saved');
    }

    #[OA\Delete(path: '/api/v1/settings/access/teams/{id}', operationId: 'deleteAccessTeam', description: 'Deletes a team; its members keep their roles.', summary: 'Delete a team', security: [['bearerAuth' => []]], tags: ['Settings', 'Access'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Team deleted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function destroy(string $id): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $this->teams->delete($tenant, AccessTeam::query()->where('tenant_id', $tenant->id)->findOrFail($id));

        return $this->successResponse(message: 'Team deleted');
    }
}
