<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services;

use DOMDocument;
use DOMElement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\HumanResources\Contracts\RecruitmentServiceInterface;
use Modules\HumanResources\Enums\JobOpeningStatus;
use Modules\HumanResources\Enums\RecruitingLocale;
use Modules\HumanResources\Models\Department;
use Modules\HumanResources\Models\JobOpening;
use Modules\HumanResources\Models\RecruitingSettings;
use Modules\HumanResources\Support\RecruitingDefaults;

/**
 * Public representation of job openings: `PublicJob` payloads and the JSON / RSS 2.0 feeds for aggregators.
 * Only openings in status `published` (never `internal_only`) whose closing date is not past are exposed.
 */
final readonly class JobFeedBuilder
{
    public const string JOBS_NAMESPACE = 'urn:fatturafacile:jobs';

    public function __construct(
        private RecruitmentServiceInterface $recruitment
    ) {}

    /**
     * Published openings of the active tenant.
     *
     * @return Builder<JobOpening>
     */
    public function publicOpeningsQuery(): Builder
    {
        return JobOpening::query()
            ->with('department')
            ->where('status', JobOpeningStatus::Published->value)
            ->where(function (Builder $query): void {
                $query->whereNull('closes_at')->orWhereDate('closes_at', '>=', now()->toDateString());
            });
    }

    public function jobUrl(string $careersSlug, string $jobOpeningId): string
    {
        $base = rtrim((string) config('recruiting.public_careers_base_url'), '/');

        return $base.'/careers/'.rawurlencode($careersSlug).'/jobs/'.rawurlencode($jobOpeningId);
    }

    /**
     * @return array{id: string, reference: string, title: string, description: string, requirements: string|null, benefits: string|null, department_name: string|null, location_site: string, employment_type: string, salary_range_public: array{min: float|null, max: float|null, currency: string, period: string}|null, locale: string, available_locales: list<string>, published_at: string|null, closes_at: string|null, apply_url: string}
     */
    public function publicJob(JobOpening $opening, ?string $locale, string $careersSlug): array
    {
        $translation = $opening->localized($locale);

        return [
            'id' => (string) $opening->id,
            'reference' => (string) $opening->reference,
            'title' => $translation['title'],
            'description' => $translation['description'],
            'requirements' => $translation['requirements'],
            'benefits' => $translation['benefits'],
            'department_name' => $this->departmentName($opening->department, $translation['locale']),
            'location_site' => (string) $opening->location_site,
            'employment_type' => (string) $opening->employment_type,
            'salary_range_public' => $opening->salaryRangePublic(),
            'locale' => $translation['locale'],
            'available_locales' => $opening->publishedLanguages(),
            'published_at' => $opening->published_at?->toIso8601ZuluString(),
            'closes_at' => $opening->closes_at?->toDateString(),
            'apply_url' => $this->jobUrl($careersSlug, (string) $opening->id),
        ];
    }

    /**
     * @return array{version: string, company: string, generated_at: string, jobs: list<array<string, mixed>>}
     */
    public function jsonFeed(RecruitingSettings $settings, ?string $locale): array
    {
        $slug = (string) $settings->careers_slug;
        $jobs = [];

        foreach ($this->feedEntries($locale) as [$opening, $entryLocale]) {
            $translation = $opening->localized($entryLocale);
            $url = $this->jobUrl($slug, (string) $opening->id);

            $jobs[] = [
                'id' => (string) $opening->id,
                'url' => $url,
                'apply_url' => $url,
                'title' => $translation['title'],
                'description_html' => $this->descriptionHtml($translation),
                'locale' => $translation['locale'],
                'department' => $this->departmentName($opening->department, $translation['locale']),
                'location' => (string) $opening->location_site,
                'employment_type' => (string) $opening->employment_type,
                'salary_range_public' => $opening->salaryRangePublic(),
                'published_at' => $opening->published_at?->toIso8601ZuluString(),
                'valid_through' => $opening->closes_at?->toDateString(),
            ];
        }

        return [
            'version' => '1.0',
            'company' => $this->recruitment->companyName($settings),
            'generated_at' => now()->toIso8601ZuluString(),
            'jobs' => $jobs,
        ];
    }

    public function rssFeed(RecruitingSettings $settings, ?string $locale): string
    {
        $slug = (string) $settings->careers_slug;
        $company = $this->recruitment->companyName($settings);
        $channelLocale = RecruitingLocale::tryFrom((string) $locale);
        $entries = $this->feedEntries($locale);

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $rss = $document->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $rss->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:jobs', self::JOBS_NAMESPACE);
        $document->appendChild($rss);

        $channel = $document->createElement('channel');
        $rss->appendChild($channel);

        $lastBuild = collect($entries)
            ->map(static fn (array $entry): ?string => $entry[0]->updated_at?->toRfc2822String())
            ->filter()
            ->first();

        $this->appendText($document, $channel, 'title', $company);
        $this->appendText($document, $channel, 'link', rtrim((string) config('recruiting.public_careers_base_url'), '/').'/careers/'.rawurlencode($slug));
        $this->appendText($document, $channel, 'description', $settings->localizedText('intro', $channelLocale?->value) ?: $company);
        $this->appendText($document, $channel, 'language', $channelLocale?->value ?? RecruitingLocale::En->value);
        $this->appendText($document, $channel, 'lastBuildDate', $lastBuild ?? now()->toRfc2822String());

        foreach ($entries as [$opening, $entryLocale]) {
            $translation = $opening->localized($entryLocale);
            $item = $document->createElement('item');
            $channel->appendChild($item);

            $this->appendText($document, $item, 'title', $translation['title']);
            $this->appendText($document, $item, 'link', $this->jobUrl($slug, (string) $opening->id));

            $guid = $this->appendText($document, $item, 'guid', $opening->id.'-'.$translation['locale']);
            $guid->setAttribute('isPermaLink', 'false');

            $this->appendText($document, $item, 'pubDate', ($opening->published_at ?? $opening->created_at ?? now())->toRfc2822String());

            $description = $document->createElement('description');
            $description->appendChild($document->createCDATASection(str_replace(']]>', ']]&gt;', $this->descriptionHtml($translation))));
            $item->appendChild($description);

            $department = $this->departmentName($opening->department, $translation['locale']);
            if ($department !== null) {
                $this->appendText($document, $item, 'category', $department);
            }

            $item->appendChild($document->createElementNS(self::JOBS_NAMESPACE, 'jobs:location'))
                ->appendChild($document->createTextNode((string) $opening->location_site));
            $item->appendChild($document->createElementNS(self::JOBS_NAMESPACE, 'jobs:employmentType'))
                ->appendChild($document->createTextNode((string) $opening->employment_type));

            $salary = $opening->salaryRangePublic();
            if ($salary !== null) {
                $salaryElement = $document->createElementNS(self::JOBS_NAMESPACE, 'jobs:salary');
                $salaryElement->setAttribute('currency', $salary['currency']);
                $salaryElement->setAttribute('period', $salary['period']);
                if ($salary['min'] !== null) {
                    $salaryElement->setAttribute('min', number_format($salary['min'], 2, '.', ''));
                }
                if ($salary['max'] !== null) {
                    $salaryElement->setAttribute('max', number_format($salary['max'], 2, '.', ''));
                }
                $item->appendChild($salaryElement);
            }
        }

        return (string) $document->saveXML();
    }

    /**
     * One entry per opening in the requested locale, or one per published language when no locale is requested.
     *
     * @return list<array{0: JobOpening, 1: string|null}>
     */
    private function feedEntries(?string $locale): array
    {
        /** @var Collection<int, JobOpening> $openings */
        $openings = $this->publicOpeningsQuery()->orderByDesc('published_at')->orderBy('id')->get();
        $entries = [];

        foreach ($openings as $opening) {
            if ($locale !== null && $locale !== '') {
                $entries[] = [$opening, $locale];

                continue;
            }

            $languages = $opening->publishedLanguages();
            foreach ($languages !== [] ? $languages : [null] as $language) {
                $entries[] = [$opening, $language];
            }
        }

        return $entries;
    }

    /**
     * @param  array{locale: string, title: string, description: string, requirements: string|null, benefits: string|null}  $translation
     */
    private function descriptionHtml(array $translation): string
    {
        $locale = RecruitingLocale::tryFrom($translation['locale']) ?? RecruitingLocale::En;
        $html = '<p>'.nl2br(e($translation['description']), false).'</p>';

        foreach (['requirements', 'benefits'] as $section) {
            $text = trim((string) $translation[$section]);
            if ($text !== '') {
                $html .= '<h3>'.e(RecruitingDefaults::jobSectionLabel($section, $locale)).'</h3><p>'.nl2br(e($text), false).'</p>';
            }
        }

        return $html;
    }

    private function departmentName(?Department $department, string $locale): ?string
    {
        if ($department === null) {
            return null;
        }

        $language = (RecruitingLocale::tryFrom($locale) ?? RecruitingLocale::En)->language();
        $translations = (array) ($department->translations ?? []);
        $translated = $translations[$locale] ?? $translations[$language] ?? null;

        return is_string($translated) && $translated !== '' ? $translated : (string) $department->name;
    }

    private function appendText(DOMDocument $document, DOMElement $parent, string $name, string $value): DOMElement
    {
        $element = $document->createElement($name);
        $element->appendChild($document->createTextNode($value));
        $parent->appendChild($element);

        return $element;
    }
}
