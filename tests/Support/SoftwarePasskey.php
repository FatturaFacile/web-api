<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * A software authenticator for tests: an ES256 key pair that produces the same attestation ("none") and assertion
 * payloads a browser sends from navigator.credentials.create() and get(), so the real WebAuthn verification runs.
 */
final class SoftwarePasskey
{
    public string $credentialId;

    private \OpenSSLAsymmetricKey $key;

    public function __construct(private readonly string $rpId, private readonly string $origin, public int $counter = 0)
    {
        $this->credentialId = random_bytes(32);
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC, 'config' => self::opensslConfig()]);
        assert($key !== false);
        $this->key = $key;
    }

    /**
     * @param  array<string, mixed>  $options  creation options as answered by the API
     * @return array<string, mixed>
     */
    public function create(array $options, bool $userVerified = true): array
    {
        $client = json_encode(['type' => 'webauthn.create', 'challenge' => $options['challenge'], 'origin' => $this->origin], JSON_THROW_ON_ERROR);
        $authData = hash('sha256', $this->rpId, true)
            .chr(0x41 | ($userVerified ? 0x04 : 0))
            .pack('N', $this->counter)
            .str_repeat("\0", 16)
            .pack('n', strlen($this->credentialId)).$this->credentialId
            .$this->coseKey();
        $attestation = self::map([['fmt', self::text('none')], ['attStmt', self::head(5, 0)], ['authData', self::bytes($authData)]]);

        return [
            'id' => self::b64($this->credentialId),
            'rawId' => self::b64($this->credentialId),
            'type' => 'public-key',
            'response' => ['clientDataJSON' => self::b64($client), 'attestationObject' => self::b64($attestation)],
        ];
    }

    /**
     * @param  array<string, mixed>  $options  request options as answered by the API
     * @return array<string, mixed>
     */
    public function assert(array $options, string $userHandle, ?int $counter = null): array
    {
        $this->counter = $counter ?? $this->counter + 1;
        $client = json_encode(['type' => 'webauthn.get', 'challenge' => $options['challenge'], 'origin' => $this->origin], JSON_THROW_ON_ERROR);
        $authData = hash('sha256', $this->rpId, true).chr(0x05).pack('N', $this->counter);
        openssl_sign($authData.hash('sha256', $client, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return [
            'id' => self::b64($this->credentialId),
            'rawId' => self::b64($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::b64($client),
                'authenticatorData' => self::b64($authData),
                'signature' => self::b64($signature),
                'userHandle' => self::b64($userHandle),
            ],
        ];
    }

    /** Windows PHP builds ship without openssl.cnf: key generation then needs an explicit minimal one. */
    private static function opensslConfig(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'passkey-test-openssl.cnf';
        if (! is_file($path)) {
            file_put_contents($path, '[req]
distinguished_name=dn
[dn]
');
        }

        return $path;
    }

    private function coseKey(): string
    {
        $ec = openssl_pkey_get_details($this->key)['ec'];

        return self::head(5, 5)
            .self::head(0, 1).self::head(0, 2)
            .self::head(0, 3).self::head(1, 6)
            .self::head(1, 0).self::head(0, 1)
            .self::head(1, 1).self::bytes(str_pad($ec['x'], 32, "\0", STR_PAD_LEFT))
            .self::head(1, 2).self::bytes(str_pad($ec['y'], 32, "\0", STR_PAD_LEFT));
    }

    public static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function head(int $major, int $n): string
    {
        return match (true) {
            $n < 24 => chr(($major << 5) | $n),
            $n < 256 => chr(($major << 5) | 24).chr($n),
            default => chr(($major << 5) | 25).pack('n', $n),
        };
    }

    private static function text(string $s): string
    {
        return self::head(3, strlen($s)).$s;
    }

    private static function bytes(string $s): string
    {
        return self::head(2, strlen($s)).$s;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $pairs  text key and already encoded value
     */
    private static function map(array $pairs): string
    {
        return self::head(5, count($pairs)).implode('', array_map(static fn (array $p): string => self::text($p[0]).$p[1], $pairs));
    }
}
