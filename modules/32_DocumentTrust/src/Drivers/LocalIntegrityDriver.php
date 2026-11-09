<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Drivers;

use Modules\DocumentTrust\Contracts\SignatureDriver;
use Modules\DocumentTrust\Models\DocumentSignature;
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

    /**
     * The fingerprint must equal the SHA-256 of the content as it is now.
     *
     * @return array{valid: bool, reason: ?string}
     */
    public function verify(string $content, DocumentSignature $signature): array
    {
        $matches = hash_equals((string) base64_decode((string) $signature->signature, true), hash('sha256', $content, true));

        return ['valid' => $matches, 'reason' => $matches ? null : 'content_changed'];
    }
}
