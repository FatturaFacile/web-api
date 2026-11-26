<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Enums;

enum SyncTrigger: string
{
    case Scheduled = 'scheduled';
    case Manual = 'manual';
}
