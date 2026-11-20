<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Infrastructure\Services;

use App\Exceptions\UnsafeOutboundUrlException;
use App\Services\Security\OutboundUrlGuard;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Modules\DigitalPreservation\Domain\Exceptions\PreservationException;
use Modules\DigitalPreservation\Domain\ValueObjects\Sha256Hash;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationConfiguration;
use Modules\DocumentTrust\Exceptions\DocumentTrustException;
use Modules\DocumentTrust\Support\Rfc3161Codec;
use phpseclib3\Math\BigInteger;
use Throwable;

/**
 * RFC 3161 time stamping of the PdV hash. The request is an ASN.1 DER TimeStampReq (SHA-256, OID
 * 2.16.840.1.101.3.4.2.1) sent to the tenant's TSA; the response is validated by the existing {@see Rfc3161Codec}
 * (status, imprint, nonce, generation time and token signature, NOT the certificate chain).
 *
 * Without a TSA URL a deterministic local test authority is used: an HMAC over imprint and time with the application
 * key. It proves nothing to a third party and is never reported as qualified.
 */
final readonly class Rfc3161TimestampService
{
    private const string LocalProvider = 'local-test-tsa';

    public function __construct(private OutboundUrlGuard $guard) {}

    /**
     * @return array{token: string, gen_time: DateTimeImmutable, provider: string, qualified: bool}
     *
     * @throws PreservationException when the authority fails or no authority is available
     */
    public function stamp(string $sha256Hex, ?PreservationConfiguration $configuration): array
    {
        $url = (string) $configuration?->tsa_url;

        if ($url === '') {
            return $this->stampLocally($sha256Hex);
        }

        try {
            $this->guard->inspectUrl($url, ['https']);
        } catch (UnsafeOutboundUrlException) {
            throw new PreservationException('The time-stamp authority URL is not an allowed https address.');
        }

        $digest = Sha256Hash::fromHex($sha256Hex)->binary();
        $nonce = new BigInteger(random_bytes(8), 256);
        $request = Http::timeout(20)->withBody(Rfc3161Codec::buildRequest($digest, $nonce), 'application/timestamp-query')->accept('application/timestamp-reply');
        $credentials = $configuration->tsa_credentials ?? [];

        if (! empty($credentials['username'])) {
            $request = $request->withBasicAuth((string) $credentials['username'], (string) ($credentials['password'] ?? ''));
        }

        try {
            $response = $this->guard->protect($request)->post($url)->throw();
            $token = Rfc3161Codec::parseResponse($response->body(), $digest, $nonce, 'rfc3161', false);
        } catch (DocumentTrustException|ConnectionException|RequestException|UnsafeOutboundUrlException $exception) {
            throw new PreservationException('The time-stamp authority refused or could not be reached: '.$exception->getMessage(), 0, $exception);
        } catch (Throwable $exception) {
            throw new PreservationException('The time-stamp authority failed.', 0, $exception);
        }

        return ['token' => (string) base64_decode((string) $token->token, true), 'gen_time' => $token->issuedAt, 'provider' => $token->provider, 'qualified' => $token->qualified];
    }

    /**
     * Check a stored token against the hash it must cover. A remote token is checked for the presence of the imprint
     * only (the full validation happened when it was issued); a local token is checked by its signature.
     */
    public function verify(string $token, string $sha256Hex): bool
    {
        if ($token === '') {
            return false;
        }

        if ($token[0] === '{') {
            $data = json_decode($token, true);

            if (! is_array($data) || ($data['provider'] ?? null) !== self::LocalProvider || ! hash_equals($sha256Hex, (string) ($data['imprint'] ?? ''))) {
                return false;
            }

            return hash_equals($this->localSignature($sha256Hex, (string) $data['gen_time']), (string) ($data['signature'] ?? ''));
        }

        return str_contains($token, Sha256Hash::fromHex($sha256Hex)->binary());
    }

    /**
     * @return array{token: string, gen_time: DateTimeImmutable, provider: string, qualified: bool}
     */
    private function stampLocally(string $sha256Hex): array
    {
        if (! config('digital_preservation.allow_local_tsa')) {
            throw new PreservationException('No time-stamp authority is configured and the local test authority is disabled.');
        }

        $time = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $genTime = $time->format('Y-m-d\TH:i:s\Z');
        $token = json_encode([
            'provider' => self::LocalProvider, 'imprint' => $sha256Hex, 'gen_time' => $genTime,
            'signature' => $this->localSignature($sha256Hex, $genTime), 'warning' => 'Local test time stamp, not a qualified RFC 3161 token.',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return ['token' => $token, 'gen_time' => $time, 'provider' => self::LocalProvider, 'qualified' => false];
    }

    private function localSignature(string $sha256Hex, string $genTime): string
    {
        return hash_hmac('sha256', $sha256Hex.'|'.$genTime, (string) config('app.key'));
    }
}
