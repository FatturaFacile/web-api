<?php

declare(strict_types=1);

namespace Modules\HumanResources\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\HumanResources\Contracts\RecruitmentServiceInterface;
use Modules\HumanResources\Enums\JobOpeningStatus;
use Modules\HumanResources\Enums\RecruitingLocale;
use Modules\HumanResources\Exceptions\RecruitingRuleViolationException;
use Modules\HumanResources\Models\JobOpening;
use Modules\HumanResources\Models\Position;

/**
 * Creates draft job openings and updates existing ones, enforcing translations and position vacancy.
 */
final readonly class SaveJobOpeningAction
{
    public function __construct(
        private RecruitmentServiceInterface $recruitment
    ) {}

    /**
     * @param  array{position_id: string, location_site: string, employment_type: string, openings_count?: int|null, salary_range_public?: array{min?: float|int|string|null, max?: float|int|string|null, currency?: string|null}|null, published_languages: list<string>, translations: array<string, array<string, string|null>>, closes_at?: string|null}  $data
     */
    public function create(array $data, ?string $actorUserId = null): JobOpening
    {
        return DB::transaction(function () use ($data, $actorUserId): JobOpening {
            /** @var Position $position */
            $position = Position::query()->lockForUpdate()->findOrFail($data['position_id']);
            $openingsCount = (int) ($data['openings_count'] ?? 1);

            if ($this->recruitment->availableVacancy($position) < $openingsCount) {
                throw RecruitingRuleViolationException::noVacancy();
            }

            $opening = new JobOpening([
                'reference' => $this->nextReference(),
                'position_id' => $position->id,
                'department_id' => $position->department_id,
                'location_site' => $data['location_site'],
                'employment_type' => $data['employment_type'],
                'openings_count' => $openingsCount,
                'hires_count' => 0,
                'status' => JobOpeningStatus::Draft,
                'published_languages' => array_values(array_unique($data['published_languages'])),
                'translations' => $this->cleanTranslations($data['translations']),
                'closes_at' => $data['closes_at'] ?? null,
                'created_by' => $actorUserId !== null && Str::isUuid($actorUserId) ? $actorUserId : null,
            ]);
            $this->applySalary($opening, $data['salary_range_public'] ?? null);
            $this->assertTranslationsComplete($opening);
            $opening->save();

            return $opening;
        });
    }

    /**
     * @param  array<string, mixed>  $data  same keys as `create()`, all optional
     */
    public function update(JobOpening $opening, array $data): JobOpening
    {
        return DB::transaction(function () use ($opening, $data): JobOpening {
            /** @var JobOpening $opening */
            $opening = JobOpening::query()->lockForUpdate()->findOrFail($opening->id);

            if (array_key_exists('position_id', $data) && $data['position_id'] !== null && $data['position_id'] !== $opening->position_id) {
                /** @var Position $position */
                $position = Position::query()->findOrFail($data['position_id']);
                $opening->position_id = $position->id;
                $opening->department_id = $position->department_id;
            }

            foreach (['location_site', 'employment_type', 'closes_at'] as $field) {
                if (array_key_exists($field, $data)) {
                    $opening->{$field} = $data[$field];
                }
            }

            if (array_key_exists('openings_count', $data) && $data['openings_count'] !== null) {
                $openingsCount = (int) $data['openings_count'];
                if ($openingsCount < (int) $opening->hires_count) {
                    throw ValidationException::withMessages([
                        'openings_count' => ['The number of openings cannot be lower than the hires already made.'],
                    ]);
                }
                $opening->openings_count = $openingsCount;
            }

            if (array_key_exists('published_languages', $data) && is_array($data['published_languages'])) {
                $opening->published_languages = array_values(array_unique($data['published_languages']));
            }

            if (array_key_exists('translations', $data) && is_array($data['translations'])) {
                $opening->translations = array_replace(
                    (array) ($opening->translations ?? []),
                    $this->cleanTranslations($data['translations'])
                );
            }

            if (array_key_exists('salary_range_public', $data)) {
                $this->applySalary($opening, is_array($data['salary_range_public']) ? $data['salary_range_public'] : null);
            }

            $this->assertTranslationsComplete($opening);

            if ($opening->status->isOpen() && ($opening->isDirty('position_id') || $opening->isDirty('openings_count'))) {
                /** @var Position $position */
                $position = Position::query()->lockForUpdate()->findOrFail($opening->position_id);
                if ($this->recruitment->availableVacancy($position, $opening) < $opening->remainingOpenings()) {
                    throw RecruitingRuleViolationException::noVacancy();
                }
            }

            $opening->save();

            return $opening;
        });
    }

    /**
     * Every published language needs at least a title and a description.
     */
    public function assertTranslationsComplete(JobOpening $opening): void
    {
        $translations = (array) ($opening->translations ?? []);
        $errors = [];

        foreach ($opening->publishedLanguages() as $locale) {
            foreach (['title', 'description'] as $field) {
                if (trim((string) ($translations[$locale][$field] ?? '')) === '') {
                    $errors["translations.{$locale}.{$field}"] = ["The {$field} is required for the published language {$locale}."];
                }
            }
        }

        if ($opening->publishedLanguages() === []) {
            $errors['published_languages'] = ['At least one publication language is required.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>|null  $salary
     */
    private function applySalary(JobOpening $opening, ?array $salary): void
    {
        if ($salary === null || (($salary['min'] ?? null) === null && ($salary['max'] ?? null) === null)) {
            $opening->salary_min = null;
            $opening->salary_max = null;
            $opening->salary_currency = null;

            return;
        }

        $opening->salary_min = isset($salary['min']) ? (string) round((float) $salary['min'], 2) : null;
        $opening->salary_max = isset($salary['max']) ? (string) round((float) $salary['max'], 2) : null;
        $opening->salary_currency = strtoupper((string) ($salary['currency'] ?? 'EUR'));
    }

    /**
     * @param  array<mixed>  $translations
     * @return array<string, array{title: string|null, description: string|null, requirements: string|null, benefits: string|null}>
     */
    private function cleanTranslations(array $translations): array
    {
        $clean = [];
        foreach ($translations as $locale => $translation) {
            if (! is_array($translation) || RecruitingLocale::tryFrom((string) $locale) === null) {
                continue;
            }

            $clean[(string) $locale] = [
                'title' => isset($translation['title']) ? trim((string) $translation['title']) : null,
                'description' => isset($translation['description']) ? (string) $translation['description'] : null,
                'requirements' => isset($translation['requirements']) ? (string) $translation['requirements'] : null,
                'benefits' => isset($translation['benefits']) ? (string) $translation['benefits'] : null,
            ];
        }

        return $clean;
    }

    private function nextReference(): string
    {
        do {
            $reference = 'JOB-'.now()->format('Y').'-'.strtoupper(Str::random(6));
        } while (JobOpening::withTrashed()->where('reference', $reference)->exists());

        return $reference;
    }
}
