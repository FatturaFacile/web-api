<?php

declare(strict_types=1);

namespace Modules\Observability\Domain\Enums;

/**
 * What happened with the EXPLAIN of a captured query.
 */
enum PlanStatus: string
{
    case Available = 'available';
    case Unsupported = 'unsupported';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
