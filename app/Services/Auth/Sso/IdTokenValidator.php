<?php

declare(strict_types=1);

namespace App\Services\Auth\Sso;

use App\Models\SsoProvider;

/**
 * Validates an OpenID Connect id_token (OpenID Connect Core 1.0, section 3.1.3.7, and RFC 7519) without a JWT library:
 *
 * - the signature is verified with openssl_verify against the key of the provider JWKS selected by `kid`; only the
 *   RS256, RS384, RS512 and ES256 algorithms (config `sso.allowed_algorithms`) are accepted, never `none` or an HMAC (so a
 *   token cannot be forged by signing it with the public key), and the key must be of the type of the algorithm;
 * - `iss` must equal the configured issuer exactly, `aud` must contain the client id (and `azp`, when there are several
 *   audiences, must be the client id);
 * - `exp` must be in the future, `nbf` and `iat` not in the future, within `sso.clock_skew_seconds`;
 * - `nonce` must match the one of the login that was started;
 * - `sub` must be present.
 *
 * Supported keys: RSA (RS*) and EC P-256 (ES256). ES384, ES512 and EdDSA are not supported.
 */
final class IdTokenValidator
{
    private const OPENSSL_ALGORITHMS = [
        'RS256' => OPENSSL_ALGO_SHA256,
        'RS384' => OPENSSL_ALGO_SHA384,
        'RS512' => OPENSSL_ALGO_SHA512,
        'ES256' => OPENSSL_ALGO_SHA256,
    ];

    public function __construct(
        private readonly JwksKeys $keys,
        private readonly JwkConverter $converter,
    ) {}

    /**
     * @param  string  $expectedNonceHash  SHA-256 (hex) of the nonce sent in the authorization request
     * @return array<string, mixed> the verified claims
     *
     * @throws SsoException with the reason of the first check that failed
     */
    public function validate(string $idToken, SsoProvider $provider, string $expectedNonceHash): array
    {
        $parts = explode('.', $idToken);

        if (count($parts) !== 3 || in_array('', $parts, true)) {
            throw new SsoException('token_malformed');
        }

        $header = json_decode(JwkConverter::base64UrlDecode($parts[0]), true);
        $claims = json_decode(JwkConverter::base64UrlDecode($parts[1]), true);
        $signature = JwkConverter::base64UrlDecode($parts[2]);

        if (! is_array($header) || ! is_array($claims) || $signature === '') {
            throw new SsoException('token_malformed');
        }

        $algorithm = $header['alg'] ?? null;

        if (! is_string($algorithm) || ! in_array($algorithm, (array) config('sso.allowed_algorithms'), true) || ! isset(self::OPENSSL_ALGORITHMS[$algorithm])) {
            throw new SsoException('algorithm_not_allowed', is_string($algorithm) ? $algorithm : '');
        }

        $kid = isset($header['kid']) && is_string($header['kid']) ? $header['kid'] : null;
        // The key type follows the algorithm of the header: a token cannot pick an RSA key to be checked as ECDSA or the reverse.
        $key = openssl_pkey_get_public($this->keys->pemFor($provider, $kid, $algorithm === 'ES256' ? 'EC' : 'RSA'));

        if ($key === false) {
            throw new SsoException('key_unusable');
        }

        if ($algorithm === 'ES256') {
            $signature = $this->converter->es256SignatureToDer($signature);

            if ($signature === '') {
                throw new SsoException('signature_invalid');
            }
        }

        if (openssl_verify($parts[0].'.'.$parts[1], $signature, $key, self::OPENSSL_ALGORITHMS[$algorithm]) !== 1) {
            throw new SsoException('signature_invalid');
        }

        $this->assertClaims($claims, $provider, $expectedNonceHash);

        return $claims;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function assertClaims(array $claims, SsoProvider $provider, string $expectedNonceHash): void
    {
        $now = time();
        $skew = (int) config('sso.clock_skew_seconds');

        if (($claims['iss'] ?? null) !== $provider->issuer) {
            throw new SsoException('issuer_mismatch');
        }

        $audience = $claims['aud'] ?? null;
        $audiences = is_array($audience) ? array_values($audience) : [$audience];

        if (! in_array($provider->client_id, $audiences, true)) {
            throw new SsoException('audience_mismatch');
        }

        if (count($audiences) > 1 && ($claims['azp'] ?? null) !== $provider->client_id) {
            throw new SsoException('authorized_party_mismatch');
        }

        if (! is_int($claims['exp'] ?? null) || $now > $claims['exp'] + $skew) {
            throw new SsoException('token_expired');
        }

        if (isset($claims['nbf']) && (! is_int($claims['nbf']) || $now < $claims['nbf'] - $skew)) {
            throw new SsoException('token_not_yet_valid');
        }

        if (isset($claims['iat']) && (! is_int($claims['iat']) || $now < $claims['iat'] - $skew)) {
            throw new SsoException('token_issued_in_future');
        }

        $nonce = $claims['nonce'] ?? null;

        if (! is_string($nonce) || ! hash_equals($expectedNonceHash, hash('sha256', $nonce))) {
            throw new SsoException('nonce_mismatch');
        }

        if (! is_string($claims['sub'] ?? null) || $claims['sub'] === '' || strlen($claims['sub']) > 255) {
            throw new SsoException('subject_missing');
        }
    }
}
