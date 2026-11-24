<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\TalentLearning\Contracts\HrDirectory;
use Modules\TalentLearning\Http\Controllers\Concerns\InteractsWithTalent;
use Modules\TalentLearning\Http\Requests\CompleteEnrolmentRequest;
use Modules\TalentLearning\Http\Requests\EnrolmentRequest;
use Modules\TalentLearning\Http\Requests\ProgressRequest;
use Modules\TalentLearning\Http\Resources\EnrolmentResource;
use Modules\TalentLearning\Models\LmsEnrolment;
use Modules\TalentLearning\Services\EmployeeAnnotator;
use Modules\TalentLearning\Services\LearningService;
use OpenApi\Attributes as OA;

/**
 * Enrolments of employees in courses, and the learner view of the own enrolments.
 */
final class EnrolmentController extends Controller
{
    use InteractsWithTalent;

    public function __construct(private readonly LearningService $learning, private readonly EmployeeAnnotator $annotator, private readonly HrDirectory $directory) {}

    #[OA\Get(path: '/api/v1/talent-learning/enrolments', operationId: 'listTalentEnrolments', description: 'Enrolments of every employee, latest first. For learning managers. Filters: course_id, employee_id, status, overdue (1 for open enrolments past their due date).', summary: 'List enrolments', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], responses: [
        new OA\Response(response: 200, description: 'Enrolments', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TalentEnrolment'))])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('manage', LmsEnrolment::class);
        $filters = $request->validate([
            'course_id' => ['nullable', 'uuid'],
            'employee_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in([LmsEnrolment::Enrolled, LmsEnrolment::InProgress, LmsEnrolment::Completed, LmsEnrolment::Cancelled])],
            'overdue' => ['nullable', 'boolean'],
        ]);

        $page = LmsEnrolment::query()->with(['course', 'certificate'])->where('tenant_id', $this->tenantId())
            ->when(isset($filters['course_id']), static fn ($query) => $query->where('course_id', $filters['course_id']))
            ->when(isset($filters['employee_id']), static fn ($query) => $query->where('employee_id', $filters['employee_id']))
            ->when(isset($filters['status']), static fn ($query) => $query->where('status', $filters['status']))
            ->when(! empty($filters['overdue']), static fn ($query) => $query->whereIn('status', [LmsEnrolment::Enrolled, LmsEnrolment::InProgress])->whereNotNull('due_on')->where('due_on', '<', Carbon::today()->toDateString()))
            ->orderByDesc('created_at')
            ->paginate($this->perPage());
        $this->annotator->annotate($this->tenantId(), $page->getCollection());

        return EnrolmentResource::collection($page);
    }

    #[OA\Post(path: '/api/v1/talent-learning/enrolments', operationId: 'storeTalentEnrolment', description: 'Enrol an active employee in a published course. An employee has one open enrolment per course and cannot enrol while holding a valid certificate.', summary: 'Enrol an employee in a course', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TalentEnrolmentRequest')), tags: ['Talent and learning'], responses: [
        new OA\Response(response: 201, description: 'Enrolment created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentEnrolment')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function store(EnrolmentRequest $request): JsonResponse
    {
        $this->authorize('create', LmsEnrolment::class);

        return (new EnrolmentResource($this->present($this->learning->enrol($this->tenantId(), $request->validated()))))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Get(path: '/api/v1/talent-learning/enrolments/{id}', operationId: 'showTalentEnrolment', description: 'Return an enrolment. A learner reads her own enrolments through /my/enrolments.', summary: 'Show an enrolment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Enrolment', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentEnrolment')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
    ])]
    public function show(string $id): EnrolmentResource
    {
        $this->authorize('manage', LmsEnrolment::class);
        $enrolment = LmsEnrolment::query()->findOrFail($id);
        $this->authorize('view', $enrolment);

        return new EnrolmentResource($this->present($enrolment));
    }

    #[OA\Post(path: '/api/v1/talent-learning/enrolments/{id}/progress', operationId: 'progressTalentEnrolment', description: 'Record the progress in percent. It can only grow; the first progress starts the enrolment.', summary: 'Record the progress of an enrolment', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TalentProgressRequest')), tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Enrolment updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentEnrolment')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function progress(ProgressRequest $request, string $id): EnrolmentResource
    {
        $enrolment = LmsEnrolment::query()->findOrFail($id);
        $this->authorize('update', $enrolment);

        return new EnrolmentResource($this->present($this->learning->recordProgress($enrolment, (int) $request->validated('progress_percent'))));
    }

    #[OA\Post(path: '/api/v1/talent-learning/enrolments/{id}/complete', operationId: 'completeTalentEnrolment', description: 'Complete an enrolment and issue the certificate (numbered per company; it expires when the course has a validity). The score is required, and must reach the pass score, when the course has one.', summary: 'Complete an enrolment', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/TalentCompleteEnrolmentRequest')), tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Enrolment completed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentEnrolment')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function complete(CompleteEnrolmentRequest $request, string $id): EnrolmentResource
    {
        $enrolment = LmsEnrolment::query()->findOrFail($id);
        $this->authorize('update', $enrolment);
        $score = $request->validated('score');

        return new EnrolmentResource($this->present($this->learning->complete($enrolment, $score === null ? null : (float) $score)));
    }

    #[OA\Post(path: '/api/v1/talent-learning/enrolments/{id}/cancel', operationId: 'cancelTalentEnrolment', description: 'Cancel an enrolment that is not completed.', summary: 'Cancel an enrolment', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Enrolment cancelled', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentEnrolment')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function cancel(string $id): EnrolmentResource
    {
        $enrolment = LmsEnrolment::query()->findOrFail($id);
        $this->authorize('update', $enrolment);

        return new EnrolmentResource($this->present($this->learning->cancel($enrolment)));
    }

    #[OA\Get(path: '/api/v1/talent-learning/my/enrolments', operationId: 'listMyTalentEnrolments', description: 'The enrolments of the employee linked to the logged-in user, with course and certificate. Empty when the user has no employee record.', summary: 'List my enrolments', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Talent and learning'], responses: [
        new OA\Response(response: 200, description: 'Enrolments', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TalentEnrolment'))])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
    ])]
    public function mine(): AnonymousResourceCollection
    {
        $employee = $this->directory->employeeOfUser($this->tenantId(), $this->userId());

        return EnrolmentResource::collection(
            $employee === null ? collect() : LmsEnrolment::query()->with(['course', 'certificate'])->where('tenant_id', $this->tenantId())->where('employee_id', $employee->id)->orderByDesc('created_at')->get(),
        );
    }

    #[OA\Post(path: '/api/v1/talent-learning/my/enrolments/{id}/progress', operationId: 'progressMyTalentEnrolment', description: 'A learner records the progress of her own enrolment. Completion is recorded by a learning manager.', summary: 'Record the progress of my enrolment', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/TalentProgressRequest')), tags: ['Talent and learning'], parameters: [new OA\Parameter(ref: '#/components/parameters/TalentId')], responses: [
        new OA\Response(response: 200, description: 'Enrolment updated', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TalentEnrolment')])),
        new OA\Response(ref: '#/components/responses/TalentUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/TalentForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/TalentNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/TalentUnprocessable', response: 422),
    ])]
    public function mineProgress(ProgressRequest $request, string $id): EnrolmentResource
    {
        $employee = $this->directory->employeeOfUser($this->tenantId(), $this->userId());
        abort_if($employee === null, 404);
        $enrolment = LmsEnrolment::query()->where('employee_id', $employee->id)->findOrFail($id);

        return new EnrolmentResource($this->present($this->learning->recordProgress($enrolment, min(99, (int) $request->validated('progress_percent')))));
    }

    private function present(LmsEnrolment $enrolment): LmsEnrolment
    {
        $enrolment->load(['course', 'certificate']);
        $this->annotator->annotate($this->tenantId(), [$enrolment]);

        return $enrolment;
    }
}
