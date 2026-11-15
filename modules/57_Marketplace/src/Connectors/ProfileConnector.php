<?php

declare(strict_types=1);

namespace Modules\Marketplace\Connectors;

use App\Exceptions\UnsafeOutboundUrlException;
use App\Services\Security\OutboundUrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Marketplace\Contracts\Connector;
use Modules\Marketplace\Contracts\PullsRecords;
use Modules\Marketplace\Exceptions\ConnectorException;
use Modules\Marketplace\Support\ConnectorContext;
use Modules\Marketplace\Support\ConnectorDefinition;
use Modules\Marketplace\Support\HealthCheckResult;
use Modules\Marketplace\Support\SyncResult;
use Throwable;

/**
 * A connector driven by data: a {@see ConnectorDefinition} for the catalog and an {@see HttpProfile} that says how to reach
 * the REST API of the external system. It performs real, guarded calls (https only, public addresses only, no redirects,
 * a timeout) for the health check and for the pull of a resource, and counts the records the external system returns.
 *
 * Mapping those records into the records of the application (orders into sales documents, payments into treasury) is the
 * job of the adapter of each system: it replaces the profile connector by a class implementing {@see Connector} under the
 * same key and a higher version.
 */
final class ProfileConnector implements Connector, PullsRecords
{
    public function __construct(
        private readonly ConnectorDefinition $definition,
        private readonly HttpProfile $profile,
        private readonly OutboundUrlGuard $outbound,
    ) {}

    public function definition(): ConnectorDefinition
    {
        return $this->definition;
    }

    public function checkHealth(ConnectorContext $context): HealthCheckResult
    {
        $started = hrtime(true);

        try {
            $response = $this->client($context)->get($this->url($context, $this->profile->healthPath));
        } catch (ConnectionException) {
            return new HealthCheckResult(HealthCheckResult::Unhealthy, __('marketplace_errors.health.unreachable'));
        } catch (UnsafeOutboundUrlException|ConnectorException) {
            return new HealthCheckResult(HealthCheckResult::Unhealthy, __('marketplace_errors.health.address_refused'));
        } catch (Throwable) {
            return new HealthCheckResult(HealthCheckResult::Unhealthy, __('marketplace_errors.health.failed'));
        }

        $latency = (int) round((hrtime(true) - $started) / 1_000_000);

        return match (true) {
            $response->successful() => HealthCheckResult::healthy($latency),
            in_array($response->status(), [401, 403], true) => new HealthCheckResult(HealthCheckResult::Unhealthy, __('marketplace_errors.health.credentials_rejected'), $latency),
            $response->status() === 429 => new HealthCheckResult(HealthCheckResult::Degraded, __('marketplace_errors.health.rate_limited'), $latency),
            $response->serverError() => new HealthCheckResult(HealthCheckResult::Unhealthy, __('marketplace_errors.health.server_error', ['status' => $response->status()]), $latency),
            default => new HealthCheckResult(HealthCheckResult::Degraded, __('marketplace_errors.health.unexpected_status', ['status' => $response->status()]), $latency),
        };
    }

    public function sync(ConnectorContext $context, string $resource): SyncResult
    {
        $count = count($this->pull($context, $resource, null));

        return new SyncResult($count, 0, __('marketplace_errors.sync.fetched', ['count' => $count, 'resource' => $resource]));
    }

    public function pull(ConnectorContext $context, string $resource, ?string $cursor): array
    {
        $target = $this->profile->resourcePaths[$resource] ?? throw new ConnectorException(__('marketplace_errors.sync.unknown_resource', ['resource' => $resource]));
        $path = $target['path'];
        $parameter = $this->profile->cursorParameters[$resource] ?? null;

        if ($cursor !== null && $cursor !== '' && $parameter !== null) {
            $path .= (str_contains($path, '?') ? '&' : '?').$parameter.'='.rawurlencode($cursor);
        }

        try {
            $response = $this->client($context)->get($this->url($context, $path));
        } catch (ConnectionException) {
            throw new ConnectorException(__('marketplace_errors.health.unreachable'));
        } catch (UnsafeOutboundUrlException) {
            throw new ConnectorException(__('marketplace_errors.health.address_refused'));
        }

        $this->assertSuccessful($response);
        $json = $response->json();
        $records = $target['collection'] !== null ? data_get($json, $target['collection']) : $json;

        return is_array($records) ? array_values(array_filter($records, is_array(...))) : [];
    }

    /**
     * @throws ConnectorException
     */
    private function assertSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw new ConnectorException(match (true) {
            in_array($response->status(), [401, 403], true) => __('marketplace_errors.health.credentials_rejected'),
            $response->status() === 429 => __('marketplace_errors.health.rate_limited'),
            default => __('marketplace_errors.sync.status', ['status' => $response->status()]),
        });
    }

    private function client(ConnectorContext $context): PendingRequest
    {
        $request = Http::timeout(max(1, (int) config('marketplace.http_timeout_seconds', 10)))->acceptJson();
        $auth = $this->profile->auth;

        $request = match ($auth['type'] ?? 'none') {
            'bearer' => $request->withToken($this->credential($context, $auth['credential'] ?? '')),
            'header' => $request->withHeaders([($auth['name'] ?? 'Authorization') => $this->credential($context, $auth['credential'] ?? '')]),
            'basic' => $request->withBasicAuth($this->credential($context, $auth['user'] ?? ''), $this->credential($context, $auth['password'] ?? '')),
            default => $request,
        };

        return $this->outbound->protect($request);
    }

    private function credential(ConnectorContext $context, string $key): string
    {
        return $context->credential($key) ?? throw new ConnectorException(__('marketplace_errors.sync.credential_missing', ['field' => $key]));
    }

    /**
     * The address of a path of the external system, with the `{setting}` placeholders of the profile filled in. A setting
     * used inside the address may only hold characters an address can have; a setting that is the whole base address
     * (the store URL) must be an https URL, which the outbound guard then checks again on the request.
     *
     * @throws ConnectorException when a setting used in the address is not acceptable
     */
    private function url(ConnectorContext $context, string $path): string
    {
        $template = $this->profile->environments[(string) $context->setting('environment')] ?? $this->profile->baseUrl;
        $full = rtrim($template, '/').'/'.ltrim($path, '/');

        return preg_replace_callback('/{([a-z][a-z0-9_]*)}/', function (array $match) use ($context, $template): string {
            $value = rtrim((string) $context->setting($match[1]), '/');
            $isWholeBase = str_starts_with($template, '{'.$match[1].'}');

            if ($value === '' || preg_match($isWholeBase ? '#^https://[A-Za-z0-9._:/-]+$#' : '/^[A-Za-z0-9._:-]+$/', $value) !== 1) {
                throw new ConnectorException(__('marketplace_errors.health.address_refused'));
            }

            return $value;
        }, $full) ?? '';
    }
}
