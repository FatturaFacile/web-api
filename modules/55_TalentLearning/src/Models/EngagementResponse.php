<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One submitted questionnaire. respondent_employee_id is always null for an anonymous survey; the segment (the
 * department of the respondent) is only reported for groups above the anonymity threshold.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $survey_id
 * @property string|null $respondent_employee_id
 * @property string|null $segment
 * @property Carbon $submitted_on
 */
final class EngagementResponse extends AnonymousRecord
{
    protected $table = 'tl_survey_responses';

    protected function casts(): array
    {
        return ['submitted_on' => 'date'];
    }

    /**
     * @return HasMany<EngagementAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(EngagementAnswer::class, 'response_id');
    }
}
