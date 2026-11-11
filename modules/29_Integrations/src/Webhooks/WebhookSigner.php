<?php

declare(strict_types=1);

namespace Modules\Integrations\Webhooks;

/**
 * Signs and verifies webhook payloads: HMAC-SHA256 over "{timestamp}.{raw body}" keyed with the signing secret of the
 * endpoint (the whole string, "whsec_" prefix included), sent as
 *
 *     X-FF-Webhook-Signature: t=1731312000,v1=<hex>[,v1=<hex>]
 *
 * The scheme is the one popularised by Stripe ("t=" and "v1=" elements) so existing verification helpers port easily; it
 * is not Stripe's header, and the header name is ours. While a rotated secret overlaps, the header carries one v1 value
 * per valid secret and the receiver accepts the request when any of them matches.
 */
final class WebhookSigner
{
    public const string SignatureHeader = 'X-FF-Webhook-Signature';

    /**
     * @param  list<string>  $secrets
     */
    public function header(int $timestamp, string $body, array $secrets): string
    {
        $parts = ["t={$timestamp}"];

        foreach ($secrets as $secret) {
            $parts[] = 'v1='.self::signature($timestamp, $body, $secret);
        }

        return implode(',', $parts);
    }

    public static function signature(int $timestamp, string $body, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /**
     * The verification a receiver must perform, kept here as the reference implementation (and used by the tests).
     * Constant-time comparison; a timestamp further than $toleranceSeconds from now is rejected to stop replays.
     */
    public static function verify(string $header, string $body, string $secret, int $toleranceSeconds = 300, ?int $now = null): bool
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $element) {
            [$key, $value] = array_pad(explode('=', trim($element), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === [] || abs(($now ?? time()) - $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = self::signature($timestamp, $body, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
