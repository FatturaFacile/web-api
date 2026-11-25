<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\DTOs;

/**
 * A PDF with an empty signature field: the bytes, the place reserved for the CMS and the SHA-256 digest of everything
 * outside it (the ByteRange). A signer, local or remote, signs that digest and the CMS goes into the reserved place.
 */
final readonly class PreparedPdf
{
    public function __construct(
        public string $bytes,
        public int $contentsOffset,
        public int $contentsLength,
        public string $digest,
    ) {}

    public function digestHex(): string
    {
        return bin2hex($this->digest);
    }

    public function digestBase64(): string
    {
        return base64_encode($this->digest);
    }
}
