<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Controllers\Recruiting;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Modules\HumanResources\Actions\HireCandidateAndCreateEmployeeAction;
use Modules\HumanResources\Actions\MoveCandidateStageAction;
use Modules\HumanResources\Actions\SubmitCandidateApplicationAction;
use Modules\HumanResources\Contracts\CandidateDocumentStorageInterface;
use Modules\HumanResources\Contracts\RecruitmentServiceInterface;
use Modules\HumanResources\DTOs\HireCandidateDTO;
use Modules\HumanResources\DTOs\MoveCandidateStageDTO;
use Modules\HumanResources\DTOs\SubmitCandidateApplicationDTO;
use Modules\HumanResources\Enums\ApplicationSource;
use Modules\HumanResources\Enums\ApplicationStage;
use Modules\HumanResources\Enums\RecruitingLocale;
use Modules\HumanResources\Enums\RejectionReasonCode;
use Modules\HumanResources\Exceptions\RecruitingRuleViolationException;
use Modules\HumanResources\Http\Requests\Recruiting\ExtendConsentRequest;
use Modules\HumanResources\Http\Requests\Recruiting\HireCandidateRequest;
use Modules\HumanResources\Http\Requests\Recruiting\MoveCandidateStageRequest;
use Modules\HumanResources\Http\Requests\Recruiting\StoreCandidateApplicationRequest;
use Modules\HumanResources\Http\Requests\Recruiting\UpdateCandidateApplicationRequest;
use Modules\HumanResources\Http\Resources\CandidateApplicationCardResource;
use Modules\HumanResources\Http\Resources\CandidateApplicationResource;
use Modules\HumanResources\Http\Resources\JobOpeningResource;
use Modules\HumanResources\Models\CandidateApplication;
use Modules\HumanResources\Services\CandidateRetentionService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CandidateApplicationController extends Controller
{
    use RespondsWithRecruitingPages;

    public function __construct(
        private readonly SubmitCandidateApplicationAction $submitApplication,
        private readonly MoveCandidateStageAction $moveStage,
        private readonly HireCandidateAndCreateEmployeeAction $hireCandidate,
        private readonly CandidateRetentionService $retention,
        private readonly CandidateDocumentStorageInterface $documents,
        private readonly RecruitmentServiceInterface $recruitment
    ) {}

    #[OA\Get(
        path: '/api/v1/hr/recruiting/applications',
        operationId: 'hrRecruitingListApplications',
        description: 'Paginated candidate applications with scorecard count and average score.',
        summary: 'List applications',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Recruiting'],
        parameters: [new OA\Parameter(name: 'job_opening_id', in: 'query', required: false, description: 'Opening filter', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'stage', in: 'query', required: false, description: 'Pipeline stage', schema: new OA\Schema(type: 'string', enum: ['applied', 'hr_screening', 'technical_interview', 'manager_interview', 'offer_extended', 'hired', 'rejected'])), new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Matches name, e-mail and reference', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'consent_expiring_within_days', in: 'query', required: false, description: 'Only applications whose privacy consent expires within these days', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Page size', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Paginated applications', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'per_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CandidateApplication::class);

        $query = CandidateApplication::query()
            ->with('jobOpening')
            ->withCount('scorecards')
            ->withAvg('scorecards', 'overall_score');

        if ($request->filled('job_opening_id')) {
            $this->whereUuidFilter($query, 'job_opening_id', $request->query('job_opening_id'));
        }

        if ($request->filled('stage')) {
            $query->where('stage', (string) $request->query('stage'));
        }

        if ($request->filled('search')) {
            $term = '%'.mb_strtolower((string) $request->query('search')).'%';
            $query->where(function (Builder $builder) use ($term): void {
                $builder->whereRaw('LOWER(first_name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(reference) LIKE ?', [$term]);
            });
        }

        if ($request->filled('consent_expiring_within_days')) {
            $days = max(0, (int) $request->query('consent_expiring_within_days'));
            $query->whereNull('anonymized_at')
                ->whereNotNull('privacy_consent_expires_at')
                ->whereBetween('privacy_consent_expires_at', [now(), now()->addDays($days)]);
        }

        $applications = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($this->perPage($request));

        return $this->paginatedResponse(
            $applications,
            static fn (CandidateApplication $application): array => CandidateApplicationCardResource::cardFields($application)
        );
    }

    #[OA\Post(
        path: '/api/v1/hr/recruiting/applications',
        operationId: 'hrRecruitingCreateApplication',
        description: 'HR enters an application on behalf of a candidate (multipart, optional CV that is parsed).',
        summary: 'Create application manually',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Recruiting'],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(type: 'object', required: ['job_opening_id', 'first_name', 'last_name', 'email'], properties: [new OA\Property(property: 'job_opening_id', type: 'string', format: 'uuid'), new OA\Property(property: 'first_name', type: 'string', maxLength: 100), new OA\Property(property: 'last_name', type: 'string', maxLength: 100), new OA\Property(property: 'email', type: 'string', format: 'email'), new OA\Property(property: 'phone', type: 'string', maxLength: 50, nullable: true), new OA\Property(property: 'cover_letter', type: 'string', maxLength: 20000, nullable: true), new OA\Property(property: 'locale', type: 'string', enum: ['it-CH', 'de-CH', 'fr-CH', 'it-IT', 'en'], nullable: true), new OA\Property(property: 'source', type: 'string', enum: ['careers_page', 'manual', 'referral', 'job_board'], nullable: true, description: 'Defaults to manual'), new OA\Property(property: 'retention_months', type: 'integer', nullable: true, description: 'Allowed values from the recruiting configuration (6 or 12)'), new OA\Property(property: 'cv', type: 'string', format: 'binary', nullable: true, description: 'pdf, doc or docx')]))),
        responses: [
            new OA\Response(response: 201, description: 'Application created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function store(StoreCandidateApplicationRequest $request): JsonResponse
    {
        $this->authorize('create', CandidateApplication::class);

        $settings = $this->recruitment->settings();
        $application = $this->submitApplication->execute(new SubmitCandidateApplicationDTO(
            jobOpeningId: (string) $request->input('job_opening_id'),
            firstName: (string) $request->input('first_name'),
            lastName: (string) $request->input('last_name'),
            email: (string) $request->input('email'),
            source: ApplicationSource::tryFrom((string) $request->input('source')) ?? ApplicationSource::Manual,
            locale: RecruitingLocale::tryFrom((string) $request->input('locale')) ?? RecruitingLocale::fromAppLocale(app()->getLocale()),
            retentionMonths: $request->filled('retention_months') ? (int) $request->input('retention_months') : (int) $settings->retention_default_months,
            consentTextVersion: 'manual',
            phone: $request->filled('phone') ? (string) $request->input('phone') : null,
            coverLetter: $request->filled('cover_letter') ? (string) $request->input('cover_letter') : null,
            cv: $request->file('cv') instanceof UploadedFile ? $request->file('cv') : null,
            actorUserId: $this->actorId($request)
        ));

        return response()->json(['data' => $this->detail($application, $request)], 201);
    }

    #[OA\Get(
        path: '/api/v1/hr/recruiting/applications/{id}',
        operationId: 'hrRecruitingGetApplication',
        description: 'Application detail with history, e-mails and current offer.',
        summary: 'Get application',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Recruiting'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the application', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Application', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
        ]
    )]
    public function show(Request $request, string $id): JsonResponse
    {
        $application = $this->find($id);
        $this->authorize('view', $application);

        return response()->json(['data' => $this->detail($application, $request)]);
    }

    #[OA\Patch(
        path: '/api/v1/hr/recruiting/applications/{id}',
        operationId: 'hrRecruitingUpdateApplication',
        description: 'Updates the internal notes and the phone number. Anonymised applications cannot be changed.',
        summary: 'Update application',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Recruiting'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the application', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'notes', type: 'string', maxLength: 20000, nullable: true), new OA\Property(property: 'phone', type: 'string', maxLength: 50, nullable: true)])),
        responses: [
            new OA\Response(response: 200, description: 'Application', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/HrConflict', response: 409),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function update(UpdateCandidateApplicationRequest $request, string $id): JsonResponse
    {
        $application = $this->find($id);
        $this->authorize('update', $application);

        if ($application->isAnonymized()) {
            throw RecruitingRuleViolationException::candidateAnonymized();
        }

        $application->fill($request->validated());
        $application->save();

        return response()->json(['data' => $this->detail($application, $request)]);
    }

    #[OA\Get(
        path: '/api/v1/hr/recruiting/applications/{id}/cv',
        operationId: 'hrRecruitingDownloadApplicationCv',
        description: 'Streams the stored CV; 404 when none is available or the candidate was anonymised.',
        summary: 'Download candidate CV',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Recruiting'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the application', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The CV file', content: new OA\MediaType(mediaType: 'application/octet-stream', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
        ]
    )]
    public function cv(string $id): StreamedResponse|JsonResponse
    {
        $application = $this->find($id);
        $this->authorize('view', $application);

        $storageKey = $application->cv_storage_key;
        if ($application->isAnonymized() || $storageKey === null || ! $this->documents->exists($storageKey)) {
            return response()->json(['message' => 'No CV available for this application.'], Response::HTTP_NOT_FOUND);
        }

        return $this->documents->download($storageKey, (string) ($application->cv_original_name ?: 'cv'), $application->cv_mime_type);
    }

    #[OA\Post(
        path: '/api/v1/hr/recruiting/applications/{id}/reparse-cv',
        operationId: 'hrRecruitingReparseApplicationCv',
        description: 'Runs the CV parser again on the stored file.',
        summary: 'Re-parse candidate CV',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Recruiting'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the application', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Application', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
        ]
    )]
    public function reparseCv(Request $request, string $id): JsonResponse
    {
        $application = $this->find($id);
        $this->authorize('update', $application);

        $application = $this->submitApplication->reparse($application);

        return response()->json(['data' => $this->detail($application, $request)]);
    }

    #[OA\Post(
        path: '/api/v1/hr/recruiting/applications/{id}/move',
        operationId: 'hrRecruitingMoveApplication',
        description: 'Moves the application along the pipeline (kanban drag and drop); rejecting requires a reason code. Stage e-mails are sent when enabled.',
        summary: 'Move application to a stage',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Recruiting'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the application', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['stage'], properties: [new OA\Property(property: 'stage', type: 'string', enum: ['applied', 'hr_screening', 'technical_interview', 'manager_interview', 'offer_extended', 'hired', 'rejected']), new OA\Property(property: 'board_position', type: 'integer', minimum: 0, nullable: true), new OA\Property(property: 'rejection_reason_code', type: 'string', enum: ['not_qualified', 'salary_mismatch', 'position_filled', 'withdrawn', 'no_show', 'culture_fit', 'other'], nullable: true, description: 'Required when stage is rejected'), new OA\Property(property: 'rejection_note', type: 'string', maxLength: 2000, nullable: true), new OA\Property(property: 'send_email', type: 'boolean', nullable: true, description: 'Override the stage auto-email setting'), new OA\Property(property: 'note', type: 'string', maxLength: 2000, nullable: true)])),
        responses: [
            new OA\Response(response: 200, description: 'Application', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/HrConflict', response: 409),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function move(MoveCandidateStageRequest $request, string $id): JsonResponse
    {
        $application = $this->find($id);
        $this->authorize('update', $application);

        $application = $this->moveStage->execute(new MoveCandidateStageDTO(
            applicationId: (string) $application->id,
            stage: ApplicationStage::from((string) $request->input('stage')),
            boardPosition: $request->filled('board_position') ? (int) $request->input('board_position') : null,
            rejectionReasonCode: RejectionReasonCode::tryFrom((string) $request->input('rejection_reason_code')),
            rejectionNote: $request->filled('rejection_note') ? (string) $request->input('rejection_note') : null,
            sendEmail: $request->has('send_email') && $request->input('send_email') !== null ? $request->boolean('send_email') : null,
            note: $request->filled('note') ? (string) $request->input('note') : null,
            actorUserId: $this->actorId($request)
        ));

        return response()->json(['data' => $this->detail($application, $request)]);
    }

    #[OA\Post(
        path: '/api/v1/hr/recruiting/applications/{id}/extend-consent',
        operationId: 'hrRecruitingExtendApplicationConsent',
        description: 'Extends the privacy-consent retention period of the candidate.',
        summary: 'Extend candidate consent',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Recruiting'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the application', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['months'], properties: [new OA\Property(property: 'months', type: 'integer', description: 'Allowed values from the recruiting configuration (6 or 12)')])),
        responses: [
            new OA\Response(response: 200, description: 'Application', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/HrConflict', response: 409),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function extendConsent(ExtendConsentRequest $request, string $id): JsonResponse
    {
        $application = $this->find($id);
        $this->authorize('update', $application);

        $application = $this->retention->extendConsent($application, (int) $request->input('months'));

        return response()->json(['data' => $this->detail($application, $request)]);
    }

    #[OA\Post(
        path: '/api/v1/hr/recruiting/applications/{id}/anonymize',
        operationId: 'hrRecruitingAnonymizeApplication',
        description: 'GDPR erasure: irreversibly removes the personal data of the candidate; hired candidates cannot be anonymised.',
        summary: 'Anonymise application',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Recruiting'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the application', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Application', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/HrConflict', response: 409),
        ]
    )]
    public function anonymize(Request $request, string $id): JsonResponse
    {
        $application = $this->find($id);
        $this->authorize('anonymize', $application);

        if ($application->isHired()) {
            throw RecruitingRuleViolationException::alreadyHired();
        }

        $application = $this->retention->anonymize($application, CandidateRetentionService::REASON_ERASURE_REQUEST);

        return response()->json(['data' => $this->detail($application, $request)]);
    }

    #[OA\Post(
        path: '/api/v1/hr/recruiting/applications/{id}/hire',
        operationId: 'hrRecruitingHireApplication',
        description: 'Hires the candidate: creates the employee record and optionally starts the onboarding checklist.',
        summary: 'Hire candidate',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Recruiting'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the application', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'hire_date', type: 'string', format: 'date', nullable: true), new OA\Property(property: 'work_email', type: 'string', format: 'email', nullable: true), new OA\Property(property: 'manager_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'start_onboarding', type: 'boolean', nullable: true, description: 'Defaults to true')])),
        responses: [
            new OA\Response(response: 201, description: 'Hire result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'application', type: 'object'), new OA\Property(property: 'employee', type: 'object', properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'employee_number', type: 'string'), new OA\Property(property: 'full_name', type: 'string'), new OA\Property(property: 'hire_date', type: 'string', format: 'date', nullable: true)]), new OA\Property(property: 'job_opening', type: 'object'), new OA\Property(property: 'onboarding', type: 'object')])])),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/HrConflict', response: 409),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function hire(HireCandidateRequest $request, string $id): JsonResponse
    {
        $application = $this->find($id);
        $this->authorize('hire', $application);

        $result = $this->hireCandidate->execute(new HireCandidateDTO(
            applicationId: (string) $application->id,
            hireDate: $request->filled('hire_date') ? (string) $request->input('hire_date') : null,
            workEmail: $request->filled('work_email') ? (string) $request->input('work_email') : null,
            managerId: $request->filled('manager_id') ? (string) $request->input('manager_id') : null,
            startOnboarding: $request->has('start_onboarding') && $request->input('start_onboarding') !== null ? $request->boolean('start_onboarding') : true,
            actorUserId: $this->actorId($request)
        ));

        $employee = $result['employee'];
        $opening = $result['job_opening']->load(['position', 'department'])->loadCount('applications');

        return response()->json([
            'data' => [
                'application' => $this->detail($result['application'], $request),
                'employee' => [
                    'id' => (string) $employee->id,
                    'employee_number' => (string) $employee->employee_number,
                    'full_name' => (string) $employee->full_name,
                    'hire_date' => $employee->hire_date?->toDateString(),
                ],
                'job_opening' => (new JobOpeningResource($opening))->resolve($request),
                'onboarding' => $result['onboarding']->toArray(),
            ],
        ], 201);
    }

    private function find(string $id): CandidateApplication
    {
        /** @var CandidateApplication $application */
        $application = CandidateApplication::query()->findOrFail($id);

        return $application;
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(CandidateApplication $application, Request $request): array
    {
        $application = $application->fresh() ?? $application;
        $application->load(['jobOpening', 'history', 'emails', 'currentOffer'])
            ->loadCount('scorecards')
            ->loadAvg('scorecards', 'overall_score');

        return (new CandidateApplicationResource($application))->resolve($request);
    }
}
