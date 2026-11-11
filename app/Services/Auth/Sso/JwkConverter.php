<?php

declare(strict_types=1);

namespace App\Services\Auth\Sso;

/**
 * Turns an RSA JSON Web Key (RFC 7517, RFC 7518 section 6.3) into a PEM public key that openssl_verify accepts: the
 * modulus and exponent are wrapped in an RSAPublicKey structure inside a SubjectPublicKeyInfo (RFC 5280, rsaEncryption
 * 1.2.840.113549.1.1.1), in DER, then base64 with PEM armour.
 */
final class JwkConverter
{
    /**
     * @param  array<string, mixed>  $jwk
     *
     * @throws SsoException when the key is not a usable RSA key of at least 2048 bits
     */
    public function toPem(array $jwk): string
    {
        if (($jwk['kty'] ?? null) !== 'RSA' || ! is_string($jwk['n'] ?? null) || ! is_string($jwk['e'] ?? null)) {
            throw new SsoException('jwk_unsupported');
        }

        $modulus = self::base64UrlDecode($jwk['n']);
        $exponent = self::base64UrlDecode($jwk['e']);

        if ($modulus === '' || $exponent === '' || strlen(ltrim($modulus, "\0")) < 256) {
            throw new SsoException('jwk_weak_or_malformed');
        }

        $rsaPublicKey = $this->sequence($this->integer($modulus).$this->integer($exponent));
        $algorithm = $this->sequence("\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01\x05\x00");
        $subjectPublicKeyInfo = $this->sequence($algorithm."\x03".$this->length(strlen($rsaPublicKey) + 1)."\x00".$rsaPublicKey);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($subjectPublicKeyInfo), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    public static function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        return $decoded === false ? '' : $decoded;
    }

    private function sequence(string $content): string
    {
        return "\x30".$this->length(strlen($content)).$content;
    }

    private function integer(string $unsigned): string
    {
        $unsigned = ltrim($unsigned, "\0");
        $unsigned = $unsigned === '' ? "\0" : $unsigned;

        if ((ord($unsigned[0]) & 0x80) !== 0) {
            $unsigned = "\0".$unsigned;
        }

        return "\x02".$this->length(strlen($unsigned)).$unsigned;
    }

    private function length(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\0");

        return chr(0x80 | strlen($bytes)).$bytes;
    }
}
