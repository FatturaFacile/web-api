<?php

declare(strict_types=1);

namespace Modules\Integrations\Gateways;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Modules\Integrations\Contracts\PaymentLinkGateway;
use Modules\Integrations\DTOs\PaymentEvent;
use Modules\Integrations\DTOs\PaymentLinkCreated;
use Modules\Integrations\DTOs\PaymentLinkRequest;
use Modules\Integrations\Exceptions\PaymentGatewayException;

/**
 * Stripe Checkout Sessions (https://docs.stripe.com/api/checkout/sessions/create): the customer pays on the page hosted
 * by Stripe, and `checkout.session.completed` confirms the payment.
 */
final class StripePaymentLinkGateway implements PaymentLinkGateway
{
    private const int SignatureToleranceSeconds = 300;

    public function name(): string
    {
        return 'stripe';
    }

    public function createLink(PaymentLinkRequest $request, array $credentials): PaymentLinkCreated
    {
        $secretKey = $credentials['secret_key'] ?? throw new PaymentGatewayException('The Stripe secret key is not configured.');

        try {
            $response = Http::timeout(20)->asForm()->withBasicAuth($secretKey, '')->post('https://api.stripe.com/v1/checkout/sessions', [
                'mode' => 'payment',
                'client_reference_id' => $request->reference,
                'success_url' => url('/payment-link/completed'),
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => strtolower($request->currency),
                        'unit_amount' => (int) bcmul($request->amount, '100', 0),
                        'product_data' => ['name' => $request->description],
                    ],
                ]],
            ]);
        } catch (ConnectionException) {
            throw new PaymentGatewayException('Stripe cannot be reached.');
        }

        if (! $response->successful() || ! is_string($response->json('url'))) {
            throw new PaymentGatewayException((string) ($response->json('error.message') ?? 'Stripe refused the payment link.'));
        }

        return new PaymentLinkCreated((string) $response->json('id'), (string) $response->json('url'));
    }

    public function parseWebhook(Request $request, array $credentials): ?PaymentEvent
    {
        $payload = $request->getContent();
        $this->assertSigned($payload, (string) $request->header('Stripe-Signature'), (string) ($credentials['webhook_secret'] ?? ''));

        $event = json_decode($payload, true);
        $session = $event['data']['object'] ?? null;

        if (($event['type'] ?? null) !== 'checkout.session.completed' || ! is_array($session) || ($session['payment_status'] ?? null) !== 'paid') {
            return null;
        }

        return new PaymentEvent(
            (string) $event['id'],
            (string) $session['id'],
            bcdiv((string) $session['amount_total'], '100', 2),
            strtoupper((string) $session['currency']),
        );
    }

    /**
     * The header is `t=<timestamp>,v1=<signature>[,v0=...]`, the signature is an HMAC-SHA256 of `<timestamp>.<raw body>`
     * with the endpoint secret. A header can hold several v1 values while a secret is being rolled.
     */
    private function assertSigned(string $payload, string $header, string $secret): void
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($name === 't') {
                $timestamp = $value;
            } elseif ($name === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($secret === '' || $timestamp === null || ! ctype_digit($timestamp) || $signatures === []) {
            throw new PaymentGatewayException('Invalid webhook signature.');
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
        $matches = array_filter($signatures, static fn (string $signature): bool => hash_equals($expected, $signature));

        if ($matches === [] || abs(now()->getTimestamp() - (int) $timestamp) > self::SignatureToleranceSeconds) {
            throw new PaymentGatewayException('Invalid webhook signature.');
        }
    }
}
