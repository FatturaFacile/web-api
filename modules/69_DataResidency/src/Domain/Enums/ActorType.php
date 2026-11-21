<?php

declare(strict_types=1);

namespace Modules\DataResidency\Domain\Enums;

enum ActorType: string
{
    case System = 'SYSTEM';
    case User = 'USER';
    case Ciso = 'CISO';
    case Cron = 'CRON';
}
