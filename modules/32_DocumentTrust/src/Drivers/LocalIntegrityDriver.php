<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Drivers;

use Modules\DocumentTrust\Contracts\SignatureDriver;
use Modules\DocumentTrust\Models\DocumentSigningSetting;

/**
 * An integrity fingerprint, not a signature: it has no legal value and identifies no signer.
 */
final class LocalIntegrityDriver implements SignatureDriver
{
    /**
     * Driver name.
     */
    public function name(): string
    {
        return 'local';
    }

    /**
     * Return the SHA-256 fingerprint of the content.
     */
    public function sign(string $content, ?DocumentSigningSetting $settings): array
    {
        return ['format' => 'sha256-integrity', 'signature' => base64_encode(hash('sha256', $content, true))];
    }
}
