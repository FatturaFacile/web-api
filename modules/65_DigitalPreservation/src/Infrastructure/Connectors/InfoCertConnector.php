<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Infrastructure\Connectors;

use Modules\DigitalPreservation\Domain\Enums\PreservationProvider;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatch;

/**
 * InfoCert LegalDoc adapter. Authenticates with a bearer token (`api_key`) and an organisation identifier.
 */
final class InfoCertConnector extends AbstractHttpConservatorConnector
{
    public function provider(): PreservationProvider
    {
        return PreservationProvider::InfoCert;
    }

    protected function defaultBaseUrl(): string
    {
        return 'https://legaldoc.infocert.it/api';
    }

    protected function depositPath(): string
    {
        return '/archive/packages';
    }

    protected function pingPath(): string
    {
        return '/status';
    }

    protected function authHeaders(array $credentials): array
    {
        return ['Authorization' => 'Bearer '.(string) ($credentials['api_key'] ?? ''), 'X-Organization' => (string) ($credentials['organization_id'] ?? '')];
    }

    protected function depositPayload(PreservationBatch $batch, string $pdvXml, array $files, array $credentials): array
    {
        return [
            'reference' => $batch->batch_number, 'index' => ['filename' => 'PdV.xml', 'sha256' => hash('sha256', $pdvXml), 'data' => base64_encode($pdvXml)],
            'files' => array_map(static fn (string $path, string $content): array => ['filename' => basename($path), 'sha256' => hash('sha256', $content), 'data' => base64_encode($content)], array_keys($files), array_values($files)),
        ];
    }

    protected function externalId(array $json): ?string
    {
        return isset($json['packageId']) ? (string) $json['packageId'] : null;
    }

    protected function receiptXml(array $json): ?string
    {
        return isset($json['rdv']) ? (string) $json['rdv'] : null;
    }
}
