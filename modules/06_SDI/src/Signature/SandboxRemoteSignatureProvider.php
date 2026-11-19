<?php

declare(strict_types=1);

namespace Modules\SDI\Signature;

use Modules\SDI\Contracts\SignatureProviderInterface;
use Modules\SDI\DTOs\SignedPayload;

/**
 * Mock of a remote HSM / cloud trust provider (InfoCert, Namirial, Aruba, OpenAPI remote sign). It signs with an
 * ephemeral key so the whole flow works without credentials; real adapters implement the same interface and exchange
 * the OTP or token with the provider.
 */
final class SandboxRemoteSignatureProvider implements SignatureProviderInterface
{
    private ?LocalPkcs12SignatureProvider $signer = null;

    public function signCades(string $xml, string $fileName): SignedPayload
    {
        return $this->signer()->signCades($xml, $fileName);
    }

    public function signXades(string $xml, string $fileName): SignedPayload
    {
        return $this->signer()->signXades($xml, $fileName);
    }

    private function signer(): LocalPkcs12SignatureProvider
    {
        if ($this->signer === null) {
            ['pkcs12' => $p12, 'password' => $password] = LocalPkcs12SignatureProvider::generateSelfSigned();
            $this->signer = new LocalPkcs12SignatureProvider($p12, $password);
        }

        return $this->signer;
    }
}
