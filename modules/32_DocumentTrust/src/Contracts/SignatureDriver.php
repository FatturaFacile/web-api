<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Contracts;

use Modules\DocumentTrust\Exceptions\DocumentTrustException;
use Modules\DocumentTrust\Models\DocumentSignature;
use Modules\DocumentTrust\Models\DocumentSigningSetting;

/**
 * Produces a signature for a document.
 */
interface SignatureDriver
{
    /**
     * Driver name.
     */
    public function name(): string;

    /**
     * @return array{format: string, signature: string} the signature base64-encoded
     *
     * @throws DocumentTrustException when the document cannot be signed
     */
    public function sign(string $content, ?DocumentSigningSetting $settings): array;

    /**
     * Checks a stored signature against the content the document has now.
     *
     * Only the cryptographic binding between signature and content is checked: the certificate chain is not validated
     * against a trust list, so a positive answer proves the document was not changed since it was signed, not who the
     * signer is.
     *
     * @return array{valid: bool, reason: ?string}
     */
    public function verify(string $content, DocumentSignature $signature): array;
}
