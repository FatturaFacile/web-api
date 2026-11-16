<?php

declare(strict_types=1);

namespace Modules\Logistics\Enums;

enum TransferStatus: string
{
    case Draft = 'draft';
    case InTransit = 'in_transit';
    case Received = 'received';
    case DiscrepancyPending = 'discrepancy_pending';
    case Reconciled = 'reconciled';
    case Cancelled = 'cancelled';
}
