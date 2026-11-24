<?php

declare(strict_types=1);

namespace Modules\Hse\Domain\Enums;

enum IncidentStatus: string
{
    case Draft = 'draft';
    case UnderInvestigation = 'under_investigation';
    case CorrectiveActionsOpen = 'corrective_actions_open';
    case Closed = 'closed';
}
