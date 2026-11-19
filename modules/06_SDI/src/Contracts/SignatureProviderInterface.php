<?php

declare(strict_types=1);

namespace Modules\SDI\Contracts;

use Modules\SDI\DTOs\SignedPayload;

interface SignatureProviderInterface
{
    /**
     * CAdES-BES enveloped signature: the file becomes <name>.xml.p7m.
     */
    public function signCades(string $xml, string $fileName): SignedPayload;

    /**
     * XAdES-BES enveloped signature: the file stays <name>.xml.
     */
    public function signXades(string $xml, string $fileName): SignedPayload;
}
