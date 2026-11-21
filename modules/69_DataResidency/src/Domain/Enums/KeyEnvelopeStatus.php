<?php

declare(strict_types=1);

namespace Modules\DataResidency\Domain\Enums;

enum KeyEnvelopeStatus: string
{
    case Active = 'ACTIVE';
    case Rotating = 'ROTATING';
    case Suspended = 'SUSPENDED';
    case RevokedCryptoShredded = 'REVOKED_CRYPTO_SHREDDED';

    public function isLocked(): bool
    {
        return $this === self::Suspended || $this === self::RevokedCryptoShredded;
    }
}
