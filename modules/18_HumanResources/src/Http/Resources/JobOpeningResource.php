<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HumanResources\Models\JobOpening;

/**
 * @mixin JobOpening
 */
final class JobOpeningResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var JobOpening $opening */
        $opening = $this->resource;

        $applicationsCount = $opening->getAttribute('applications_count');
        if ($applicationsCount === null) {
            $applicationsCount = $opening->applications()->count();
        }

        return [
            'id' => (string) $opening->id,
            'reference' => (string) $opening->reference,
            'position_id' => (string) $opening->position_id,
            'position_title' => $opening->position?->title,
            'department_id' => $opening->department_id,
            'department_name' => $opening->department?->name,
            'location_site' => (string) $opening->location_site,
            'employment_type' => (string) $opening->employment_type,
            'openings_count' => (int) $opening->openings_count,
            'hires_count' => (int) $opening->hires_count,
            'salary_range_public' => $opening->salaryRangePublic(),
            'status' => $opening->status->value,
            'published_languages' => $opening->publishedLanguages(),
            'translations' => (object) array_map(static fn (array $translation): array => [
                'title' => $translation['title'] ?? null,
                'description' => $translation['description'] ?? null,
                'requirements' => $translation['requirements'] ?? null,
                'benefits' => $translation['benefits'] ?? null,
            ], array_filter((array) ($opening->translations ?? []), 'is_array')),
            'published_at' => $opening->published_at?->toIso8601ZuluString(),
            'closes_at' => $opening->closes_at?->toDateString(),
            'closed_at' => $opening->closed_at?->toIso8601ZuluString(),
            'applications_count' => (int) $applicationsCount,
            'created_at' => $opening->created_at?->toIso8601ZuluString(),
            'updated_at' => $opening->updated_at?->toIso8601ZuluString(),
        ];
    }
}
