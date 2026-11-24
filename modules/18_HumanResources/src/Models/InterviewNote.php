<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Database\Factories\InterviewNoteFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * What an interviewer wrote about a candidate. It is personal data and goes with the application.
 */
#[UseFactory(InterviewNoteFactory::class)]
final class InterviewNote extends CandidateRecord
{
    /** @use HasFactory<InterviewNoteFactory> */
    use HasFactory;

    protected $table = 'hr_interview_notes';

    protected function casts(): array
    {
        return ['interviewed_at' => 'datetime', 'rating' => 'integer', 'note' => 'encrypted'];
    }
}
