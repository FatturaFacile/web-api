<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Services;

use Illuminate\Support\Facades\Cache;
use Modules\ElectronicSignature\Exceptions\SignatureException;

/**
 * Authenticates the callbacks of the providers. The sender signs `<timestamp>.<raw body>` with HMAC-SHA256 and the shared
 * webhook secret of the company configuration; the signature (hex, optionally prefixed `sha256=`) and the Unix timestamp
 * travel in headers. A callback outside the tolerance window is stale, and an event id that was already seen is a replay.
 */
final class WebhookAuthenticator
{
    public const string SignatureHeader = 'x-signature';

    public const string TimestampHeader = 'x-signature-timestamp';

    /**
     * @param  array<string, string>  $headers  lower-case header names
     *
     * @throws SignatureException 401 when the signature is missing or wrong, 409 for a replayed event
     */
    public function assertAuthentic(string $payload, array $headers, ?string $secret, string $namespace, string $eventId): void
    {
        $timestamp = $headers[self::TimestampHeader] ?? '';
        $signature = strtolower(preg_replace('/^sha256=/i', '', $headers[self::SignatureHeader] ?? '') ?? '');

        if ($secret === null || $secret === '' || $timestamp === '' || $signature === '' || ! ctype_digit($timestamp)) {
            throw new SignatureException('webhook_unauthenticated', 401);
        }

        if (! hash_equals(hash_hmac('sha256', $timestamp.'.'.$payload, $secret), $signature)) {
            throw new SignatureException('webhook_unauthenticated', 401);
        }

        $tolerance = (int) config('electronic_signature.webhooks.tolerance_seconds', 300);

        if (abs(time() - (int) $timestamp) > $tolerance) {
            throw new SignatureException('webhook_stale', 401);
        }

        // Add returns false when the key exists: the same event id twice inside the retention window is a replay.
        if (! Cache::add('esig:webhook:'.$namespace.':'.hash('sha256', $eventId), 1, $tolerance * 2)) {
            throw new SignatureException('webhook_replayed', 409);
        }
    }

    /**
     * The header values a sender computes (used by tests and the sandbox simulators).
     *
     * @return array{x-signature: string, x-signature-timestamp: string}
     */
    public static function sign(string $payload, string $secret, ?int $timestamp = null): array
    {
        $timestamp ??= time();

        return [
            self::SignatureHeader => hash_hmac('sha256', $timestamp.'.'.$payload, $secret),
            self::TimestampHeader => (string) $timestamp,
        ];
    }
}
