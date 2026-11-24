<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\HumanResources\Enums\ExitReasonCode;
use Modules\HumanResources\Http\Requests\SaveOffboardingTemplateRequest;
use Modules\HumanResources\Http\Requests\StartOffboardingRequest;
use Modules\HumanResources\Http\Requests\StoreExitInterviewRequest;
use Modules\HumanResources\Http\Resources\HrRecordResource;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\ExitInterview;
use Modules\HumanResources\Models\OnboardingChecklist;
use Modules\HumanResources\Models\OnboardingTask;
use Modules\HumanResources\Models\OnboardingTemplate;
use Modules\HumanResources\Services\Offboarding\OffboardingService;
use OpenApi\Attributes as OA;

/**
 * Offboarding of the employees who leave: templates, checklists, tasks and exit interviews.
 */
final class OffboardingController extends Controller
{
    public function __construct(private readonly OffboardingService $offboarding) {}

    /**
     * List offboarding templates.
     */
    #[OA\Get(
        path: '/api/v1/hr/offboarding-templates',
        operationId: 'listOffboardingTemplates',
        description: 'Return the offboarding templates: lists of tasks, each due some days after the termination date.',
        summary: 'List offboarding templates',
        security: [['bearerAuth' => []]],
        tags: ['Human Resources'],
        responses: [
            new OA\Response(response: 200, description: 'Templates', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        ],
    )]
    public function templates(): AnonymousResourceCollection
    {
        return HrRecordResource::collection(OnboardingTemplate::query()->where('kind', OnboardingTemplate::KindOffboarding)->orderBy('name')->gridPaginate(RequestedPerPage::resolve(100)));
    }

    /**
     * Create offboarding template.
     */
    #[OA\Post(
        path: '/api/v1/hr/offboarding-templates',
        operationId: 'storeOffboardingTemplate',
        description: 'Create a list of offboarding tasks. A task of code asset_return is repeated for each asset of the leaving employee.',
        summary: 'Create offboarding template',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesSaveOffboardingTemplateRequest')),
        tags: ['Human Resources'],
        responses: [
            new OA\Response(response: 201, description: 'Template', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ],
    )]
    public function storeTemplate(SaveOffboardingTemplateRequest $request): JsonResponse
    {
        return (new HrRecordResource(OnboardingTemplate::create([...$request->validated(), 'kind' => OnboardingTemplate::KindOffboarding])))->response()->setStatusCode(201);
    }

    /**
     * Update offboarding template.
     */
    #[OA\Put(
        path: '/api/v1/hr/offboarding-templates/{id}',
        operationId: 'updateOffboardingTemplate',
        description: 'Change the name or the tasks of an offboarding template; checklists already started keep their tasks.',
        summary: 'Update offboarding template',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesSaveOffboardingTemplateRequest')),
        tags: ['Human Resources'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Template', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ],
    )]
    public function updateTemplate(SaveOffboardingTemplateRequest $request, string $id): HrRecordResource
    {
        $template = OnboardingTemplate::query()->where('kind', OnboardingTemplate::KindOffboarding)->findOrFail($id);
        $template->update($request->validated());

        return new HrRecordResource($template);
    }

    /**
     * Delete offboarding template.
     */
    #[OA\Delete(
        path: '/api/v1/hr/offboarding-templates/{id}',
        operationId: 'deleteOffboardingTemplate',
        description: 'Delete an offboarding template; checklists already started are not affected.',
        summary: 'Delete offboarding template',
        security: [['bearerAuth' => []]],
        tags: ['Human Resources'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        ],
    )]
    public function destroyTemplate(string $id): Response
    {
        OnboardingTemplate::query()->where('kind', OnboardingTemplate::KindOffboarding)->findOrFail($id)->delete();

        return response()->noContent();
    }

    /**
     * List offboarding checklists.
     */
    #[OA\Get(
        path: '/api/v1/hr/offboarding-checklists',
        operationId: 'listOffboardingChecklists',
        description: 'Return the offboarding checklists with their tasks and employee, optionally of one employee or only the open or completed ones.',
        summary: 'List offboarding checklists',
        security: [['bearerAuth' => []]],
        tags: ['Human Resources'],
        parameters: [new OA\Parameter(name: 'employee_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['open', 'completed']))],
        responses: [
            new OA\Response(response: 200, description: 'Checklists', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        ],
    )]
    public function checklists(Request $request): AnonymousResourceCollection
    {
        return HrRecordResource::collection(OnboardingChecklist::query()
            ->where('kind', OnboardingChecklist::KindOffboarding)
            ->with(['tasks', 'employee:id,employee_number,full_name,department_id,termination_date'])
            ->when($request->query('employee_id'), static fn ($query, $value) => $query->where('employee_id', $value))
            ->when($request->query('status') === 'open', static fn ($query) => $query->whereNull('completed_at'))
            ->when($request->query('status') === 'completed', static fn ($query) => $query->whereNotNull('completed_at'))
            ->orderByDesc('created_at')
            ->gridPaginate(RequestedPerPage::resolve(25)));
    }

    /**
     * Show offboarding checklist.
     */
    #[OA\Get(
        path: '/api/v1/hr/offboarding-checklists/{id}',
        operationId: 'showOffboardingChecklist',
        description: 'Return one offboarding checklist with its tasks and employee.',
        summary: 'Show offboarding checklist',
        security: [['bearerAuth' => []]],
        tags: ['Human Resources'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Checklist', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        ],
    )]
    public function showChecklist(string $id): HrRecordResource
    {
        return new HrRecordResource(OnboardingChecklist::query()->where('kind', OnboardingChecklist::KindOffboarding)->with(['tasks', 'employee:id,employee_number,full_name,department_id,termination_date'])->findOrFail($id));
    }

    /**
     * Start offboarding.
     */
    #[OA\Post(
        path: '/api/v1/hr/offboarding-checklists',
        operationId: 'startOffboardingChecklist',
        description: 'Open the offboarding checklist of an employee who has already been terminated (the terminate action opens it by itself).',
        summary: 'Start offboarding',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesStartOffboardingRequest')),
        tags: ['Human Resources'],
        responses: [
            new OA\Response(response: 201, description: 'Checklist', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ],
    )]
    public function startChecklist(StartOffboardingRequest $request): JsonResponse
    {
        $template = $request->validated('template_id') !== null
            ? OnboardingTemplate::query()->where('kind', OnboardingTemplate::KindOffboarding)->findOrFail($request->validated('template_id'))
            : null;
        $checklist = $this->offboarding->startOffboarding(Employee::query()->findOrFail($request->validated('employee_id')), $template);

        return (new HrRecordResource($checklist->load(['tasks', 'employee:id,employee_number,full_name,department_id,termination_date'])))->response()->setStatusCode(201);
    }

    /**
     * Complete offboarding task.
     */
    #[OA\Post(
        path: '/api/v1/hr/offboarding-tasks/{id}/complete',
        operationId: 'completeOffboardingTask',
        description: 'Mark an offboarding task as done. The last task closes the checklist.',
        summary: 'Complete offboarding task',
        security: [['bearerAuth' => []]],
        tags: ['Human Resources'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Task', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ],
    )]
    public function completeTask(Request $request, string $id): HrRecordResource
    {
        return new HrRecordResource($this->offboarding->completeTask(OnboardingTask::query()->findOrFail($id), $this->actor($request)));
    }

    /**
     * List exit reason codes.
     */
    #[OA\Get(
        path: '/api/v1/hr/exit-reason-codes',
        operationId: 'listExitReasonCodes',
        description: 'Return the reason codes of an exit interview and whether each is a voluntary departure.',
        summary: 'List exit reason codes',
        security: [['bearerAuth' => []]],
        tags: ['Human Resources'],
        responses: [
            new OA\Response(response: 200, description: 'Reason codes', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        ],
    )]
    public function reasonCodes(): JsonResponse
    {
        return response()->json(['data' => array_map(static fn (ExitReasonCode $code): array => ['code' => $code->value, 'voluntary' => $code->isVoluntary()], ExitReasonCode::cases())]);
    }

    /**
     * List exit interviews.
     */
    #[OA\Get(
        path: '/api/v1/hr/exit-interviews',
        operationId: 'listExitInterviews',
        description: 'Return the exit interviews, optionally of one employee or one reason code.',
        summary: 'List exit interviews',
        security: [['bearerAuth' => []]],
        tags: ['Human Resources'],
        parameters: [new OA\Parameter(name: 'employee_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'reason_code', in: 'query', required: false, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Exit interviews', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        ],
    )]
    public function interviews(Request $request): AnonymousResourceCollection
    {
        return HrRecordResource::collection(ExitInterview::query()
            ->with('employee:id,employee_number,full_name,department_id,termination_date')
            ->when($request->query('employee_id'), static fn ($query, $value) => $query->where('employee_id', $value))
            ->when($request->query('reason_code'), static fn ($query, $value) => $query->where('reason_code', $value))
            ->orderByDesc('conducted_on')
            ->gridPaginate(RequestedPerPage::resolve(25)));
    }

    /**
     * Record exit interview.
     */
    #[OA\Post(
        path: '/api/v1/hr/exit-interviews',
        operationId: 'storeExitInterview',
        description: 'Record the exit interview of a terminated employee with its reason code (a second record of the same employee replaces the first) and close the exit interview task of the offboarding.',
        summary: 'Record exit interview',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesStoreExitInterviewRequest')),
        tags: ['Human Resources'],
        responses: [
            new OA\Response(response: 201, description: 'Exit interview', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
        ],
    )]
    public function storeInterview(StoreExitInterviewRequest $request): JsonResponse
    {
        $data = $request->validated();
        $employee = Employee::query()->findOrFail($data['employee_id']);
        unset($data['employee_id']);

        return (new HrRecordResource($this->offboarding->recordExitInterview($employee, $data, $this->actor($request))))->response()->setStatusCode(201);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
