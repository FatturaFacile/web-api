<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Infrastructure\Connectors;

use Modules\DigitalPreservation\Domain\Enums\PreservationProvider;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatch;

/**
 * Namirial Archive adapter. Authenticates with a subscription key (`api_key`).
 */
final class NamirialConnector extends AbstractHttpConservatorConnector
{
    public function provider(): PreservationProvider
    {
        return PreservationProvider::Namirial;
    }

    protected function defaultBaseUrl(): string
    {
        return 'https://archive.namirial.com/api/v1';
    }

    protected function depositPath(): string
    {
        return '/submissions';
    }

    protected function pingPath(): string
    {
        return '/health';
    }

    protected function authHeaders(array $credentials): array
    {
        return ['Ocp-Apim-Subscription-Key' => (string) ($credentials['api_key'] ?? '')];
    }

    protected function depositPayload(PreservationBatch $batch, string $pdvXml, array $files, array $credentials): array
    {
        return [
            'submissionCode' => $batch->batch_number, 'pdv' => base64_encode($pdvXml), 'pdvHash' => hash('sha256', $pdvXml), 'hashAlgorithm' => 'SHA-256',
            'attachments' => array_map(static fn (string $path, string $content): array => ['fileName' => basename($path), 'hash' => hash('sha256', $content), 'body' => base64_encode($content)], array_keys($files), array_values($files)),
        ];
    }

    protected function externalId(array $json): ?string
    {
        return isset($json['submissionId']) ? (string) $json['submissionId'] : null;
    }

    protected function receiptXml(array $json): ?string
    {
        return isset($json['receipt']) ? (string) $json['receipt'] : null;
    }
}
