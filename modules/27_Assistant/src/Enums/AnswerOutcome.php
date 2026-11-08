<?php

declare(strict_types=1);

namespace Modules\Assistant\Enums;

/**
 * Allowed values of answer outcome in the Assistant module.
 */
enum AnswerOutcome: string
{
    case Answered = 'answered';
    case Unmatched = 'unmatched';
    case Forbidden = 'forbidden';
    case Failed = 'failed';
}
