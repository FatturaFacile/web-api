<?php

declare(strict_types=1);

namespace Tests\Support;

use OpenSSLAsymmetricKey;

/**
 * A throw-away OpenID Connect identity provider for tests: an RSA key generated at runtime, its JWKS, and signed (or
 * deliberately forged) id_tokens. Nothing here is a fixed secret.
 */
final class OidcTestIdp
{
    private OpenSSLAsymmetricKey $key;

    /**
     * @var array{n: string, e: string}
     */
    private array $public;

    public function __construct(public readonly string $kid = 'test-key-1')
    {
        $key = self::generateKey();
        $this->key = $key;
        $details = openssl_pkey_get_details($key);
        $this->public = ['n' => self::b64(ltrim($details['rsa']['n'], "\0")), 'e' => self::b64($details['rsa']['e'])];
    }

    /**
     * @return array{keys: list<array<string, string>>}
     */
    public function jwks(): array
    {
        return ['keys' => [['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $this->kid] + $this->public]];
    }

    /**
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>  $headerOverrides
     */
    public function idToken(array $claims, array $headerOverrides = []): string
    {
        $header = array_merge(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $this->kid], $headerOverrides);
        $input = self::b64((string) json_encode($header)).'.'.self::b64((string) json_encode($claims));
        openssl_sign($input, $signature, $this->key, OPENSSL_ALGO_SHA256);

        return $input.'.'.self::b64($signature);
    }

    /**
     * A token that claims RS256 but is signed with HMAC using the public key material as secret (algorithm confusion).
     *
     * @param  array<string, mixed>  $claims
     */
    public function hmacForgery(array $claims): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT', 'kid' => $this->kid];
        $input = self::b64((string) json_encode($header)).'.'.self::b64((string) json_encode($claims));

        return $input.'.'.self::b64(hash_hmac('sha256', $input, $this->public['n'], true));
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    public function unsigned(array $claims): string
    {
        return self::b64((string) json_encode(['alg' => 'none', 'typ' => 'JWT'])).'.'.self::b64((string) json_encode($claims)).'.'.self::b64('x');
    }

    /**
     * Generates an RSA key. Where the PHP build has no OpenSSL configuration file (Windows), a minimal one is written to
     * the temporary directory.
     */
    public static function generateKey(): OpenSSLAsymmetricKey
    {
        $options = ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048];
        $key = openssl_pkey_new($options);

        if ($key === false) {
            $config = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ff-openssl-minimal.cnf';
            file_put_contents($config, '[req]
distinguished_name=dn
[dn]
');
            $key = openssl_pkey_new($options + ['config' => $config]);
        }

        return $key;
    }

    private static function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
