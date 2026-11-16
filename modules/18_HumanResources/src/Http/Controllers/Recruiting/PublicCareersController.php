<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Controllers\Recruiting;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Modules\HumanResources\Actions\SubmitCandidateApplicationAction;
use Modules\HumanResources\Contracts\RecruitmentServiceInterface;
use Modules\HumanResources\DTOs\SubmitCandidateApplicationDTO;
use Modules\HumanResources\Enums\ApplicationSource;
use Modules\HumanResources\Enums\RecruitingLocale;
use Modules\HumanResources\Http\Middleware\ResolveCareersTenant;
use Modules\HumanResources\Http\Requests\Recruiting\PublicApplyRequest;
use Modules\HumanResources\Models\JobOpening;
use Modules\HumanResources\Models\RecruitingSettings;
use Modules\HumanResources\Services\JobFeedBuilder;
use Modules\HumanResources\Support\RecruitingDefaults;

/**
 * Unauthenticated careers endpoints. The tenant comes only from the careers slug (`ResolveCareersTenant`); only
 * `published` openings are exposed and no candidate data is ever returned.
 */
final class PublicCareersController extends Controller
{
    use RespondsWithRecruitingPages;

    public function __construct(
        private readonly JobFeedBuilder $feedBuilder,
        private readonly RecruitmentServiceInterface $recruitment,
        private readonly SubmitCandidateApplicationAction $submitApplication
    ) {}

    public function show(Request $request): JsonResponse
    {
        $settings = $this->settings($request);
        $locale = $this->requestedLocale($request);

        return response()->json([
            'data' => [
                'company_name' => $this->recruitment->companyName($settings),
                'intro' => $settings->localizedText('intro', $locale),
                'privacy_notice' => $settings->localizedText('privacy_notice', $locale),
                'privacy_policy_url' => $settings->privacy_policy_url,
                'consent_text_version' => (string) $settings->consent_text_version,
                'available_locales' => $this->availableLocales($settings),
                'retention_months' => (int) $settings->retention_default_months,
            ],
        ]);
    }

    public function jobs(Request $request): JsonResponse
    {
        $settings = $this->settings($request);
        $locale = $this->requestedLocale($request) ?? RecruitingLocale::En->value;
        $query = $this->feedBuilder->publicOpeningsQuery();

        if ($request->filled('department_id')) {
            $this->whereUuidFilter($query, 'department_id', $request->query('department_id'));
        }

        if ($request->filled('employment_type')) {
            $query->where('employment_type', (string) $request->query('employment_type'));
        }

        if ($request->filled('location_site')) {
            $query->whereRaw('LOWER(location_site) = ?', [mb_strtolower((string) $request->query('location_site'))]);
        }

        if ($request->filled('search')) {
            $term = '%'.mb_strtolower((string) $request->query('search')).'%';
            $query->where(function (Builder $builder) use ($term): void {
                $builder->whereRaw('LOWER(reference) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(location_site) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(CAST(translations AS TEXT)) LIKE ?', [$term]);
            });
        }

        $openings = $query->orderByDesc('published_at')->orderByDesc('id')->paginate($this->perPage($request));

        return $this->paginatedResponse(
            $openings,
            fn (JobOpening $opening): array => $this->feedBuilder->publicJob($opening, $locale, (string) $settings->careers_slug)
        );
    }

    public function job(Request $request, string $careersSlug, string $id): JsonResponse
    {
        $settings = $this->settings($request);

        /** @var JobOpening $opening */
        $opening = $this->feedBuilder->publicOpeningsQuery()->findOrFail($id);

        return response()->json([
            'data' => $this->feedBuilder->publicJob($opening, $this->requestedLocale($request), (string) $settings->careers_slug),
        ]);
    }

    public function apply(PublicApplyRequest $request, string $careersSlug, string $id): JsonResponse
    {
        $settings = $this->settings($request);

        /** @var JobOpening $opening */
        $opening = $this->feedBuilder->publicOpeningsQuery()->findOrFail($id);
        $locale = RecruitingLocale::from((string) $request->input('locale'));
        $cv = $request->file('cv');

        $application = $this->submitApplication->execute(new SubmitCandidateApplicationDTO(
            jobOpeningId: (string) $opening->id,
            firstName: (string) $request->input('first_name'),
            lastName: (string) $request->input('last_name'),
            email: (string) $request->input('email'),
            source: ApplicationSource::CareersPage,
            locale: $locale,
            retentionMonths: (int) $settings->retention_default_months,
            consentTextVersion: (string) $request->input('consent_text_version'),
            phone: $request->filled('phone') ? (string) $request->input('phone') : null,
            coverLetter: $request->filled('cover_letter') ? (string) $request->input('cover_letter') : null,
            cv: $cv instanceof UploadedFile ? $cv : null
        ));

        return response()->json([
            'data' => [
                'reference' => (string) $application->reference,
                'message' => RecruitingDefaults::applicationReceivedMessage($locale),
            ],
        ], 201);
    }

    public function feedJson(Request $request): JsonResponse
    {
        return response()->json($this->feedBuilder->jsonFeed($this->settings($request), $this->requestedLocale($request)));
    }

    public function feedRss(Request $request): Response
    {
        return response(
            $this->feedBuilder->rssFeed($this->settings($request), $this->requestedLocale($request)),
            200,
            ['Content-Type' => 'application/rss+xml; charset=UTF-8']
        );
    }

    private function settings(Request $request): RecruitingSettings
    {
        $settings = $request->attributes->get(ResolveCareersTenant::SETTINGS_ATTRIBUTE);

        if (! $settings instanceof RecruitingSettings) {
            abort(404);
        }

        return $settings;
    }

    private function requestedLocale(Request $request): ?string
    {
        $locale = RecruitingLocale::tryFrom((string) $request->query('locale', ''));

        return $locale?->value;
    }

    /**
     * Languages in which the careers page has content: published languages of public openings, else intro texts.
     *
     * @return list<string>
     */
    private function availableLocales(RecruitingSettings $settings): array
    {
        $used = [];
        foreach ($this->feedBuilder->publicOpeningsQuery()->without('department')->get() as $opening) {
            /** @var JobOpening $opening */
            $used = [...$used, ...$opening->publishedLanguages()];
        }

        if ($used === []) {
            $used = array_keys(array_filter((array) ($settings->intro ?? [])));
        }

        $locales = array_values(array_filter(
            RecruitingLocale::values(),
            static fn (string $locale): bool => in_array($locale, $used, true)
        ));

        return $locales !== [] ? $locales : [RecruitingLocale::En->value];
    }
}
