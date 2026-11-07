<?php

declare(strict_types=1);

namespace Modules\Associations\Enums;

/**
 * How a ballot is decided: more favourable than contrary votes, more than half of the votes cast, or a percentage of the eligible voters set by the statute.
 */
enum MajorityRule: string
{
    case Simple = 'simple';
    case Absolute = 'absolute';
    case Qualified = 'qualified';
}
