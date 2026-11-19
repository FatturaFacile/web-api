<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Infrastructure\Connectors;

use Modules\DigitalPreservation\Domain\Enums\PreservationProvider;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatch;

/**
 * Aruba (DocFly / Conservazione Sostitutiva) REST adapter. Authenticates with an API key and tenant code.
 */
final class ArubaPecConnector extends AbstractHttpConservatorConnector
{
    public function provider(): PreservationProvider
    {
        return PreservationProvider::ArubaPec;
    }

    protected function defaultBaseUrl(): string
    {
        return 'https://api.conservazione.aruba.it';
    }

    protected function depositPath(): string
    {
        return '/v1/deposits';
    }

    protected function pingPath(): string
    {
        return '/v1/ping';
    }

    protected function authHeaders(array $credentials): array
    {
        return ['X-Api-Key' => (string) ($credentials['api_key'] ?? ''), 'X-Customer-Code' => (string) ($credentials['customer_code'] ?? '')];
    }

    protected function depositPayload(PreservationBatch $batch, string $pdvXml, array $files, array $credentials): array
    {
        return [
            'packageId' => 'PDV-'.$batch->batch_number, 'indexFile' => base64_encode($pdvXml), 'indexSha256' => hash('sha256', $pdvXml),
            'documents' => array_map(static fn (string $path, string $content): array => ['name' => basename($path), 'sha256' => hash('sha256', $content), 'content' => base64_encode($content)], array_keys($files), array_values($files)),
        ];
    }

    protected function externalId(array $json): ?string
    {
        return isset($json['depositId']) ? (string) $json['depositId'] : null;
    }

    protected function receiptXml(array $json): ?string
    {
        return isset($json['receiptXml']) ? (string) $json['receiptXml'] : null;
    }
}
