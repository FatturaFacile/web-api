<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\MesFeedback;

/**
 * Outcome of one MES event in the multi-status answer of the feedback endpoint. `applied`, `duplicate` and `stale` are
 * final for the MES Core (nothing to send again); `rejected` is dead-lettered on its side.
 */
enum MesEventStatus: string
{
    case Applied = 'applied';
    case Duplicate = 'duplicate';
    case Stale = 'stale';
    case Rejected = 'rejected';
}
