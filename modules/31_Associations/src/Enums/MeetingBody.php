<?php

declare(strict_types=1);

namespace Modules\Associations\Enums;

/**
 * The body that meets: the assembly of the members, the board, or another body such as a committee.
 */
enum MeetingBody: string
{
    case Assembly = 'assembly';
    case Board = 'board';
    case Other = 'other';
}
