<?php

declare(strict_types=1);

namespace Modules\Assistant\Enums;

enum AnswerOutcome: string
{
    case Answered = 'answered';
    case Unmatched = 'unmatched';
    case Forbidden = 'forbidden';
    case Failed = 'failed';
}
