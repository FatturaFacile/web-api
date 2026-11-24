<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Controllers\Recruiting;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\HumanResources\Actions\PublishJobOpeningAction;
use Modules\HumanResources\Actions\SaveJobOpeningAction;
use Modules\HumanResources\Contracts\RecruitmentServiceInterface;
use Modules\HumanResources\Enums\ApplicationStage;
use Modules\HumanResources\Enums\RecruitingLocale;
use Modules\HumanResources\Http\Requests\Recruiting\CloseJobOpeningRequest;
use Modules\HumanResources\Http\Requests\Recruiting\PublishJobOpeningRequest;
use Modules\HumanResources\Http\Requests\Recruiting\StoreJobOpeningRequest;
use Modules\HumanResources\Http\Resources\CandidateApplicationCardResource;
use Modules\HumanResources\Http\Resources\JobOpeningResource;
use Modules\HumanResources\Models\CandidateApplication;
use Modules\HumanResources\Models\JobOpening;
use OpenApi\Attributes as OA;

final class JobOpeningController extends Controller
{
    use RespondsWithRecruitingPages;

    public function __construct(
        private readonly SaveJobOpeningAction $saveJobOpening,
        private readonly PublishJobOpeningAction $publishJobOpening,
        private readonly RecruitmentServiceInterface $recruitment
    ) {}

    #[OA\Get(
        path: '/api/v1/hr/recruiting/job-openings',
        operationId: 'hrRecruitingListJobOpenings',
        description: 'Paginated job openings with their application count, newest first.',
        summary: 'List job openings',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Recruiting'],
        parameters: [new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Opening status', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'department_id', in: 'query', required: false, description: 'Department filter', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Matches reference, site and translated texts', schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Page size', schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Paginated job openings', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')), new OA\Property(property: 'meta', type: 'object', properties: [new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'per_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer')])])),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', JobOpening::class);

        $query = JobOpening::query()->with(['position', 'department'])->withCount('applications');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        if ($request->filled('department_id')) {
            $this->whereUuidFilter($query, 'department_id', $request->query('department_id'));
        }

        if ($request->filled('search')) {
            $term = '%'.mb_strtolower((string) $request->query('search')).'%';
            $query->where(function (Builder $builder) use ($term): void {
                $builder->whereRaw('LOWER(reference) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(location_site) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(CAST(translations AS TEXT)) LIKE ?', [$term]);
            });
        }

        $openings = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($this->perPage($request));

        return $this->paginatedResponse($openings, fn (JobOpening $opening): array => (new JobOpeningResource($opening))->resolve($request));
    }

    #[OA\Post(
        path: '/api/v1/hr/recruiting/job-openings',
        operationId: 'hrRecruitingCreateJobOpening',
        description: 'Creates a job opening for a position with per-language texts.',
        summary: 'Create job opening',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Recruiting'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['position_id', 'location_site', 'employment_type', 'published_languages', 'translations'], properties: [new OA\Property(property: 'position_id', type: 'string', format: 'uuid'), new OA\Property(property: 'location_site', type: 'string', maxLength: 150), new OA\Property(property: 'employment_type', type: 'string', enum: ['full_time', 'part_time', 'contractor', 'apprentice', 'seasonal']), new OA\Property(property: 'openings_count', type: 'integer', minimum: 1, maximum: 1000, nullable: true), new OA\Property(property: 'salary_range_public', type: 'object', nullable: true, properties: [new OA\Property(property: 'min', type: 'number', nullable: true), new OA\Property(property: 'max', type: 'number', nullable: true), new OA\Property(property: 'currency', type: 'string', enum: ['EUR', 'CHF'], nullable: true)]), new OA\Property(property: 'published_languages', type: 'array', minItems: 1, items: new OA\Items(type: 'string', enum: ['it-CH', 'de-CH', 'fr-CH', 'it-IT', 'en'])), new OA\Property(property: 'translations', type: 'object', description: 'Per-locale texts keyed by locale', additionalProperties: new OA\AdditionalProperties(type: 'object', properties: [new OA\Property(property: 'title', type: 'string', nullable: true), new OA\Property(property: 'description', type: 'string', nullable: true), new OA\Property(property: 'requirements', type: 'string', nullable: true), new OA\Property(property: 'benefits', type: 'string', nullable: true)])), new OA\Property(property: 'closes_at', type: 'string', format: 'date', nullable: true)])),
        responses: [
            new OA\Response(response: 201, description: 'Job opening created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function store(StoreJobOpeningRequest $request): JsonResponse
    {
        $this->authorize('create', JobOpening::class);

        /** @var array{position_id: string, location_site: string, employment_type: string, openings_count?: int|null, salary_range_public?: array{min?: float|int|string|null, max?: float|int|string|null, currency?: string|null}|null, published_languages: list<string>, translations: array<string, array<string, string|null>>, closes_at?: string|null} $data */
        $data = $request->validated();
        $opening = $this->saveJobOpening->create($data, $this->actorId($request));

        return response()->json(['data' => (new JobOpeningResource($this->loadOpening($opening)))->resolve($request)], 201);
    }

    #[OA\Get(
        path: '/api/v1/hr/recruiting/job-openings/{id}',
        operationId: 'hrRecruitingGetJobOpening',
        description: 'A job opening with position, department and application count.',
        summary: 'Get job opening',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Recruiting'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the job opening', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Job opening', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
        ]
    )]
    public function show(Request $request, string $id): JsonResponse
    {
        $opening = JobOpening::query()->findOrFail($id);
        $this->authorize('view', $opening);

        return response()->json(['data' => (new JobOpeningResource($this->loadOpening($opening)))->resolve($request)]);
    }

    #[OA\Put(
        path: '/api/v1/hr/recruiting/job-openings/{id}',
        operationId: 'hrRecruitingUpdateJobOpening',
        description: 'Partial update: only the sent fields change.',
        summary: 'Update job opening',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Recruiting'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the job opening', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'position_id', type: 'string', format: 'uuid'), new OA\Property(property: 'location_site', type: 'string', maxLength: 150), new OA\Property(property: 'employment_type', type: 'string', enum: ['full_time', 'part_time', 'contractor', 'apprentice', 'seasonal']), new OA\Property(property: 'openings_count', type: 'integer', minimum: 1, maximum: 1000, nullable: true), new OA\Property(property: 'salary_range_public', type: 'object', nullable: true, properties: [new OA\Property(property: 'min', type: 'number', nullable: true), new OA\Property(property: 'max', type: 'number', nullable: true), new OA\Property(property: 'currency', type: 'string', enum: ['EUR', 'CHF'], nullable: true)]), new OA\Property(property: 'published_languages', type: 'array', minItems: 1, items: new OA\Items(type: 'string', enum: ['it-CH', 'de-CH', 'fr-CH', 'it-IT', 'en'])), new OA\Property(property: 'translations', type: 'object', description: 'Per-locale texts keyed by locale', additionalProperties: new OA\AdditionalProperties(type: 'object', properties: [new OA\Property(property: 'title', type: 'string', nullable: true), new OA\Property(property: 'description', type: 'string', nullable: true), new OA\Property(property: 'requirements', type: 'string', nullable: true), new OA\Property(property: 'benefits', type: 'string', nullable: true)])), new OA\Property(property: 'closes_at', type: 'string', format: 'date', nullable: true)])),
        responses: [
            new OA\Response(response: 200, description: 'Job opening', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function update(StoreJobOpeningRequest $request, string $id): JsonResponse
    {
        $opening = JobOpening::query()->findOrFail($id);
        $this->authorize('update', $opening);

        $opening = $this->saveJobOpening->update($opening, $request->validated());

        return response()->json(['data' => (new JobOpeningResource($this->loadOpening($opening)))->resolve($request)]);
    }

    #[OA\Post(
        path: '/api/v1/hr/recruiting/job-openings/{id}/publish',
        operationId: 'hrRecruitingPublishJobOpening',
        description: 'Publishes the opening on the careers page, or internally only.',
        summary: 'Publish job opening',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Recruiting'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the job opening', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'internal_only', type: 'boolean', description: 'Publish for internal candidates only')])),
        responses: [
            new OA\Response(response: 200, description: 'Job opening', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/HrConflict', response: 409),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function publish(PublishJobOpeningRequest $request, string $id): JsonResponse
    {
        $opening = JobOpening::query()->findOrFail($id);
        $this->authorize('publish', $opening);

        $opening = $this->publishJobOpening->execute($opening, $request->boolean('internal_only'));

        return response()->json(['data' => (new JobOpeningResource($this->loadOpening($opening)))->resolve($request)]);
    }

    #[OA\Post(
        path: '/api/v1/hr/recruiting/job-openings/{id}/close',
        operationId: 'hrRecruitingCloseJobOpening',
        description: 'Closes the opening as filled or cancelled.',
        summary: 'Close job opening',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Recruiting'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the job opening', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['outcome'], properties: [new OA\Property(property: 'outcome', type: 'string', enum: ['filled', 'cancelled'])])),
        responses: [
            new OA\Response(response: 200, description: 'Job opening', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/HrConflict', response: 409),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function close(CloseJobOpeningRequest $request, string $id): JsonResponse
    {
        $opening = JobOpening::query()->findOrFail($id);
        $this->authorize('close', $opening);

        $opening = $this->publishJobOpening->close($opening, (string) $request->input('outcome'));

        return response()->json(['data' => (new JobOpeningResource($this->loadOpening($opening)))->resolve($request)]);
    }

    #[OA\Get(
        path: '/api/v1/hr/recruiting/job-openings/{id}/board',
        operationId: 'hrRecruitingJobOpeningBoard',
        description: 'The opening with its applications grouped by pipeline stage (label, enabled flag, count and cards per stage).',
        summary: 'Kanban board of a job opening',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Recruiting'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the job opening', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Matches candidate name and e-mail', schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Board', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'job_opening', type: 'object'), new OA\Property(property: 'stages', type: 'array', items: new OA\Items(type: 'object', properties: [new OA\Property(property: 'stage', type: 'string'), new OA\Property(property: 'label', type: 'string'), new OA\Property(property: 'enabled', type: 'boolean'), new OA\Property(property: 'count', type: 'integer'), new OA\Property(property: 'applications', type: 'array', items: new OA\Items(type: 'object'))]))])])),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
        ]
    )]
    public function board(Request $request, string $id): JsonResponse
    {
        $opening = JobOpening::query()->findOrFail($id);
        $this->authorize('view', $opening);

        $settings = $this->recruitment->settings();
        $locale = RecruitingLocale::fromAppLocale(app()->getLocale());

        $query = CandidateApplication::query()
            ->with('jobOpening')
            ->withCount('scorecards')
            ->withAvg('scorecards', 'overall_score')
            ->where('job_opening_id', $opening->id);

        if ($request->filled('search')) {
            $term = '%'.mb_strtolower((string) $request->query('search')).'%';
            $query->where(function (Builder $builder) use ($term): void {
                $builder->whereRaw('LOWER(first_name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$term]);
            });
        }

        $applications = $query->orderBy('board_position')->orderBy('created_at')->get()->groupBy(
            static fn (CandidateApplication $application): string => $application->stage->value
        );

        $stages = array_map(static function (ApplicationStage $stage) use ($applications, $settings, $locale): array {
            $cards = $applications->get($stage->value, collect());

            return [
                'stage' => $stage->value,
                'label' => $settings->stageLabel($stage, $locale),
                'enabled' => $settings->stageEnabled($stage),
                'count' => $cards->count(),
                'applications' => $cards->map(
                    static fn (CandidateApplication $application): array => CandidateApplicationCardResource::cardFields($application)
                )->values()->all(),
            ];
        }, ApplicationStage::cases());

        return response()->json([
            'data' => [
                'job_opening' => (new JobOpeningResource($this->loadOpening($opening)))->resolve($request),
                'stages' => $stages,
            ],
        ]);
    }

    private function loadOpening(JobOpening $opening): JobOpening
    {
        return $opening->load(['position', 'department'])->loadCount('applications');
    }
}
