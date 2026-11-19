<?php

declare(strict_types=1);

namespace Modules\SDI\Services;

use Modules\SDI\Contracts\SignatureProviderInterface;
use Modules\SDI\DTOs\SignedPayload;
use Modules\SDI\Enums\SignatureMode;
use Modules\SDI\Models\SdiConfiguration;
use Modules\SDI\Signature\LocalPkcs12SignatureProvider;
use Modules\SDI\Signature\SandboxRemoteSignatureProvider;

/**
 * Picks the signature provider configured for the tenant and signs the invoice file.
 */
final class DigitalSignatureService
{
    /**
     * @param  'cades'|'xades'  $format
     *
     * @throws \DomainException when the tenant has no signature configured
     */
    public function sign(SdiConfiguration $configuration, string $xml, string $fileName, string $format = 'cades'): SignedPayload
    {
        $provider = $this->providerFor($configuration);

        return $format === 'xades' ? $provider->signXades($xml, $fileName) : $provider->signCades($xml, $fileName);
    }

    public function providerFor(SdiConfiguration $configuration): SignatureProviderInterface
    {
        return match ($configuration->signature_mode) {
            SignatureMode::LocalPkcs12 => $this->local($configuration),
            SignatureMode::RemoteHsmProvider => new SandboxRemoteSignatureProvider,
            default => throw new \DomainException('No digital signature is configured.'),
        };
    }

    private function local(SdiConfiguration $configuration): LocalPkcs12SignatureProvider
    {
        $stored = (string) $configuration->encrypted_certificate;
        $decoded = json_decode($stored, true);

        if (! is_array($decoded) || ! isset($decoded['pkcs12'], $decoded['password'])) {
            throw new \DomainException('The PKCS#12 certificate is not stored correctly.');
        }

        return new LocalPkcs12SignatureProvider((string) base64_decode((string) $decoded['pkcs12'], true), (string) $decoded['password']);
    }
}
