<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Infrastructure\Webhook;

use Illuminate\Http\Request;
use Modules\SwissEbill\Domain\Exceptions\EbillException;
use Modules\SwissEbill\Models\EbillSetting;

/**
 * Authenticates a webhook of SIX Paynet / PostFinance with the mode configured by the company:
 *  - hmac_sha256: X-Ebill-Signature = hex HMAC-SHA256 of the raw body (with X-Ebill-Timestamp: of "timestamp.body", and the
 *    timestamp must be within the tolerance, which stops replays of captured requests);
 *  - jws_hs256: a compact JWS (HS256) in X-Ebill-Jws or as Bearer token, attached (payload = body) or detached ("header..signature");
 *  - mutual_tls: the reverse proxy verified the client certificate (X-SSL-Client-Verify: SUCCESS) and its fingerprint is the pinned one.
 * Every comparison is constant time; a missing secret rejects the call.
 */
final class WebhookSignatureVerifier
{
    /**
     * @throws EbillException invalid_signature (401)
     */
    public function verify(EbillSetting $setting, Request $request): void
    {
        $ok = match ($setting->webhook_auth_mode) {
            'jws_hs256' => $this->jws($setting, $request),
            'mutual_tls' => $this->mutualTls($setting, $request),
            default => $this->hmac($setting, $request),
        };

        if (! $ok) {
            throw new EbillException('invalid_signature', 'The webhook signature is not valid.', 401);
        }
    }

    private function hmac(EbillSetting $setting, Request $request): bool
    {
        $secret = (string) $setting->webhook_secret;
        $header = trim((string) $request->header('X-Ebill-Signature'));

        if ($secret === '' || $header === '') {
            return false;
        }

        $given = strtolower(str_starts_with($header, 'sha256=') ? substr($header, 7) : $header);
        $timestamp = trim((string) $request->header('X-Ebill-Timestamp'));
        $signed = $request->getContent();

        if ($timestamp !== '') {
            if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > (int) config('swiss_ebill.webhook_tolerance_seconds', 300)) {
                return false;
            }

            $signed = $timestamp.'.'.$signed;
        }

        return hash_equals(hash_hmac('sha256', $signed, $secret), $given);
    }

    private function jws(EbillSetting $setting, Request $request): bool
    {
        $secret = (string) $setting->webhook_secret;
        $token = trim((string) ($request->header('X-Ebill-Jws') ?: $request->bearerToken()));
        $parts = explode('.', $token);

        if ($secret === '' || count($parts) !== 3) {
            return false;
        }

        [$header, $payload, $signature] = $parts;
        $decoded = json_decode((string) $this->base64UrlDecode($header), true);

        // Only HS256 is accepted: "none" and asymmetric algorithms are refused outright.
        if (! is_array($decoded) || ($decoded['alg'] ?? null) !== 'HS256') {
            return false;
        }

        $body = $request->getContent();
        $payloadPart = $payload === '' ? $this->base64UrlEncode($body) : $payload;

        if ($payload !== '' && ! hash_equals($payload, $this->base64UrlEncode($body))) {
            return false;
        }

        return hash_equals($this->base64UrlEncode(hash_hmac('sha256', $header.'.'.$payloadPart, $secret, true)), $signature);
    }

    private function mutualTls(EbillSetting $setting, Request $request): bool
    {
        $pinned = $this->fingerprint((string) $setting->webhook_mtls_fingerprint);
        $given = $this->fingerprint((string) $request->header((string) config('swiss_ebill.mtls_fingerprint_header')));

        if ($pinned === '' || $given === '') {
            return false;
        }

        return strtoupper((string) $request->header((string) config('swiss_ebill.mtls_verify_header'))) === 'SUCCESS' && hash_equals($pinned, $given);
    }

    private function fingerprint(string $value): string
    {
        return strtolower((string) preg_replace('/[^0-9a-fA-F]/', '', $value));
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
