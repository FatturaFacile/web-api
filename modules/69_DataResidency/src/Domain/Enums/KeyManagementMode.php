<?php

declare(strict_types=1);

namespace Modules\DataResidency\Domain\Enums;

enum KeyManagementMode: string
{
    case PlatformManaged = 'Platform-Managed';
    /** Bring Your Own Key: the customer KMS wraps the DEK, a short TTL cache is allowed. */
    case Byok = 'BYOK';
    /** Hold Your Own Key: no DEK is cached, every cryptographic boundary unwraps in the customer HSM. */
    case Hyok = 'HYOK';

    public function allowsDekCache(): bool
    {
        return $this !== self::Hyok;
    }

    public function isCustomerManaged(): bool
    {
        return $this !== self::PlatformManaged;
    }
}
