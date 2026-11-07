<?php

declare(strict_types=1);

namespace Modules\Associations\Enums;

/**
 * Lifecycle of a ballot.
 */
enum BallotStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';
}
