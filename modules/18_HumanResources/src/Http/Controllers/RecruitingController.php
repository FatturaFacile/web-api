<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\HumanResources\Http\Requests\HireCandidateRequest;
use Modules\HumanResources\Http\Requests\MoveApplicationRequest;
use Modules\HumanResources\Http\Requests\RecordConsentRequest;
use Modules\HumanResources\Http\Requests\SaveJobPostingRequest;
use Modules\HumanResources\Http\Requests\SaveOnboardingTemplateRequest;
use Modules\HumanResources\Http\Requests\SaveRecruitingSettingsRequest;
use Modules\HumanResources\Http\Requests\StoreInterviewNoteRequest;
use Modules\HumanResources\Http\Requests\StoreJobApplicationRequest;
use Modules\HumanResources\Http\Resources\HrRecordResource;
use Modules\HumanResources\Models\InterviewNote;
use Modules\HumanResources\Models\JobApplication;
use Modules\HumanResources\Models\JobPosting;
use Modules\HumanResources\Models\OnboardingChecklist;
use Modules\HumanResources\Models\OnboardingTask;
use Modules\HumanResources\Models\OnboardingTemplate;
use Modules\HumanResources\Models\PipelineStage;
use Modules\HumanResources\Models\RecruitingSetting;
use Modules\HumanResources\Services\Recruiting\RecruitingService;
use OpenApi\Attributes as OA;

/**
 * Job postings, the hiring pipeline, interview notes, retention of candidate data and onboarding.
 */
final class RecruitingController extends Controller
{
    public function __construct(private readonly RecruitingService $recruiting) {}

    /**
     * List postings.
     */
    #[OA\Get(path: '/api/v1/hr/job-postings', operationId: 'listJobPostings', description: 'Return the job postings of the company.', summary: 'List job postings', security: [['bearerAuth' => []]], tags: ['Human Resources'], responses: [
        new OA\Response(response: 200, description: 'Postings', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
    ])]
    public function postings(): AnonymousResourceCollection
    {
        return HrRecordResource::collection(JobPosting::query()->orderByDesc('created_at')->get());
    }

    /**
     * Create a posting.
     */
    #[OA\Post(path: '/api/v1/hr/job-postings', operationId: 'storeJobPosting', description: 'Create a job posting, in draft or open.', summary: 'Create job posting', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesSaveJobPostingRequest')), tags: ['Human Resources'], responses: [
        new OA\Response(response: 201, description: 'Posting', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function storePosting(SaveJobPostingRequest $request): JsonResponse
    {
        return (new HrRecordResource(JobPosting::create($request->validated())))->response()->setStatusCode(201);
    }

    /**
     * Close a posting.
     */
    #[OA\Post(path: '/api/v1/hr/job-postings/{id}/close', operationId: 'closeJobPosting', description: 'Close a posting. Applications still open get their retention date.', summary: 'Close job posting', security: [['bearerAuth' => []]], tags: ['Human Resources'], parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')], responses: [
        new OA\Response(response: 200, description: 'Posting', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
    ])]
    public function closePosting(string $id): HrRecordResource
    {
        return new HrRecordResource($this->recruiting->closePosting(JobPosting::query()->findOrFail($id)));
    }

    /**
     * The stages of the pipeline.
     */
    #[OA\Get(path: '/api/v1/hr/pipeline-stages', operationId: 'listPipelineStages', description: 'Return the stages of the hiring pipeline, created with defaults on first use.', summary: 'List pipeline stages', security: [['bearerAuth' => []]], tags: ['Human Resources'], responses: [
        new OA\Response(response: 200, description: 'Stages', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
    ])]
    public function stages(): AnonymousResourceCollection
    {
        return HrRecordResource::collection($this->recruiting->stages());
    }

    /**
     * List applications.
     */
    #[OA\Get(path: '/api/v1/hr/job-applications', operationId: 'listJobApplications', description: 'Return the applications, optionally of one posting, with their stage.', summary: 'List job applications', security: [['bearerAuth' => []]], tags: ['Human Resources'], parameters: [new OA\Parameter(name: 'job_posting_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Applications', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
    ])]
    public function applications(Request $request): AnonymousResourceCollection
    {
        return HrRecordResource::collection(JobApplication::query()
            ->with('stage')
            ->when($request->query('job_posting_id'), static fn ($query, $postingId) => $query->where('job_posting_id', $postingId))
            ->orderByDesc('created_at')
            ->paginate(RequestedPerPage::resolve(50)));
    }

    /**
     * Record an application.
     */
    #[OA\Post(path: '/api/v1/hr/job-applications', operationId: 'storeJobApplication', description: 'Record an application to an open posting. Personal data are encrypted.', summary: 'Create job application', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesStoreJobApplicationRequest')), tags: ['Human Resources'], responses: [
        new OA\Response(response: 201, description: 'Application', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function storeApplication(StoreJobApplicationRequest $request): JsonResponse
    {
        $posting = JobPosting::query()->findOrFail($request->validated('job_posting_id'));
        $values = collect($request->validated())->except('job_posting_id')->all();

        return (new HrRecordResource($this->recruiting->apply($posting, $values, $this->actor($request))))->response()->setStatusCode(201);
    }

    /**
     * Show an application.
     */
    #[OA\Get(path: '/api/v1/hr/job-applications/{id}', operationId: 'showJobApplication', description: 'Return an application with its stage history and interview notes.', summary: 'Show job application', security: [['bearerAuth' => []]], tags: ['Human Resources'], parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')], responses: [
        new OA\Response(response: 200, description: 'Application', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
    ])]
    public function showApplication(string $id): HrRecordResource
    {
        return new HrRecordResource(JobApplication::query()->with('stage', 'stageEvents', 'interviewNotes')->findOrFail($id));
    }

    /**
     * Move an application to a stage.
     */
    #[OA\Post(path: '/api/v1/hr/job-applications/{id}/move', operationId: 'moveJobApplication', description: 'Move an active application to a stage of the pipeline. The closing stages are reached with hire and reject.', summary: 'Move job application', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['pipeline_stage_id'], properties: [new OA\Property(property: 'pipeline_stage_id', type: 'string', format: 'uuid')], type: 'object')), tags: ['Human Resources'], parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')], responses: [
        new OA\Response(response: 200, description: 'Application', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function moveApplication(MoveApplicationRequest $request, string $id): HrRecordResource
    {
        $stage = PipelineStage::query()->findOrFail($request->validated('pipeline_stage_id'));

        return new HrRecordResource($this->recruiting->moveToStage(JobApplication::query()->findOrFail($id), $stage, $this->actor($request)));
    }

    /**
     * Add an interview note.
     */
    #[OA\Post(path: '/api/v1/hr/job-applications/{id}/notes', operationId: 'storeInterviewNote', description: 'Add an interview note to an application. It is encrypted and erased with the application.', summary: 'Create interview note', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesStoreInterviewNoteRequest')), tags: ['Human Resources'], parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')], responses: [
        new OA\Response(response: 201, description: 'Note', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function storeNote(StoreInterviewNoteRequest $request, string $id): JsonResponse
    {
        $application = JobApplication::query()->findOrFail($id);
        $note = InterviewNote::query()->create([...$request->validated(), 'job_application_id' => $application->id, 'interviewer_id' => $this->actor($request)->id]);

        return (new HrRecordResource($note))->response()->setStatusCode(201);
    }

    /**
     * Reject an application.
     */
    #[OA\Post(path: '/api/v1/hr/job-applications/{id}/reject', operationId: 'rejectJobApplication', description: 'Reject an application. It is erased automatically when its retention ends.', summary: 'Reject job application', security: [['bearerAuth' => []]], tags: ['Human Resources'], parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')], responses: [
        new OA\Response(response: 200, description: 'Application', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function reject(Request $request, string $id): HrRecordResource
    {
        return new HrRecordResource($this->recruiting->reject(JobApplication::query()->findOrFail($id), $this->actor($request)));
    }

    /**
     * Record the consent to keep the data.
     */
    #[OA\Post(path: '/api/v1/hr/job-applications/{id}/consent', operationId: 'recordApplicationConsent', description: 'Record the consent of the candidate to stay in the pool until a date. It can only lengthen the retention.', summary: 'Record candidate consent', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['consent_until'], properties: [new OA\Property(property: 'consent_until', type: 'string', format: 'date')], type: 'object')), tags: ['Human Resources'], parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')], responses: [
        new OA\Response(response: 200, description: 'Application', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function recordConsent(RecordConsentRequest $request, string $id): HrRecordResource
    {
        return new HrRecordResource($this->recruiting->recordConsent(JobApplication::query()->findOrFail($id), (string) $request->validated('consent_until')));
    }

    /**
     * Erase an application.
     */
    #[OA\Delete(path: '/api/v1/hr/job-applications/{id}', operationId: 'eraseJobApplication', description: 'Erase an application at once, with its history and notes, for a request of the candidate. It cannot be undone.', summary: 'Erase job application', security: [['bearerAuth' => []]], tags: ['Human Resources'], parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')], responses: [
        new OA\Response(response: 204, description: 'Erased'),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
    ])]
    public function erase(string $id): JsonResponse
    {
        $this->recruiting->erase(JobApplication::query()->findOrFail($id));

        return response()->json(null, 204);
    }

    /**
     * Hire a candidate.
     */
    #[OA\Post(path: '/api/v1/hr/job-applications/{id}/hire', operationId: 'hireCandidate', description: 'Turn the candidate into an employee through the ordinary hire and start the onboarding from a template.', summary: 'Hire candidate', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesHireCandidateRequest')), tags: ['Human Resources'], parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')], responses: [
        new OA\Response(response: 201, description: 'Employee', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function hire(HireCandidateRequest $request, string $id): JsonResponse
    {
        $employee = $this->recruiting->hire(JobApplication::query()->with('posting')->findOrFail($id), $request->validated(), $this->actor($request));

        return response()->json(['data' => ['employee_id' => $employee->id, 'employee_number' => $employee->employee_number]], 201);
    }

    /**
     * Set the retention.
     */
    #[OA\Put(path: '/api/v1/hr/recruiting-settings', operationId: 'saveRecruitingSettings', description: 'Set how many days candidate data stay after the end of an application. It is a choice of the company: the law gives no fixed term.', summary: 'Save recruiting settings', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['retention_days'], properties: [new OA\Property(property: 'retention_days', type: 'integer', minimum: 1, maximum: 3650)], type: 'object')), tags: ['Human Resources'], responses: [
        new OA\Response(response: 200, description: 'Settings', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function saveSettings(SaveRecruitingSettingsRequest $request): JsonResponse
    {
        $settings = RecruitingSetting::query()->first() ?? new RecruitingSetting;
        $settings->fill($request->validated())->save();

        return response()->json(['data' => $settings]);
    }

    /**
     * Create an onboarding template.
     */
    #[OA\Post(path: '/api/v1/hr/onboarding-templates', operationId: 'storeOnboardingTemplate', description: 'Create a list of onboarding tasks, each due some days after the start.', summary: 'Create onboarding template', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/HumanResourcesSaveOnboardingTemplateRequest')), tags: ['Human Resources'], responses: [
        new OA\Response(response: 201, description: 'Template', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollUnprocessable', response: 422),
    ])]
    public function storeTemplate(SaveOnboardingTemplateRequest $request): JsonResponse
    {
        return (new HrRecordResource(OnboardingTemplate::create($request->validated())))->response()->setStatusCode(201);
    }

    /**
     * List onboardings.
     */
    #[OA\Get(path: '/api/v1/hr/onboarding-checklists', operationId: 'listOnboardingChecklists', description: 'Return the onboarding checklists with their tasks, optionally of one employee.', summary: 'List onboarding checklists', security: [['bearerAuth' => []]], tags: ['Human Resources'], parameters: [new OA\Parameter(name: 'employee_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Checklists', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
    ])]
    public function checklists(Request $request): AnonymousResourceCollection
    {
        return HrRecordResource::collection(OnboardingChecklist::query()
            ->with('tasks')
            ->when($request->query('employee_id'), static fn ($query, $employeeId) => $query->where('employee_id', $employeeId))
            ->orderByDesc('created_at')
            ->get());
    }

    /**
     * Complete a task.
     */
    #[OA\Post(path: '/api/v1/hr/onboarding-tasks/{id}/complete', operationId: 'completeOnboardingTask', description: 'Mark a task as done. The last task closes the checklist.', summary: 'Complete onboarding task', security: [['bearerAuth' => []]], tags: ['Human Resources'], parameters: [new OA\Parameter(ref: '#/components/parameters/PayrollRecordId')], responses: [
        new OA\Response(response: 200, description: 'Task', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(ref: '#/components/responses/PayrollUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/PayrollForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/PayrollNotFound', response: 404),
    ])]
    public function completeTask(Request $request, string $id): HrRecordResource
    {
        return new HrRecordResource($this->recruiting->completeTask(OnboardingTask::query()->findOrFail($id), $this->actor($request)));
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
