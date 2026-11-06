<?php

declare(strict_types=1);

namespace Modules\Integrations\Gateways;

use Illuminate\Http\Request;
use Modules\Integrations\Contracts\PaymentLinkGateway;
use Modules\Integrations\DTOs\PaymentEvent;
use Modules\Integrations\Exceptions\PaymentGatewayException;
use Modules\Integrations\Models\PaymentLink;

/**
 * Stand-in without a provider: the link points nowhere real and a payment arrives as a webhook signed with HMAC-SHA256
 * in `X-Local-Signature`, with the `webhook_secret` of the tenant. It never moves money.
 */
final class LocalPaymentLinkGateway implements PaymentLinkGateway
{
    public function name(): string
    {
        return 'local';
    }

    public function createLink(PaymentLink $link, string $description, array $credentials): array
    {
        return ['reference' => "local_{$link->id}", 'url' => url("/local-payment/{$link->id}")];
    }

    public function parseWebhook(Request $request, array $credentials): ?PaymentEvent
    {
        $secret = (string) ($credentials['webhook_secret'] ?? '');
        $payload = $request->getContent();

        if ($secret === '' || ! hash_equals(hash_hmac('sha256', $payload, $secret), (string) $request->header('X-Local-Signature'))) {
            throw new PaymentGatewayException('Invalid webhook signature.');
        }

        $event = json_decode($payload, true);

        if (! is_array($event) || ! isset($event['event_id'], $event['reference'], $event['amount'], $event['currency'])) {
            return null;
        }

        return new PaymentEvent((string) $event['event_id'], (string) $event['reference'], (string) $event['amount'], (string) $event['currency']);
    }
}
