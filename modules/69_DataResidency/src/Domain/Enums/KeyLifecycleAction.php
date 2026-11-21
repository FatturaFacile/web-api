<?php

declare(strict_types=1);

namespace Modules\DataResidency\Domain\Enums;

enum KeyLifecycleAction: string
{
    case Rotate = 'ROTATE';
    case Suspend = 'SUSPEND';
    case CryptoShred = 'CRYPTO_SHRED';
}
