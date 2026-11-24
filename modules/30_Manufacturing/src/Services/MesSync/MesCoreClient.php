<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\MesSync;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;
use Modules\Manufacturing\Support\MesHmac;
use RuntimeException;

/**
 * Outbound, signed calls from this API to the MES Core. The body is serialised once and the same bytes are signed and
 * sent, so the receiver verifies exactly what was signed. Every attempt gets a fresh timestamp.
 */
final readonly class MesCoreClient
{
    private const string RefEventsPath = '/api/v1/mes/ref-events';

    /**
     * Whether the push to the MES Core is configured.
     */
    public function enabled(): bool
    {
        return $this->baseUrl() !== '' && $this->secret() !== '';
    }

    /**
     * Announces one reference data change as a payload-less nudge (the shape the MES Core `ref-events` endpoint reads).
     *
     * @param  array{tenant_id: string, resource: string, id: string, seq: int, revision: int, deleted: bool, payload: null}  $event
     *
     * @throws RuntimeException when the MES Core is unreachable or does not accept the event
     */
    public function pushReferenceEvent(array $event): void
    {
        try {
            $body = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('The reference event cannot be encoded.', 0, $exception);
        }

        try {
            $response = Http::withBody($body, 'application/json')
                ->withHeaders([
                    MesHmac::Header => MesHmac::header($this->keyId(), $this->secret(), $body, now()->getTimestamp()),
                    'X-Tenant-Id' => $event['tenant_id'],
                ])
                ->timeout((int) config('manufacturing_mes.core.timeout_seconds'))
                ->post(rtrim($this->baseUrl(), '/').self::RefEventsPath);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('The MES Core is unreachable.', 0, $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException('The MES Core answered '.$response->status().' to the reference event.');
        }
    }

    private function baseUrl(): string
    {
        return (string) config('manufacturing_mes.core.base_url');
    }

    private function keyId(): string
    {
        return (string) config('manufacturing_mes.core.key_id');
    }

    private function secret(): string
    {
        return (string) config('manufacturing_mes.core.secret');
    }
}
