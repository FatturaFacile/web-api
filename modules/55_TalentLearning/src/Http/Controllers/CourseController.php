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
use Modules\TalentLearning\Http\Requests\CourseRequest;
use Modules\TalentLearning\Http\Resources\CourseResource;
use Modules\TalentLearning\Models\LmsCourse;
use Modules\TalentLearning\Services\LearningService;
use OpenApi\Attributes as OA;

/**
 * The course catalogue.
 */
final class CourseController extends Controller
{
    use InteractsWithTalent;

    public function __construct(private readonly LearningService $learning) {}

    #[OA\Get(path: '/api/v1/talent-learning/courses', operationId: 'listTalentCourses', description: 'Courses by title. Filters: status, delivery, is_mandatory, search (code or title). Members who do not manage learning see the published courses only.', summary: 'List courses', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], responses: [
        new OA\Response(response: 200, description: 'Courses', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TalentCourse'))])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', LmsCourse::class);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([LmsCourse::Draft, LmsCourse::Published, LmsCourse::Archived])],
            'delivery' => ['nullable', Rule::in(LmsCourse::Deliveries)],
            'is_mandatory' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $manages = $request->user()?->can('manage', LmsCourse::class) ?? false;

        return CourseResource::collection(
            LmsCourse::query()->where('tenant_id', $this->tenantId())
                ->when(! $manages, static fn ($query) => $query->where('status', LmsCourse::Published))
                ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
                ->when(isset($filters['delivery']), static fn ($query) => $query->where('delivery', $filters['delivery']))
                ->when(array_key_exists('is_mandatory', $filters) && $filters['is_mandatory'] !== null, static fn ($query) => $query->where('is_mandatory', (bool) $filters['is_mandatory']))
                ->when(! empty($filters['search']), static function ($query) use ($filters): void {
                    $term = '%'.mb_strtolower((string) $filters['search']).'%';
                    $query->where(static fn ($inner) => $inner->whereRaw('lower(title) like ?', [$term])->orWhereRaw('lower(code) like ?', [$term]));
                })
                ->orderBy('title')->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/talent-learning/courses', operationId: 'storeTalentCourse', description: 'Create a course in draft; the code is unique in the company. A mandatory course applies to every active employee, and validity_months makes the certificate expire.', summary: 'Create a course', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TalentCourseRequest')), tags: ['Talent and learning'], responses: [
        new OA\Response(response: 201, description: 'Course created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentCourse')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function store(CourseRequest $request): JsonResponse
    {
        $this->authorize('create', LmsCourse::class);

        return (new CourseResource($this->learning->createCourse($this->tenantId(), $request->validated())))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/talent-learning/courses/{id}', operationId: 'showTalentCourse', description: 'Return a course.', summary: 'Show a course', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Course', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentCourse')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
    ])]
    public function show(string $id): CourseResource
    {
        $course = LmsCourse::query()->findOrFail($id);
        $this->authorize('view', $course);

        return new CourseResource($course);
    }

    #[OA\Put(path: '/api/v1/talent-learning/courses/{id}', operationId: 'updateTalentCourse', description: 'Change a course. The status moves through the publish and archive actions.', summary: 'Update a course', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TalentCourseRequest')), tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Course updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentCourse')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function update(CourseRequest $request, string $id): CourseResource
    {
        $course = LmsCourse::query()->findOrFail($id);
        $this->authorize('update', $course);

        return new CourseResource($this->learning->updateCourse($course, $request->validated()));
    }

    #[OA\Delete(path: '/api/v1/talent-learning/courses/{id}', operationId: 'deleteTalentCourse', description: 'Delete a course that has no enrolments and is in no learning path (soft delete).', summary: 'Delete a course', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 204, description: 'Course deleted'),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function destroy(string $id): Response
    {
        $course = LmsCourse::query()->findOrFail($id);
        $this->authorize('delete', $course);
        $this->learning->deleteCourse($course);

        return response()->noContent();
    }

    #[OA\Post(path: '/api/v1/talent-learning/courses/{id}/publish', operationId: 'publishTalentCourse', description: 'Publish a draft course, or republish an archived one. The course needs a duration.', summary: 'Publish a course', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Course published', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentCourse')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function publish(string $id): CourseResource
    {
        $course = LmsCourse::query()->findOrFail($id);
        $this->authorize('update', $course);

        return new CourseResource($this->learning->transitionCourse($course, LmsCourse::Published));
    }

    #[OA\Post(path: '/api/v1/talent-learning/courses/{id}/archive', operationId: 'archiveTalentCourse', description: 'Archive a published course: it takes no new enrolments and leaves the mandatory compliance.', summary: 'Archive a course', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Course archived', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentCourse')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function archive(string $id): CourseResource
    {
        $course = LmsCourse::query()->findOrFail($id);
        $this->authorize('update', $course);

        return new CourseResource($this->learning->transitionCourse($course, LmsCourse::Archived));
    }
}
