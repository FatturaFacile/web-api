<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Models;

use Illuminate\Support\Carbon;

/**
 * The answer to one question of a response.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $response_id
 * @property string $question_id
 * @property int|null $numeric_value
 * @property string|null $text_value
 * @property Carbon $submitted_on
 */
final class EngagementAnswer extends AnonymousRecord
{
    protected $table = 'tl_survey_answers';

    protected function casts(): array
    {
        return ['numeric_value' => 'integer', 'submitted_on' => 'date'];
    }
}
