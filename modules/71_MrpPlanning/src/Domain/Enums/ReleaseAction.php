<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\Enums;

/** What the planner does with selected proposals. */
enum ReleaseAction: string
{
    case Firm = 'firm';
    case Release = 'release';
    case Dismiss = 'dismiss';
}
