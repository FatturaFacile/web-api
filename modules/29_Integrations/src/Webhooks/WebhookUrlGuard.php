<?php

declare(strict_types=1);

namespace Modules\Integrations\Webhooks;

use App\Exceptions\UnsafeOutboundUrlException;
use App\Services\Security\OutboundTarget;
use App\Services\Security\OutboundUrlGuard;
use Modules\Integrations\Webhooks\Exceptions\UnsafeWebhookUrl;

/**
 * SSRF protection for the URLs customers give us. The address rules are those of the application-wide
 * {@see OutboundUrlGuard} (every address the host resolves to must be public: loopback, private, link-local with the
 * cloud metadata address, carrier-grade NAT, multicast, reserved, IPv4-mapped / NAT64 / 6to4 / Teredo and the numeric
 * IPv4 spellings are refused); a webhook URL must also be https, carry no credentials and use port 443 or a port from
 * 1024.
 *
 * The check runs when an endpoint is saved or enabled and AGAIN at every delivery, because DNS can change. At delivery
 * the same OutboundUrlGuard pins the checked addresses for curl (no second lookup: no DNS rebinding) and disables
 * redirects (see WebhookDeliveryService::send()).
 */
final class WebhookUrlGuard
{
    public function __construct(private readonly OutboundUrlGuard $outbound) {}

    /**
     * @throws UnsafeWebhookUrl
     */
    public function assertSafe(string $url): OutboundTarget
    {
        $parts = parse_url($url);

        if ($parts === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            throw new UnsafeWebhookUrl('Only https URLs are accepted.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeWebhookUrl('The URL must not contain credentials.');
        }

        $port = $parts['port'] ?? 443;

        if ($port !== 443 && $port < 1024) {
            throw new UnsafeWebhookUrl('The port must be 443 or 1024 and above.');
        }

        try {
            return $this->outbound->inspectUrl($url, ['https']);
        } catch (UnsafeOutboundUrlException $exception) {
            throw new UnsafeWebhookUrl($exception->getMessage(), 0, $exception);
        }
    }
}
