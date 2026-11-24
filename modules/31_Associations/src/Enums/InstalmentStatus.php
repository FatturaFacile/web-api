<?php

declare(strict_types=1);

namespace Modules\Associations\Enums;

enum InstalmentStatus: string
{
    case Open = 'open';
    case Partial = 'partial';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
}
