<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\HumanResources\Enums\JobOpeningStatus;
use Modules\HumanResources\Enums\RecruitingLocale;

/**
 * Recruiting job opening (Mod 070), linked to a budgeted HR position.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $reference
 * @property string $position_id
 * @property string|null $department_id
 * @property string $location_site
 * @property string $employment_type
 * @property int $openings_count
 * @property int $hires_count
 * @property string|null $salary_min
 * @property string|null $salary_max
 * @property string|null $salary_currency
 * @property JobOpeningStatus $status
 * @property list<string>|null $published_languages
 * @property array<string, array<string, string|null>>|null $translations
 * @property Carbon|null $published_at
 * @property Carbon|null $closes_at
 * @property Carbon|null $closed_at
 */
final class JobOpening extends BaseEnterpriseModel
{
    protected $table = 'rec_job_openings';

    protected function casts(): array
    {
        return [
            'openings_count' => 'integer',
            'hires_count' => 'integer',
            'salary_min' => 'decimal:2',
            'salary_max' => 'decimal:2',
            'status' => JobOpeningStatus::class,
            'published_languages' => 'array',
            'translations' => 'array',
            'published_at' => 'datetime',
            'closes_at' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Position, $this>
     */
    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'position_id');
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    /**
     * @return HasMany<CandidateApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(CandidateApplication::class, 'job_opening_id');
    }

    /**
     * Unfilled seats still reserved by this opening on its position.
     */
    public function remainingOpenings(): int
    {
        return max(0, (int) $this->openings_count - (int) $this->hires_count);
    }

    /**
     * @return list<string>
     */
    public function publishedLanguages(): array
    {
        return array_values(array_filter(
            (array) ($this->published_languages ?? []),
            static fn (string $locale): bool => RecruitingLocale::tryFrom($locale) !== null
        ));
    }

    /**
     * Resolve the translation to show: requested locale, then `en`, then the first published language.
     *
     * @return array{locale: string, title: string, description: string, requirements: string|null, benefits: string|null}
     */
    public function localized(?string $requestedLocale): array
    {
        $translations = (array) ($this->translations ?? []);
        $published = $this->publishedLanguages();
        $eligible = $published !== [] ? $published : array_map('strval', array_keys($translations));
        $candidates = array_values(array_unique(array_filter(
            [$requestedLocale, RecruitingLocale::En->value, ...$eligible],
            static fn (?string $locale): bool => $locale !== null && $locale !== ''
        )));

        foreach ($candidates as $locale) {
            $translation = $translations[$locale] ?? null;
            if (! in_array($locale, $eligible, true) || ! is_array($translation) || trim((string) ($translation['title'] ?? '')) === '') {
                continue;
            }

            return [
                'locale' => $locale,
                'title' => (string) $translation['title'],
                'description' => (string) ($translation['description'] ?? ''),
                'requirements' => isset($translation['requirements']) ? (string) $translation['requirements'] : null,
                'benefits' => isset($translation['benefits']) ? (string) $translation['benefits'] : null,
            ];
        }

        return [
            'locale' => $requestedLocale ?? RecruitingLocale::En->value,
            'title' => (string) $this->reference,
            'description' => '',
            'requirements' => null,
            'benefits' => null,
        ];
    }

    /**
     * @return array{min: float|null, max: float|null, currency: string, period: string}|null
     */
    public function salaryRangePublic(): ?array
    {
        if ($this->salary_min === null && $this->salary_max === null) {
            return null;
        }

        return [
            'min' => $this->salary_min !== null ? round((float) $this->salary_min, 2) : null,
            'max' => $this->salary_max !== null ? round((float) $this->salary_max, 2) : null,
            'currency' => (string) ($this->salary_currency ?: 'EUR'),
            'period' => 'year',
        ];
    }
}
