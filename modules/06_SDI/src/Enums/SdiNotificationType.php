<?php

declare(strict_types=1);

namespace Modules\SDI\Enums;

enum SdiNotificationType: string
{
    case NS = 'NS';
    case RC = 'RC';
    case MC = 'MC';
    case NE = 'NE';
    case DT = 'DT';
    case AT = 'AT';
    case MT = 'MT';
}
