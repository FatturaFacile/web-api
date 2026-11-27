<?php

declare(strict_types=1);

namespace Modules\Distribution\Domain\Enums;

use Modules\Distribution\Domain\Enums\Concerns\EnumValues;

enum ProposalStatus: string
{
    use EnumValues;

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Converted = 'converted';
    case Shortfall = 'shortfall';
}
