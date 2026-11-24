<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A question: rating (1 to 5), enps (0 to 10, how likely the employee is to recommend the company) or free text.
 *
 * @property string $id
 * @property string $survey_id
 * @property int $position
 * @property string $prompt
 * @property string $question_type
 * @property bool $is_required
 */
final class EngagementQuestion extends BaseEnterpriseModel
{
    public const string Rating = 'rating';

    public const string Enps = 'enps';

    public const string Text = 'text';

    protected $table = 'tl_survey_questions';

    protected function casts(): array
    {
        return ['position' => 'integer', 'is_required' => 'boolean'];
    }
}
