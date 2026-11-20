<?php

declare(strict_types=1);

namespace Modules\Academy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Academy\Commands\CreateClassroomCohortCommand;
use Modules\Academy\Commands\EnrollStudentInClassroomCommand;
use Modules\Academy\Http\Requests\CreateClassroomCohortRequest;
use Modules\Academy\Http\Requests\JoinClassroomRequest;
use Modules\Academy\Http\Resources\AcademyArrayResource;
use Modules\Academy\Http\Resources\ClassroomCohortResource;
use Modules\Academy\Models\ClassroomCohort;
use Modules\Academy\Queries\GetClassroomCohortDashboardQuery;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Classroom cohorts: a teacher creates one, students join with the code, the teacher follows them on the dashboard.
 */
final class AcademyClassroomController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantContext $tenantContext) {}

    #[OA\Post(
        path: '/api/v1/academy/classroom/cohorts',
        operationId: 'createAcademyClassroomCohort',
        description: 'Creates a cohort led by the caller, with a join code, and assigns the given tracks to it.',
        summary: 'Create a classroom cohort',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreateAcademyClassroomCohortRequest')),
        tags: ['Academy'],
        responses: [
            new OA\Response(response: 201, description: 'Cohort', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AcademyClassroomCohort')])),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(CreateClassroomCohortRequest $request, CreateClassroomCohortCommand $command): JsonResponse
    {
        /** @var list<string> $trackIds */
        $trackIds = $request->validated('track_ids') ?? [];

        $cohort = $command->execute($this->user($request), $this->tenantId(), (string) $request->validated('name'), $trackIds, $request->validated('due_date'));

        return $this->successResponse(new ClassroomCohortResource($cohort), 'Cohort created', Response::HTTP_CREATED);
    }

    #[OA\Post(
        path: '/api/v1/academy/classroom/join',
        operationId: 'joinAcademyClassroom',
        description: 'Enrolls the caller in the cohort of the tenant with this join code.',
        summary: 'Join a classroom cohort',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/JoinAcademyClassroomRequest')),
        tags: ['Academy'],
        responses: [
            new OA\Response(response: 200, description: 'Enrolled', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'cohort_id', type: 'string', format: 'uuid'), new OA\Property(property: 'enrolled_at', type: 'string', format: 'date-time')])])),
            new OA\Response(response: 404, description: 'Unknown join code', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function join(JoinClassroomRequest $request, EnrollStudentInClassroomCommand $command): JsonResponse
    {
        $enrollment = $command->execute($this->user($request), $this->tenantId(), (string) $request->validated('join_code'));

        return $this->successResponse(['cohort_id' => (string) $enrollment->cohort_id, 'enrolled_at' => $enrollment->enrolled_at->toIso8601String()], 'Enrolled');
    }

    #[OA\Get(
        path: '/api/v1/academy/classroom/cohorts/{cohortId}/dashboard',
        operationId: 'getAcademyClassroomDashboard',
        description: 'Students, XP, completed missions and certificates of a cohort. Only the teacher of the cohort and the owners and administrators of the tenant can read it.',
        summary: 'Classroom cohort dashboard',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Academy'],
        parameters: [new OA\Parameter(name: 'cohortId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Dashboard', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/AcademyClassroomDashboard')])),
            new OA\Response(response: 403, description: 'Not the teacher of the cohort', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function dashboard(Request $request, string $cohortId, GetClassroomCohortDashboardQuery $query): JsonResponse
    {
        $user = $this->user($request);
        $cohort = ClassroomCohort::query()->where('tenant_id', $this->tenantId())->findOrFail($cohortId);

        if ($cohort->teacher_id !== (string) $user->id && ! in_array($user->getRoleInTenant($this->tenantId()), ['owner', 'admin'], true)) {
            return $this->problemDetailsResponse('Forbidden', 'Only the teacher of the cohort can see its dashboard.', Response::HTTP_FORBIDDEN);
        }

        return $this->successResponse(new AcademyArrayResource($query->execute($this->tenantId(), $cohortId)));
    }

    private function tenantId(): string
    {
        return (string) $this->tenantContext->getTenantId();
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
