<?php

declare(strict_types=1);

namespace Modules\Customs\Enums;

enum DossierStatus: string
{
    case Open = 'open';
    case Quoted = 'quoted';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case Invoiced = 'invoiced';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
}
