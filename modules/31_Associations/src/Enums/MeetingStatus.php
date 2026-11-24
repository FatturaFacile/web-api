<?php

declare(strict_types=1);

namespace Modules\Associations\Enums;

/**
 * Lifecycle of a meeting: prepared, convened with a notice, held with its quorum, closed, or cancelled.
 */
enum MeetingStatus: string
{
    case Draft = 'draft';
    case Convened = 'convened';
    case Held = 'held';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
}
