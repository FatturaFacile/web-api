<?php

declare(strict_types=1);

namespace Modules\DataResidency\Domain\Enums;

enum AccessOutcome: string
{
    case Success = 'SUCCESS';
    case Denied = 'DENIED';
    case KmsUnreachable = 'KMS_UNREACHABLE';
}
