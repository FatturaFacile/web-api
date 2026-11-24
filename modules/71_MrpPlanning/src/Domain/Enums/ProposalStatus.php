<?php

declare(strict_types=1);

namespace Modules\MrpPlanning\Domain\Enums;

/** Lifecycle of a proposal: proposed by a run, firmed by the planner, released to procurement or production, or dismissed. */
enum ProposalStatus: string
{
    case Proposed = 'Proposed';
    case Firm = 'Firm';
    case Released = 'Released';
    case Dismissed = 'Dismissed';
}
