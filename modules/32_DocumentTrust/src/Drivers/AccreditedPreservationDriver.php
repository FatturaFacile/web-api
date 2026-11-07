<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Drivers;

use Modules\DocumentTrust\Contracts\PreservationDriver;
use Modules\DocumentTrust\Exceptions\DocumentTrustException;

/**
 * Placeholder of the legal preservation, which needs a contract with an accredited provider and its API. Until then
 * every call is refused, so no document can look preserved when it is not.
 */
final class AccreditedPreservationDriver implements PreservationDriver
{
    public function name(): string
    {
        return 'accredited';
    }

    public function preserve(string $documentName, string $content): array
    {
        throw new DocumentTrustException('Legal preservation needs an accredited provider, and none is configured.');
    }
}
