<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Authorization-code + PKCE (RFC 7636, S256 only) for native apps.
 * Codes are single use and short lived.
 */
final class PkceAuthorizationService
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function issueCode(string $userId, string $tenantId, string $codeChallenge, array $context = []): string
    {
        $code = Str::random(64);

        Cache::put($this->key($code), [
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'challenge' => $codeChallenge,
            'context' => $context,
        ], now()->addSeconds((int) config('mobile.pkce.code_ttl_seconds', 120)));

        return $code;
    }

    /**
     * Consume the code if the verifier matches the stored challenge.
     *
     * @return array{user_id: string, tenant_id: string, context: array<string, mixed>}|null
     */
    public function redeem(string $code, string $codeVerifier): ?array
    {
        $payload = Cache::pull($this->key($code)); // single use even on failure

        if (! is_array($payload)) {
            return null;
        }

        if (! hash_equals($payload['challenge'], self::challengeFor($codeVerifier))) {
            return null;
        }

        return [
            'user_id' => $payload['user_id'],
            'tenant_id' => $payload['tenant_id'],
            'context' => $payload['context'],
        ];
    }

    public static function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function key(string $code): string
    {
        return 'mobile:pkce:'.hash('sha256', $code);
    }
}
