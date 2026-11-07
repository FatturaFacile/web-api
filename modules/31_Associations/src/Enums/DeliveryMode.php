<?php

declare(strict_types=1);

namespace Modules\Associations\Enums;

/**
 * Which recipients a send reaches: all of them, only those not reached yet, or a selection.
 */
enum DeliveryMode: string
{
    case All = 'all';
    case Unreached = 'unreached';
    case Selected = 'selected';
}
