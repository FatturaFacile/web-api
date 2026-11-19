<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Infrastructure\Connectors;

use App\Exceptions\UnsafeOutboundUrlException;
use App\Services\Security\OutboundUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\DigitalPreservation\Domain\Contracts\PreservationConnectorInterface;
use Modules\DigitalPreservation\Domain\Exceptions\ConnectorException;
use Modules\DigitalPreservation\Domain\ValueObjects\ConnectorReceipt;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatch;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationConfiguration;

/**
 * Shared HTTPS client of the accredited conservator adapters: credential handling (per-tenant, encrypted), retries on
 * connection errors and 5xx, SSRF guard, structured error mapping and RdV extraction.
 *
 * The endpoint paths and field names of each adapter are the application's integration profile. Each provider's real
 * contract has to be confirmed with its documentation and a contract before production use; the profile is overridable
 * per tenant through the `endpoints` and `paths` credential keys.
 */
abstract class AbstractHttpConservatorConnector implements PreservationConnectorInterface
{
    public function __construct(private readonly OutboundUrlGuard $guard) {}

    /**
     * Default base URL of the provider API.
     */
    abstract protected function defaultBaseUrl(): string;

    /**
     * Path of the deposit call.
     */
    abstract protected function depositPath(): string;

    /**
     * Path of the health-check call.
     */
    abstract protected function pingPath(): string;

    /**
     * Authentication headers from the credentials.
     *
     * @param  array<string, mixed>  $credentials
     * @return array<string, string>
     */
    abstract protected function authHeaders(array $credentials): array;

    /**
     * JSON body of the deposit call.
     *
     * @param  array<string, string>  $files
     * @return array<string, mixed>
     */
    abstract protected function depositPayload(PreservationBatch $batch, string $pdvXml, array $files, array $credentials): array;

    /**
     * Reference the provider assigned, from its response.
     *
     * @param  array<string, mixed>  $json
     */
    abstract protected function externalId(array $json): ?string;

    /**
     * RdV XML from the response, when the provider returns it inline.
     *
     * @param  array<string, mixed>  $json
     */
    abstract protected function receiptXml(array $json): ?string;

    /**
     * The generic form fields (`endpoint`, `client_id`, `client_secret`) map onto the provider keys, which win when given.
     *
     * @param  array<string, mixed>|null  $credentials
     * @return array<string, mixed>
     */
    protected function normalize(?array $credentials): array
    {
        $credentials ??= [];

        if ($credentials === []) {
            return [];
        }

        return $credentials + array_filter([
            'base_url' => $credentials['endpoint'] ?? null,
            'api_key' => $credentials['client_secret'] ?? null,
            'username' => $credentials['client_id'] ?? null,
            'customer_code' => $credentials['client_id'] ?? null,
            'organization_id' => $credentials['client_id'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    public function dispatch(PreservationBatch $batch, string $pdvXml, array $files, PreservationConfiguration $configuration): ConnectorReceipt
    {
        $credentials = $this->normalize($configuration->credentials);
        $response = $this->send('post', $this->url($credentials, $this->depositPath()), $credentials, $this->depositPayload($batch, $pdvXml, $files, $credentials));
        $json = $response->json();
        $json = is_array($json) ? $json : [];
        $externalId = $this->externalId($json);

        if ($externalId === null || $externalId === '') {
            throw new ConnectorException($this->provider()->value.' accepted the request but returned no document reference.');
        }

        $rdv = $this->receiptXml($json) ?? $this->fallbackReceipt($externalId, $pdvXml);

        return new ConnectorReceipt($externalId, $rdv, (bool) ($json['confirmed'] ?? false));
    }

    public function testConnection(PreservationConfiguration $configuration): bool
    {
        $credentials = $this->normalize($configuration->credentials);

        try {
            $this->send('get', $this->url($credentials, $this->pingPath()), $credentials, null);
        } catch (ConnectorException) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    protected function url(array $credentials, string $path): string
    {
        $base = rtrim((string) ($credentials['base_url'] ?? $this->defaultBaseUrl()), '/');
        $path = (string) ($credentials['paths'][$path] ?? $path);

        return $base.'/'.ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>|null  $body
     *
     * @throws ConnectorException
     */
    private function send(string $method, string $url, array $credentials, ?array $body): Response
    {
        if ($credentials === [] || empty($credentials['api_key'] ?? $credentials['username'] ?? null)) {
            throw new ConnectorException($this->provider()->value.' has no credentials configured.');
        }

        try {
            $this->guard->inspectUrl($url, ['https']);
        } catch (UnsafeOutboundUrlException) {
            throw new ConnectorException('The conservator URL is not an allowed https address.');
        }

        /** @var PendingRequest $request */
        $request = Http::timeout(60)
            ->retry((int) config('digital_preservation.connector_retries'), (int) config('digital_preservation.connector_retry_wait_ms'), static fn (\Throwable $e): bool => $e instanceof ConnectionException || ($e instanceof RequestException && $e->response->serverError()), false)
            ->withHeaders($this->authHeaders($credentials))
            ->acceptJson();

        try {
            $response = $this->guard->protect($request)->{$method}($url, $body ?? []);
        } catch (ConnectionException|UnsafeOutboundUrlException $exception) {
            throw new ConnectorException($this->provider()->value.' could not be reached.', 0, $exception);
        } catch (RequestException $exception) {
            throw $this->mapError($exception->response, $exception);
        }

        if ($response->failed()) {
            throw $this->mapError($response);
        }

        return $response;
    }

    private function mapError(Response $response, ?\Throwable $previous = null): ConnectorException
    {
        $detail = (string) ($response->json('message') ?? $response->json('error') ?? $response->reason());
        $kind = match (true) {
            $response->status() === 401, $response->status() === 403 => 'authentication refused',
            $response->status() === 409 => 'duplicate package',
            $response->status() === 422, $response->status() === 400 => 'package rejected',
            $response->serverError() => 'provider unavailable',
            default => 'request failed',
        };

        return new ConnectorException($this->provider()->value." {$kind} (HTTP {$response->status()}): {$detail}", $response->status(), $previous);
    }

    private function fallbackReceipt(string $externalId, string $pdvXml): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<RapportoDiVersamento Conservatore="'.htmlspecialchars($this->provider()->pdvCode(), ENT_XML1).'" Riferimento="'.htmlspecialchars($externalId, ENT_XML1).'">'
            .'<ImprontaPdV Algoritmo="SHA-256">'.hash('sha256', $pdvXml).'</ImprontaPdV><Esito>RICEVUTO</Esito></RapportoDiVersamento>'."\n";
    }
}
