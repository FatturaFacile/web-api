<?php

declare(strict_types=1);

namespace Modules\DataResidency\Domain\Enums;

enum TransferDecision: string
{
    case Allowed = 'ALLOWED';
    case RequiresSccTia = 'REQUIRES_SCC_TIA';
    case Blocked = 'BLOCKED';
}
