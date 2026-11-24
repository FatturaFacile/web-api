<?php

declare(strict_types=1);

namespace Modules\Customs\Enums;

enum ShipmentRequestStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Refused = 'refused';
}
