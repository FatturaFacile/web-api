<?php

declare(strict_types=1);

namespace Modules\DataResidency\Domain\Exceptions;

/** The customer-managed key is suspended or crypto-shredded: the encrypted data is unreadable (HTTP 423 Locked). */
final class TenantCryptoShreddedOrSuspendedException extends DataResidencyException
{
    public function httpStatus(): int
    {
        return 423;
    }

    public function errorCode(): string
    {
        return 'TENANT_CRYPTO_SHREDDED_OR_SUSPENDED';
    }

    public function title(): string
    {
        return 'Locked: Key Access Suspended';
    }
}
