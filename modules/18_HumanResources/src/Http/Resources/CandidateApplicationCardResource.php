<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HumanResources\Enums\RecruitingLocale;
use Modules\HumanResources\Models\CandidateApplication;

/**
 * Kanban card and list row of a candidate application.
 *
 * @mixin CandidateApplication
 */
final class CandidateApplicationCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return self::cardFields($this->resource);
    }

    /**
     * @return array<string, mixed>
     */
    public static function cardFields(CandidateApplication $application): array
    {
        $opening = $application->jobOpening;
        $average = $application->getAttribute('scorecards_avg_overall_score');
        $count = $application->getAttribute('scorecards_count');

        if ($count === null) {
            $scores = $application->relationLoaded('scorecards')
                ? $application->scorecards->pluck('overall_score')
                : $application->scorecards()->pluck('overall_score');
            $count = $scores->count();
            $average = $count > 0 ? $scores->avg() : null;
        }

        return [
            'id' => (string) $application->id,
            'job_opening_id' => (string) $application->job_opening_id,
            'job_opening_title' => $opening?->localized(RecruitingLocale::fromAppLocale(app()->getLocale())->value)['title'],
            'first_name' => (string) $application->first_name,
            'last_name' => (string) $application->last_name,
            'full_name' => $application->fullName(),
            'email' => $application->email,
            'phone' => $application->phone,
            'stage' => $application->stage->value,
            'stage_entered_at' => $application->stage_entered_at?->toIso8601ZuluString(),
            'board_position' => (int) $application->board_position,
            'source' => (string) $application->source,
            'cv_parse_status' => $application->cv_parse_status->value,
            'skills' => array_map('strval', (array) ($application->skills ?? [])),
            'average_score' => $average !== null && (int) $count > 0 ? round((float) $average, 2) : null,
            'scorecards_count' => (int) $count,
            'privacy_consent_expires_at' => $application->privacy_consent_expires_at?->toIso8601ZuluString(),
            'is_anonymized' => $application->isAnonymized(),
            'created_at' => $application->created_at?->toIso8601ZuluString(),
        ];
    }
}
