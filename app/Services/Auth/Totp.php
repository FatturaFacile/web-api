<?php

declare(strict_types=1);

namespace App\Services\Auth;

/**
 * Time-based one-time passwords (RFC 6238, on HOTP of RFC 4226) with HMAC-SHA1, 30-second steps and 6 digits: the
 * parameters every authenticator app supports. Secrets travel in Base32 (RFC 4648) without padding.
 */
final class Totp
{
    public const PERIOD_SECONDS = 30;

    public const DIGITS = 6;

    /**
     * Steps accepted before and after the current one, for the clock drift of the phone.
     */
    public const WINDOW = 1;

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * A new 160-bit secret in Base32, the key length RFC 4226 recommends for HMAC-SHA1.
     */
    public function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /**
     * The time step of a code that matches, or null. A step not after $lastUsedStep is refused, so a code works once.
     */
    public function verify(string $base32Secret, string $code, ?int $lastUsedStep = null, ?int $timestamp = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (preg_match('/^\d{'.self::DIGITS.'}$/', $code) !== 1) {
            return null;
        }

        $key = self::base32Decode($base32Secret);
        $currentStep = intdiv($timestamp ?? time(), self::PERIOD_SECONDS);

        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            $step = $currentStep + $offset;

            if ($step < 0 || ($lastUsedStep !== null && $step <= $lastUsedStep)) {
                continue;
            }

            if (hash_equals(self::hotp($key, $step, self::DIGITS), $code)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * The code of a time step: exposed for the tests and the RFC test vectors.
     */
    public function codeAt(string $base32Secret, int $timestamp, int $digits = self::DIGITS): string
    {
        return self::hotp(self::base32Decode($base32Secret), intdiv($timestamp, self::PERIOD_SECONDS), $digits);
    }

    /**
     * The otpauth:// URI that authenticator apps read from a QR code (Key Uri Format).
     */
    public function provisioningUri(string $base32Secret, string $accountName, string $issuer): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($accountName);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $base32Secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD_SECONDS,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $encoded;
    }

    public static function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(rtrim(preg_replace('/\s+/', '', $encoded) ?? '', '='));
        $bits = '';

        foreach (str_split($encoded) as $character) {
            $value = strpos(self::BASE32_ALPHABET, $character);

            if ($value === false) {
                throw new \InvalidArgumentException('The secret is not valid Base32.');
            }

            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }

    /**
     * HOTP with dynamic truncation (RFC 4226 section 5.3).
     */
    private static function hotp(string $key, int $counter, int $digits): string
    {
        $hash = hash_hmac('sha1', pack('J', $counter), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }
}
