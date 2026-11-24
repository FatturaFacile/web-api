<?php

declare(strict_types=1);

namespace Modules\Procurement\Enums;

enum MatchTrigger: string
{
    case Auto = 'auto';
    case Manual = 'manual';
    case Batch = 'batch';
    case Rematch = 'rematch';
}
