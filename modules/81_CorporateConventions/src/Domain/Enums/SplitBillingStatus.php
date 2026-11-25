<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Domain\Enums;

use Modules\CorporateConventions\Domain\Enums\Concerns\EnumValues;

enum SplitBillingStatus: string
{
    use EnumValues;

    case PendingConsolidation = 'PENDING_CONSOLIDATION';
    case Invoiced = 'INVOICED';
    case Paid = 'PAID';
    case Disputed = 'DISPUTED';
}
