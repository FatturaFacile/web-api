<?php

declare(strict_types=1);

namespace Modules\Distribution\Domain\Enums;

use Modules\Distribution\Domain\Enums\Concerns\EnumValues;

enum ProposalType: string
{
    use EnumValues;

    case Replenishment = 'replenishment';
    case Balancing = 'balancing';
}
