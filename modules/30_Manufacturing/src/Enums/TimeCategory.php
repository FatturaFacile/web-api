<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Enums;

/**
 * What a time interval of a phase measures: setup (Italian: setup), run (lavorazione) or suspension (fermo).
 */
enum TimeCategory: string
{
    case Setup = 'setup';
    case Run = 'run';
    case Suspension = 'suspension';
}
