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

    public function show(Request $request, string $id): JsonResponse
    {
        $application = $this->find($id);
        $this->authorize('view', $application);

        return response()->json(['data' => $this->detail($application, $request)]);
    }

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

    public function reparseCv(Request $request, string $id): JsonResponse
    {
        $application = $this->find($id);
        $this->authorize('update', $application);

        $application = $this->submitApplication->reparse($application);

        return response()->json(['data' => $this->detail($application, $request)]);
    }

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

    public function extendConsent(ExtendConsentRequest $request, string $id): JsonResponse
    {
        $application = $this->find($id);
        $this->authorize('update', $application);

        $application = $this->retention->extendConsent($application, (int) $request->input('months'));

        return response()->json(['data' => $this->detail($application, $request)]);
    }

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
