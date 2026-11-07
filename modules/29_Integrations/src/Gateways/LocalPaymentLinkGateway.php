<?php

declare(strict_types=1);

namespace Modules\Integrations\Gateways;

use Illuminate\Http\Request;
use Modules\Integrations\Contracts\PaymentLinkGateway;
use Modules\Integrations\DTOs\PaymentEvent;
use Modules\Integrations\DTOs\PaymentLinkCreated;
use Modules\Integrations\DTOs\PaymentLinkRequest;
use Modules\Integrations\Exceptions\PaymentGatewayException;

/**
 * Stand-in without a provider: the link points nowhere real and a payment arrives as a webhook whose raw JSON body is
 * signed with HMAC-SHA256 in `X-Local-Signature`, with the `webhook_secret` of the credentials. The body states
 * `reference` and `amount`, optionally `event_id`, `currency` and `status` (`paid`, the default, or `failed`). It never
 * moves money.
 */
final class LocalPaymentLinkGateway implements PaymentLinkGateway
{
    public const string SignatureHeader = 'X-Local-Signature';

    public function name(): string
    {
        return 'local';
    }

    public function createLink(PaymentLinkRequest $request, array $credentials): PaymentLinkCreated
    {
        return new PaymentLinkCreated("local_{$request->reference}", url("/local-payment/{$request->reference}"));
    }

    public function parseWebhook(Request $request, array $credentials): ?PaymentEvent
    {
        $secret = (string) ($credentials['webhook_secret'] ?? '');
        $payload = $request->getContent();

        if ($secret === '' || ! hash_equals(self::sign($payload, $secret), (string) $request->header(self::SignatureHeader))) {
            throw new PaymentGatewayException('Invalid webhook signature.');
        }

        $event = json_decode($payload, true);

        if (! is_array($event) || ! isset($event['reference'], $event['amount'])) {
            return null;
        }

        return new PaymentEvent(
            (string) ($event['event_id'] ?? 'local_'.hash('sha256', $payload)),
            (string) $event['reference'],
            bcadd((string) $event['amount'], '0', 2),
            strtoupper((string) ($event['currency'] ?? '')),
            ($event['status'] ?? 'paid') === 'paid',
        );
    }

    /**
     * The signature a caller of the local driver sends for a raw body.
     */
    public static function sign(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }
}
