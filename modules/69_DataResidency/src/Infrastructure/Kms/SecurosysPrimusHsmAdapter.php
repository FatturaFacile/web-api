<?php

declare(strict_types=1);

namespace Modules\DataResidency\Infrastructure\Kms;

use Modules\DataResidency\Domain\Enums\KmsProviderType;
use Modules\DataResidency\Domain\Exceptions\KmsUnavailableException;
use Modules\DataResidency\Infrastructure\Models\CustomerManagedKeyEnvelope;

/**
 * Securosys Primus HSM (Swiss, FIPS 140-3 Level 3) through its REST gateway (Primus REST/TSB). external_key_uri:
 * `<gateway>/v1/keys/<label>`; credentials: `bearer_token` (the gateway issues one per application). The HSM wraps with
 * the AES KEK of the label; the key never leaves the appliance.
 */
final class SecurosysPrimusHsmAdapter extends RestKmsAdapter
{
    public function type(): KmsProviderType
    {
        return KmsProviderType::SecurosysPrimusHsm;
    }

    public function wrapKey(CustomerManagedKeyEnvelope $envelope, string $dek): string
    {
        $data = $this->postJson($envelope->external_key_uri.'/wrap', ['payload' => base64_encode($dek), 'keyVersion' => $envelope->current_key_version], $this->headers($envelope));

        return $this->stringAt($data, 'wrapped');
    }

    public function unwrapKey(CustomerManagedKeyEnvelope $envelope, string $wrappedDek): string
    {
        $data = $this->postJson($envelope->external_key_uri.'/unwrap', ['wrapped' => $wrappedDek, 'keyVersion' => $envelope->current_key_version], $this->headers($envelope));
        $plain = base64_decode($this->stringAt($data, 'payload'), true);

        if ($plain === false || strlen($plain) !== 32) {
            throw new KmsUnavailableException('The HSM returned a malformed data key.');
        }

        return $plain;
    }

    public function createKeyVersion(CustomerManagedKeyEnvelope $envelope): string
    {
        return $this->stringAt($this->postJson($envelope->external_key_uri.'/versions', [], $this->headers($envelope)), 'keyVersion');
    }

    /**
     * @return array<string, string>
     */
    private function headers(CustomerManagedKeyEnvelope $envelope): array
    {
        return ['Authorization' => 'Bearer '.(string) ($this->credentials($envelope)['bearer_token'] ?? '')];
    }
}
