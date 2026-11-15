<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Modules\TalentLearning\Http\Controllers\Concerns\InteractsWithTalent;
use Modules\TalentLearning\Http\Requests\LearningPathRequest;
use Modules\TalentLearning\Http\Requests\PathEnrolmentRequest;
use Modules\TalentLearning\Http\Resources\EnrolmentResource;
use Modules\TalentLearning\Http\Resources\LearningPathResource;
use Modules\TalentLearning\Models\LmsCourse;
use Modules\TalentLearning\Models\LmsEnrolment;
use Modules\TalentLearning\Models\LmsLearningPath;
use Modules\TalentLearning\Services\EmployeeAnnotator;
use Modules\TalentLearning\Services\LearningService;
use OpenApi\Attributes as OA;

/**
 * Learning paths: ordered sequences of courses.
 */
final class LearningPathController extends Controller
{
    use InteractsWithTalent;

    public function __construct(private readonly LearningService $learning, private readonly EmployeeAnnotator $annotator) {}

    #[OA\Get(path: '/api/v1/talent-learning/learning-paths', operationId: 'listTalentLearningPaths', description: 'Learning paths by name, with their courses. Members who do not manage learning see the published paths only. Filter: status.', summary: 'List learning paths', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], responses: [
        new OA\Response(response: 200, description: 'Paths', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TalentLearningPath'))])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', LmsLearningPath::class);
        $filters = $request->validate(['status' => ['nullable', Rule::in([LmsCourse::Draft, LmsCourse::Published, LmsCourse::Archived])]]);
        $manages = $request->user()?->can('manage', LmsLearningPath::class) ?? false;

        return LearningPathResource::collection(
            LmsLearningPath::query()->with('steps.course')->where('tenant_id', $this->tenantId())
                ->when(! $manages, static fn ($query) => $query->where('status', LmsCourse::Published))
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->orderBy('name')->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/talent-learning/learning-paths', operationId: 'storeTalentLearningPath', description: 'Create a learning path in draft with its ordered courses (course_ids).', summary: 'Create a learning path', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TalentLearningPathRequest')), tags: ['Talent and learning'], responses: [
        new OA\Response(response: 201, description: 'Path created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentLearningPath')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function store(LearningPathRequest $request): JsonResponse
    {
        $this->authorize('create', LmsLearningPath::class);

        return (new LearningPathResource($this->learning->savePath($this->tenantId(), $request->validated(), null)->load('steps.course')))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/talent-learning/learning-paths/{id}', operationId: 'showTalentLearningPath', description: 'Return a learning path with its courses.', summary: 'Show a learning path', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Path', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentLearningPath')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
    ])]
    public function show(string $id): LearningPathResource
    {
        $path = LmsLearningPath::query()->with('steps.course')->findOrFail($id);
        $this->authorize('view', $path);

        return new LearningPathResource($path);
    }

    #[OA\Put(path: '/api/v1/talent-learning/learning-paths/{id}', operationId: 'updateTalentLearningPath', description: 'Change a path; course_ids replaces the ordered courses.', summary: 'Update a learning path', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TalentLearningPathRequest')), tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Path updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentLearningPath')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function update(LearningPathRequest $request, string $id): LearningPathResource
    {
        $path = LmsLearningPath::query()->findOrFail($id);
        $this->authorize('update', $path);

        return new LearningPathResource($this->learning->savePath($this->tenantId(), $request->validated(), $path)->load('steps.course'));
    }

    #[OA\Delete(path: '/api/v1/talent-learning/learning-paths/{id}', operationId: 'deleteTalentLearningPath', description: 'Delete a path nobody is enrolled through (soft delete).', summary: 'Delete a learning path', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 204, description: 'Path deleted'),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $path = LmsLearningPath::query()->findOrFail($id);
        $this->authorize('delete', $path);
        $this->learning->deletePath($path);

        return response()->noContent();
    }

    #[OA\Post(path: '/api/v1/talent-learning/learning-paths/{id}/publish', operationId: 'publishTalentLearningPath', description: 'Publish a path. Every course of it must be published.', summary: 'Publish a learning path', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Path published', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentLearningPath')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function publish(string $id): LearningPathResource
    {
        $path = LmsLearningPath::query()->findOrFail($id);
        $this->authorize('update', $path);

        return new LearningPathResource($this->learning->transitionPath($path, LmsCourse::Published)->load('steps.course'));
    }

    #[OA\Post(path: '/api/v1/talent-learning/learning-paths/{id}/archive', operationId: 'archiveTalentLearningPath', description: 'Archive a path: it takes no new enrolments.', summary: 'Archive a learning path', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Path archived', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentLearningPath')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
    ])]
    public function archive(string $id): LearningPathResource
    {
        $path = LmsLearningPath::query()->findOrFail($id);
        $this->authorize('update', $path);

        return new LearningPathResource($this->learning->transitionPath($path, LmsCourse::Archived)->load('steps.course'));
    }

    #[OA\Post(path: '/api/v1/talent-learning/learning-paths/{id}/enrol', operationId: 'enrolTalentLearningPath', description: 'Enrol active employees in every course of a published path. Courses an employee is already taking, or holds a valid certificate for, are skipped. Returns the enrolments created.', summary: 'Enrol employees in a learning path', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TalentPathEnrolmentRequest')), tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 201, description: 'Enrolments created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TalentEnrolment'))])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function enrol(PathEnrolmentRequest $request, string $id): JsonResponse
    {
        $path = LmsLearningPath::query()->findOrFail($id);
        $this->authorize('update', $path);
        $this->authorize('create', LmsEnrolment::class);
        $enrolments = $this->learning->enrolInPath($path, $request->validated('employee_ids'), $request->validated('due_on'));
        $this->annotator->annotate($this->tenantId(), $enrolments);

        return EnrolmentResource::collection(collect($enrolments))->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
