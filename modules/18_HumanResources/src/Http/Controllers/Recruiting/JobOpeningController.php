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

final class JobOpeningController extends Controller
{
    use RespondsWithRecruitingPages;

    public function __construct(
        private readonly SaveJobOpeningAction $saveJobOpening,
        private readonly PublishJobOpeningAction $publishJobOpening,
        private readonly RecruitmentServiceInterface $recruitment
    ) {}

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

    public function store(StoreJobOpeningRequest $request): JsonResponse
    {
        $this->authorize('create', JobOpening::class);

        /** @var array{position_id: string, location_site: string, employment_type: string, openings_count?: int|null, salary_range_public?: array{min?: float|int|string|null, max?: float|int|string|null, currency?: string|null}|null, published_languages: list<string>, translations: array<string, array<string, string|null>>, closes_at?: string|null} $data */
        $data = $request->validated();
        $opening = $this->saveJobOpening->create($data, $this->actorId($request));

        return response()->json(['data' => (new JobOpeningResource($this->loadOpening($opening)))->resolve($request)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $opening = JobOpening::query()->findOrFail($id);
        $this->authorize('view', $opening);

        return response()->json(['data' => (new JobOpeningResource($this->loadOpening($opening)))->resolve($request)]);
    }

    public function update(StoreJobOpeningRequest $request, string $id): JsonResponse
    {
        $opening = JobOpening::query()->findOrFail($id);
        $this->authorize('update', $opening);

        $opening = $this->saveJobOpening->update($opening, $request->validated());

        return response()->json(['data' => (new JobOpeningResource($this->loadOpening($opening)))->resolve($request)]);
    }

    public function publish(PublishJobOpeningRequest $request, string $id): JsonResponse
    {
        $opening = JobOpening::query()->findOrFail($id);
        $this->authorize('publish', $opening);

        $opening = $this->publishJobOpening->execute($opening, $request->boolean('internal_only'));

        return response()->json(['data' => (new JobOpeningResource($this->loadOpening($opening)))->resolve($request)]);
    }

    public function close(CloseJobOpeningRequest $request, string $id): JsonResponse
    {
        $opening = JobOpening::query()->findOrFail($id);
        $this->authorize('close', $opening);

        $opening = $this->publishJobOpening->close($opening, (string) $request->input('outcome'));

        return response()->json(['data' => (new JobOpeningResource($this->loadOpening($opening)))->resolve($request)]);
    }

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
