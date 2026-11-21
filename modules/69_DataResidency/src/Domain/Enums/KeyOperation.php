<?php

declare(strict_types=1);

namespace Modules\DataResidency\Domain\Enums;

enum KeyOperation: string
{
    case UnwrapDek = 'UNWRAP_DEK';
    case WrapDek = 'WRAP_DEK';
    case RotateKek = 'ROTATE_KEK';
    case CacheHit = 'CACHE_HIT';
    case KillSwitchSuspend = 'KILL_SWITCH_SUSPEND';
    case KillSwitchShred = 'KILL_SWITCH_SHRED';
    case KmsHealthCheck = 'KMS_HEALTH_CHECK';
    case AccessDeniedRevoked = 'ACCESS_DENIED_REVOKED';
}
