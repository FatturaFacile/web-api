<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Contracts;

use Modules\DocumentTrust\Exceptions\DocumentTrustException;
use Modules\DocumentTrust\Models\DocumentSigningSetting;

interface SignatureDriver
{
    public function name(): string;

    /**
     * @return array{format: string, signature: string} the signature base64-encoded
     *
     * @throws DocumentTrustException when the document cannot be signed
     */
    public function sign(string $content, ?DocumentSigningSetting $settings): array;
}
