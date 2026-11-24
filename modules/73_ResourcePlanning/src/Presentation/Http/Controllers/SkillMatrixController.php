<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\ResourcePlanning\Application\Services\SkillCatalogService;
use Modules\ResourcePlanning\Presentation\Http\Controllers\Concerns\MapsResourcePlanningErrors;
use Modules\ResourcePlanning\Presentation\Http\Requests\EmployeeSkillProfileRequest;
use Modules\ResourcePlanning\Presentation\Http\Requests\PlanningWindowRequest;
use Modules\ResourcePlanning\Presentation\Http\Requests\SkillRequest;
use Modules\ResourcePlanning\Presentation\Http\Resources\SkillResource;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * The skill taxonomy (SkillTaxonomy) and the skill matrix of the employees (EmployeeSkillProfile) of Mod 079.
 */
final class SkillMatrixController extends Controller
{
    use ApiResponse;
    use MapsResourcePlanningErrors;

    public function __construct(private readonly SkillCatalogService $catalog) {}

    #[OA\Get(path: '/api/v1/resource-planning/skills', operationId: 'listPlanningSkills', summary: 'Skill taxonomy', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Resource Planning'], responses: [new OA\Response(response: 200, description: 'The skills, ordered by category and name')])]
    public function skills(PlanningWindowRequest $request): JsonResponse
    {
        return $this->successResponse(SkillResource::collection($this->catalog->skills($request->validated('category'))));
    }

    #[OA\Post(path: '/api/v1/resource-planning/skills', operationId: 'createPlanningSkill', summary: 'Create a skill', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Resource Planning'], responses: [new OA\Response(response: 201, description: 'The skill'), new OA\Response(response: 422, description: 'Validation error')])]
    public function storeSkill(SkillRequest $request): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse(new SkillResource($this->catalog->createSkill($request->validated())), 'Skill created', Response::HTTP_CREATED));
    }

    #[OA\Put(path: '/api/v1/resource-planning/skills/{id}', operationId: 'updatePlanningSkill', summary: 'Change a skill', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Resource Planning'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The skill'), new OA\Response(response: 404, description: 'Unknown skill')])]
    public function updateSkill(string $id, SkillRequest $request): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse(new SkillResource($this->catalog->updateSkill($id, $request->validated())), 'Skill updated'));
    }

    #[OA\Delete(path: '/api/v1/resource-planning/skills/{id}', operationId: 'deletePlanningSkill', summary: 'Delete a skill with its employee profiles', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Resource Planning'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 204, description: 'Deleted'), new OA\Response(response: 404, description: 'Unknown skill')])]
    public function destroySkill(string $id): JsonResponse
    {
        return $this->guarded(function () use ($id): JsonResponse {
            $this->catalog->deleteSkill($id);

            return response()->json(null, Response::HTTP_NO_CONTENT);
        });
    }

    #[OA\Get(path: '/api/v1/resource-planning/employee-skill-profiles', operationId: 'listEmployeeSkillProfiles', summary: 'Skill matrix', description: 'The proficiency of employees in skills, with the validity of the safety certificate (Mod 099) a skill requires.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Resource Planning'], responses: [new OA\Response(response: 200, description: 'The profiles')])]
    public function profiles(PlanningWindowRequest $request): JsonResponse
    {
        $employees = $request->validated('employee_id');

        return $this->successResponse($this->catalog->profiles(is_array($employees) && $employees !== [] ? (string) $employees[0] : null, $request->validated('skill_id')));
    }

    #[OA\Post(path: '/api/v1/resource-planning/employee-skill-profiles', operationId: 'upsertEmployeeSkillProfile', summary: 'Record the proficiency of an employee in a skill', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Resource Planning'], responses: [new OA\Response(response: 201, description: 'The profile'), new OA\Response(response: 422, description: 'Validation error')])]
    public function storeProfile(EmployeeSkillProfileRequest $request): JsonResponse
    {
        return $this->successResponse($this->catalog->upsertProfile($request->validated()), 'Skill profile saved', Response::HTTP_CREATED);
    }

    #[OA\Put(path: '/api/v1/resource-planning/employee-skill-profiles/{id}', operationId: 'updateEmployeeSkillProfile', summary: 'Change the level or validity of a skill profile', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Resource Planning'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'The profile'), new OA\Response(response: 404, description: 'Unknown profile')])]
    public function updateProfile(string $id, EmployeeSkillProfileRequest $request): JsonResponse
    {
        return $this->guarded(fn (): JsonResponse => $this->successResponse($this->catalog->updateProfile($id, $request->validated()), 'Skill profile updated'));
    }

    #[OA\Delete(path: '/api/v1/resource-planning/employee-skill-profiles/{id}', operationId: 'deleteEmployeeSkillProfile', summary: 'Delete a skill profile', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Resource Planning'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 204, description: 'Deleted'), new OA\Response(response: 404, description: 'Unknown profile')])]
    public function destroyProfile(string $id): JsonResponse
    {
        return $this->guarded(function () use ($id): JsonResponse {
            $this->catalog->deleteProfile($id);

            return response()->json(null, Response::HTTP_NO_CONTENT);
        });
    }
}
