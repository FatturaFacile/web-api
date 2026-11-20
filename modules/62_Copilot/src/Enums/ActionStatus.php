<?php

declare(strict_types=1);

namespace Modules\Copilot\Enums;

/**
 * Lifecycle of an action staged by the Copilot: it only runs after a human confirmed it.
 */
enum ActionStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Executed = 'executed';
    case Failed = 'failed';
}
