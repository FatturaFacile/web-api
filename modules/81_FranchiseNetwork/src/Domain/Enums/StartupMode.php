<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Domain\Enums;

enum StartupMode: string
{
    use Concerns\EnumValues;

    case None = 'none';
    case FullExemption = 'full_exemption';
    case ReducedRate = 'reduced_rate';
    case RampUp = 'ramp_up';
}
