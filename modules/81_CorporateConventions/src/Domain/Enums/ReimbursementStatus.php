<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Domain\Enums;

use Modules\CorporateConventions\Domain\Enums\Concerns\EnumValues;

enum ReimbursementStatus: string
{
    use EnumValues;

    case PendingSettlement = 'PENDING_SETTLEMENT';
    case SubmittedToProvider = 'SUBMITTED_TO_PROVIDER';
    case Reconciled = 'RECONCILED';
    case Rejected = 'REJECTED';
}
