<?php

declare(strict_types=1);

namespace Tests\Support;

use OpenSSLAsymmetricKey;

/**
 * A throw-away OpenID Connect identity provider for tests: an RSA key (or an EC P-256 key for ES256) generated at runtime,
 * its JWKS, and signed (or deliberately forged) id_tokens. Nothing here is a fixed secret.
 */
final class OidcTestIdp
{
    private OpenSSLAsymmetricKey $key;

    /**
     * @var array<string, string>
     */
    private array $public;

    public function __construct(public readonly string $kid = 'test-key-1', public readonly bool $ec = false)
    {
        $key = $ec ? self::generateEcKey() : self::generateKey();
        $this->key = $key;
        $details = openssl_pkey_get_details($key);
        $this->public = $ec
            ? ['x' => self::b64(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)), 'y' => self::b64(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT))]
            : ['n' => self::b64(ltrim($details['rsa']['n'], "\0")), 'e' => self::b64($details['rsa']['e'])];
    }

    /**
     * @return array{keys: list<array<string, string>>}
     */
    public function jwks(): array
    {
        if ($this->ec) {
            return ['keys' => [['kty' => 'EC', 'crv' => 'P-256', 'use' => 'sig', 'alg' => 'ES256', 'kid' => $this->kid] + $this->public]];
        }

        return ['keys' => [['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $this->kid] + $this->public]];
    }

    /**
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>  $headerOverrides
     */
    public function idToken(array $claims, array $headerOverrides = []): string
    {
        $header = array_merge(['alg' => $this->ec ? 'ES256' : 'RS256', 'typ' => 'JWT', 'kid' => $this->kid], $headerOverrides);
        $input = self::b64((string) json_encode($header)).'.'.self::b64((string) json_encode($claims));
        openssl_sign($input, $signature, $this->key, OPENSSL_ALGO_SHA256);

        return $input.'.'.self::b64($this->ec ? self::derToRaw($signature) : $signature);
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

    /**
     * An EC P-256 key, with the same fallback for a PHP build without an OpenSSL configuration file.
     */
    public static function generateEcKey(): OpenSSLAsymmetricKey
    {
        $options = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
        $key = openssl_pkey_new($options);

        if ($key === false) {
            $config = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ff-openssl-minimal.cnf';
            file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n");
            $key = openssl_pkey_new($options + ['config' => $config]);
        }

        return $key;
    }

    /**
     * The raw R || S form of a DER ECDSA signature, as a JWS carries it.
     */
    public static function derToRaw(string $der): string
    {
        $offset = 2 + ((ord($der[1]) & 0x80) !== 0 ? (ord($der[1]) & 0x7F) : 0);
        $parts = [];

        foreach ([0, 1] as $ignored) {
            $length = ord($der[$offset + 1]);
            $parts[] = str_pad(ltrim(substr($der, $offset + 2, $length), "\0"), 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $parts[0].$parts[1];
    }

    private static function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
