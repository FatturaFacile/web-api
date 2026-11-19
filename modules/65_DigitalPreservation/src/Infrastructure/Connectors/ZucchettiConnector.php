<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Infrastructure\Connectors;

use Modules\DigitalPreservation\Domain\Enums\PreservationProvider;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatch;

/**
 * Zucchetti Digital Hub / Infinity conservation adapter. Authenticates with a username and API key.
 */
final class ZucchettiConnector extends AbstractHttpConservatorConnector
{
    public function provider(): PreservationProvider
    {
        return PreservationProvider::Zucchetti;
    }

    protected function defaultBaseUrl(): string
    {
        return 'https://digitalhub.zucchetti.it/api';
    }

    protected function depositPath(): string
    {
        return '/conservation/volumes';
    }

    protected function pingPath(): string
    {
        return '/conservation/ping';
    }

    protected function authHeaders(array $credentials): array
    {
        return ['Authorization' => 'Basic '.base64_encode((string) ($credentials['username'] ?? '').':'.(string) ($credentials['api_key'] ?? ''))];
    }

    protected function depositPayload(PreservationBatch $batch, string $pdvXml, array $files, array $credentials): array
    {
        return [
            'volumeName' => $batch->batch_number, 'pdvXml' => base64_encode($pdvXml), 'pdvDigest' => hash('sha256', $pdvXml),
            'items' => array_map(static fn (string $path, string $content): array => ['name' => basename($path), 'digest' => hash('sha256', $content), 'payload' => base64_encode($content)], array_keys($files), array_values($files)),
        ];
    }

    protected function externalId(array $json): ?string
    {
        return isset($json['volumeId']) ? (string) $json['volumeId'] : null;
    }

    protected function receiptXml(array $json): ?string
    {
        return isset($json['rdvXml']) ? (string) $json['rdvXml'] : null;
    }
}
