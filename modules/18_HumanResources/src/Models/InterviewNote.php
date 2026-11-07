<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

/**
 * What an interviewer wrote about a candidate. It is personal data and goes with the application.
 */
final class InterviewNote extends CandidateRecord
{
    protected $table = 'hr_interview_notes';

    protected function casts(): array
    {
        return ['interviewed_at' => 'datetime', 'rating' => 'integer', 'note' => 'encrypted'];
    }
}
