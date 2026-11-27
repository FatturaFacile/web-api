<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Modules\SwissEbill\Domain\Exceptions\EbillException;
use Modules\SwissEbill\Infrastructure\Webhook\WebhookSignatureVerifier;
use Modules\SwissEbill\Models\EbillSetting;

const EBILL_TEST_SECRET = 'a-very-long-webhook-secret-for-tests';

function ebillSetting(string $mode = 'hmac_sha256', ?string $fingerprint = null): EbillSetting
{
    $setting = new EbillSetting;
    $setting->webhook_auth_mode = $mode;
    $setting->webhook_secret = EBILL_TEST_SECRET;
    $setting->webhook_mtls_fingerprint = $fingerprint;

    return $setting;
}

/**
 * @param  array<string, string>  $headers
 */
function ebillRequest(string $body, array $headers = []): Request
{
    $server = ['CONTENT_TYPE' => 'application/json'];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return Request::create('/api/v1/webhooks/swiss-ebill/slug', 'POST', [], [], [], $server, $body);
}

function ebillB64(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function ebillRejects(EbillSetting $setting, Request $request): void
{
    try {
        (new WebhookSignatureVerifier)->verify($setting, $request);
    } catch (EbillException $e) {
        expect($e->errorCode())->toBe('invalid_signature')->and($e->httpStatus())->toBe(401);

        return;
    }

    throw new RuntimeException('The signature should have been refused.');
}

test('a valid HMAC-SHA256 signature of the body is accepted', function (): void {
    $body = '{"type":"delivered"}';
    $request = ebillRequest($body, ['X-Ebill-Signature' => 'sha256='.hash_hmac('sha256', $body, EBILL_TEST_SECRET)]);

    (new WebhookSignatureVerifier)->verify(ebillSetting(), $request);

    expect(true)->toBeTrue();
});

test('a signature over the timestamp and the body is accepted inside the tolerance', function (): void {
    $body = '{"type":"delivered"}';
    $timestamp = (string) time();
    $request = ebillRequest($body, ['X-Ebill-Timestamp' => $timestamp, 'X-Ebill-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, EBILL_TEST_SECRET)]);

    (new WebhookSignatureVerifier)->verify(ebillSetting(), $request);

    expect(true)->toBeTrue();
});

test('a replayed request with an old timestamp is refused', function (): void {
    $body = '{"type":"delivered"}';
    $timestamp = (string) (time() - 3600);

    ebillRejects(ebillSetting(), ebillRequest($body, ['X-Ebill-Timestamp' => $timestamp, 'X-Ebill-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, EBILL_TEST_SECRET)]));
});

test('a tampered body, a wrong secret, a missing header or a missing secret is refused', function (): void {
    $signature = hash_hmac('sha256', '{"type":"delivered"}', EBILL_TEST_SECRET);

    ebillRejects(ebillSetting(), ebillRequest('{"type":"rejected"}', ['X-Ebill-Signature' => $signature]));
    ebillRejects(ebillSetting(), ebillRequest('{"type":"delivered"}', ['X-Ebill-Signature' => hash_hmac('sha256', '{"type":"delivered"}', 'other')]));
    ebillRejects(ebillSetting(), ebillRequest('{"type":"delivered"}'));

    $noSecret = ebillSetting();
    $noSecret->webhook_secret = null;
    ebillRejects($noSecret, ebillRequest('{"type":"delivered"}', ['X-Ebill-Signature' => hash_hmac('sha256', '{"type":"delivered"}', '')]));
});

test('a JWS HS256 with the body as payload is accepted, attached or detached', function (): void {
    $body = '{"type":"approved"}';
    $header = ebillB64('{"alg":"HS256","typ":"JWT"}');
    $payload = ebillB64($body);
    $signature = ebillB64(hash_hmac('sha256', $header.'.'.$payload, EBILL_TEST_SECRET, true));

    (new WebhookSignatureVerifier)->verify(ebillSetting('jws_hs256'), ebillRequest($body, ['X-Ebill-Jws' => "{$header}.{$payload}.{$signature}"]));
    (new WebhookSignatureVerifier)->verify(ebillSetting('jws_hs256'), ebillRequest($body, ['Authorization' => "Bearer {$header}..{$signature}"]));

    expect(true)->toBeTrue();
});

test('a JWS with the none algorithm, another body or a wrong key is refused', function (): void {
    $body = '{"type":"approved"}';
    $none = ebillB64('{"alg":"none"}');
    $hs = ebillB64('{"alg":"HS256"}');
    $payload = ebillB64($body);

    ebillRejects(ebillSetting('jws_hs256'), ebillRequest($body, ['X-Ebill-Jws' => "{$none}.{$payload}."]));
    ebillRejects(ebillSetting('jws_hs256'), ebillRequest('{"type":"rejected"}', ['X-Ebill-Jws' => "{$hs}.{$payload}.".ebillB64(hash_hmac('sha256', "{$hs}.{$payload}", EBILL_TEST_SECRET, true))]));
    ebillRejects(ebillSetting('jws_hs256'), ebillRequest($body, ['X-Ebill-Jws' => "{$hs}.{$payload}.".ebillB64(hash_hmac('sha256', "{$hs}.{$payload}", 'wrong', true))]));
});

test('mutual TLS needs the verified handshake and the pinned fingerprint', function (): void {
    $setting = ebillSetting('mutual_tls', 'AB:CD:EF:01');
    $verifier = new WebhookSignatureVerifier;

    $verifier->verify($setting, ebillRequest('{}', ['X-SSL-Client-Verify' => 'SUCCESS', 'X-SSL-Client-Fingerprint' => 'abcdef01']));

    ebillRejects($setting, ebillRequest('{}', ['X-SSL-Client-Verify' => 'FAILED', 'X-SSL-Client-Fingerprint' => 'abcdef01']));
    ebillRejects($setting, ebillRequest('{}', ['X-SSL-Client-Verify' => 'SUCCESS', 'X-SSL-Client-Fingerprint' => '00000000']));
    ebillRejects($setting, ebillRequest('{}', ['X-SSL-Client-Verify' => 'SUCCESS']));
    ebillRejects(ebillSetting('mutual_tls', null), ebillRequest('{}', ['X-SSL-Client-Verify' => 'SUCCESS', 'X-SSL-Client-Fingerprint' => 'abcdef01']));
});
