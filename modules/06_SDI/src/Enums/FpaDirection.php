<?php

declare(strict_types=1);

namespace Modules\SDI\Enums;

enum FpaDirection: string
{
    case Outbound = 'outbound';
    case Inbound = 'inbound';
}
