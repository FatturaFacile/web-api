<?php

declare(strict_types=1);

namespace Modules\Marketplace\Connectors;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\Integrations\Webhooks\Exceptions\UnsafeWebhookUrl;
use Modules\Integrations\Webhooks\WebhookSigner;
use Modules\Integrations\Webhooks\WebhookUrlGuard;
use Modules\Marketplace\Contracts\Connector;
use Modules\Marketplace\Exceptions\ConnectorException;
use Modules\Marketplace\Support\ConfigField;
use Modules\Marketplace\Support\ConnectorContext;
use Modules\Marketplace\Support\ConnectorDefinition;
use Modules\Marketplace\Support\HealthCheckResult;
use Modules\Marketplace\Support\SyncResult;
use Throwable;

/**
 * Bridge to the webhooks of the Integrations module: it posts a signed event to an endpoint of the company, with the
 * same signature scheme and the same address guard (https, public addresses, no redirects) as the webhook deliveries, so a
 * receiver verifies marketplace events and webhook events with the same code.
 */
final class WebhookConnector implements Connector
{
    public const string Key = 'generic-webhook';

    public function __construct(private readonly WebhookUrlGuard $urls, private readonly WebhookSigner $signer, private readonly \App\Services\Security\OutboundUrlGuard $outbound) {}

    public function definition(): ConnectorDefinition
    {
        return new ConnectorDefinition(
            key: self::Key,
            name: 'Signed webhook',
            description: 'Posts signed events to an HTTPS endpoint of yours, with the same signature as the webhooks of the integrations module.',
            category: ConnectorDefinition::Automation,
            version: '1.0.0',
            fields: [
                new ConfigField('endpoint_url', 'Endpoint URL', 'url', help: 'HTTPS address that receives the events'),
                new ConfigField('signing_secret', 'Signing secret', 'secret', help: 'Used to sign every event (X-FF-Webhook-Signature)'),
            ],
            requiredScopes: ['reporting.read'],
            resources: ['events'],
            rateLimitPerMinute: 30,
            maxRateLimitPerMinute: 120,
            vendor: 'Fattura Facile',
        );
    }

    public function checkHealth(ConnectorContext $context): HealthCheckResult
    {
        $started = hrtime(true);

        try {
            $response = $this->post($context, 'marketplace.ping');
        } catch (ConnectorException $exception) {
            return new HealthCheckResult(HealthCheckResult::Unhealthy, $exception->getMessage());
        }

        $latency = (int) round((hrtime(true) - $started) / 1_000_000);

        return $response < 400
            ? HealthCheckResult::healthy($latency)
            : new HealthCheckResult($response >= 500 ? HealthCheckResult::Unhealthy : HealthCheckResult::Degraded, __('marketplace_errors.health.unexpected_status', ['status' => $response]), $latency);
    }

    public function sync(ConnectorContext $context, string $resource): SyncResult
    {
        $status = $this->post($context, 'marketplace.sync');

        if ($status >= 400) {
            throw new ConnectorException(__('marketplace_errors.sync.status', ['status' => $status]));
        }

        return new SyncResult(1, 0, __('marketplace_errors.sync.delivered'));
    }

    /**
     * @throws ConnectorException
     */
    private function post(ConnectorContext $context, string $event): int
    {
        $url = (string) $context->setting('endpoint_url');
        $secret = $context->credential('signing_secret') ?? throw new ConnectorException(__('marketplace_errors.sync.credential_missing', ['field' => 'signing_secret']));

        try {
            $this->urls->assertSafe($url);
            $timestamp = time();
            $body = json_encode(['event' => $event, 'installation_id' => $context->installationId, 'sent_at' => gmdate('c', $timestamp)], JSON_THROW_ON_ERROR);

            return $this->outbound->protect(Http::timeout(max(1, (int) config('marketplace.http_timeout_seconds', 10)))->withHeaders([
                WebhookSigner::SignatureHeader => $this->signer->header($timestamp, $body, [$secret]),
            ])->withBody($body, 'application/json'))->post($url)->status();
        } catch (UnsafeWebhookUrl) {
            throw new ConnectorException(__('marketplace_errors.health.address_refused'));
        } catch (ConnectionException) {
            throw new ConnectorException(__('marketplace_errors.health.unreachable'));
        } catch (Throwable) {
            throw new ConnectorException(__('marketplace_errors.health.failed'));
        }
    }
}
