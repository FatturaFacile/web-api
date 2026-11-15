<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * The fact that an employee answered a survey (one answer per employee). It has no link to the response.
 *
 * @property string $id
 * @property string $survey_id
 * @property string $employee_id
 * @property Carbon $responded_on
 */
final class EngagementParticipant extends BaseEnterpriseModel
{
    protected $table = 'tl_survey_participants';

    protected function casts(): array
    {
        return ['responded_on' => 'date'];
    }
}
