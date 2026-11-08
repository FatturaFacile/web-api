<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Drivers;

use Modules\DocumentTrust\Contracts\PreservationDriver;

/**
 * Keeps a fingerprint of the document. This is not legal preservation: the status says so.
 */
final class LocalPreservationDriver implements PreservationDriver
{
    /**
     * Driver name.
     */
    public function name(): string
    {
        return 'local';
    }

    /**
     * Record a fingerprint-only status.
     */
    public function preserve(string $documentName, string $content): array
    {
        return ['status' => 'fingerprint_only', 'reference' => null];
    }
}
