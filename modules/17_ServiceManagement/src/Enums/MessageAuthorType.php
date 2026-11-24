<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Enums;

enum MessageAuthorType: string
{
    case Staff = 'staff';
    case Customer = 'customer';
    case System = 'system';
}
