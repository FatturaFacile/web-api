<?php

declare(strict_types=1);

use App\Contracts\Security\HostResolver;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\DocumentTrust\Exceptions\DocumentTrustException;
use Modules\DocumentTrust\Models\PreservationPackage;
use Modules\DocumentTrust\Support\Rfc3161Codec;
use phpseclib3\File\ASN1;
use phpseclib3\Math\BigInteger;
use Tests\Support\StaticHostResolver;

require_once __DIR__.'/PreservationExtraHelpers.php';

uses(RefreshDatabase::class);

const TSA_URL = 'https://tsa.example.org/tsr';

function tsaDer(int $tag, string $content): string
{
    $length = strlen($content);
    $encoded = $length < 128 ? chr($length) : (function () use ($length): string {
        $bytes = ltrim(pack('N', $length), "\0");

        return chr(0x80 | strlen($bytes)).$bytes;
    })();

    return chr($tag).$encoded.$content;
}

function tsaOid(string $oid): string
{
    return (string) ASN1::encodeDER($oid, ['type' => ASN1::TYPE_OBJECT_IDENTIFIER]);
}

function tsaInt(int|BigInteger $value): string
{
    return (string) ASN1::encodeDER($value instanceof BigInteger ? $value : new BigInteger($value), ['type' => ASN1::TYPE_INTEGER]);
}

/**
 * A self-signed TSA certificate and its key, generated for the test.
 *
 * @return array{key: OpenSSLAsymmetricKey, der: string}
 */
function tsaIdentity(): array
{
    $config = tempnam(sys_get_temp_dir(), 'cnf');
    file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n");
    $options = ['config' => $config, 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];
    $key = openssl_pkey_new($options);
    $certificate = openssl_csr_sign(openssl_csr_new(['commonName' => 'Test TSA'], $key, $options), null, $key, 30, $options, 4242);
    openssl_x509_export($certificate, $pem);
    unlink($config);

    return ['key' => $key, 'der' => base64_decode(preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem))];
}

/**
 * The TimeStampResp a TSA would return for a request, signed with the test identity. Options alter one thing each:
 * nonce, imprint (hex), offset (seconds of the generation time), status, certificate (false drops it), tamper.
 *
 * @param  array<string, mixed>  $options
 */
function tsaResponse(string $requestDer, array $identity, array $options = []): string
{
    $request = ASN1::decodeBER($requestDer)[0]['content'];
    $imprint = $request[1]['content'][1]['content'];
    $nonce = $request[2]['content'];

    if (($options['status'] ?? 0) !== 0) {
        return tsaDer(0x30, tsaDer(0x30, tsaInt((int) $options['status']).tsaDer(0x30, tsaDer(0x0C, 'rejected'))));
    }

    $imprint = isset($options['imprint']) ? (string) hex2bin($options['imprint']) : $imprint;
    $nonce = $options['nonce'] ?? $nonce;
    $sha256 = tsaDer(0x30, tsaOid(Rfc3161Codec::OidSha256).tsaDer(0x05, ''));
    $generated = gmdate('YmdHis', time() + (int) ($options['offset'] ?? 0)).'Z';
    $tst = tsaDer(0x30, tsaInt(1).tsaOid('1.2.3.4.1').tsaDer(0x30, $sha256.tsaDer(0x04, $imprint)).tsaInt(77).tsaDer(0x18, $generated).tsaInt($nonce));

    $attributes = tsaDer(0x30, tsaOid('1.2.840.113549.1.9.3').tsaDer(0x31, tsaOid('1.2.840.113549.1.9.16.1.4')))
        .tsaDer(0x30, tsaOid('1.2.840.113549.1.9.4').tsaDer(0x31, tsaDer(0x04, hash('sha256', $tst, true))));
    openssl_sign(tsaDer(0x31, $attributes), $signature, $identity['key'], OPENSSL_ALGO_SHA256);

    if ($options['tamper'] ?? false) {
        $signature[0] = $signature[0] ^ "\x01";
    }

    $certificate = ASN1::decodeBER($identity['der'])[0]['content'][0]['content'];
    $issuer = substr($identity['der'], $certificate[3]['start'], $certificate[3]['length']);
    $signer = tsaDer(0x30, tsaInt(1).tsaDer(0x30, $issuer.tsaInt($certificate[1]['content'])).$sha256.tsaDer(0xA0, $attributes).tsaDer(0x30, tsaOid('1.2.840.113549.1.1.1').tsaDer(0x05, '')).tsaDer(0x04, $signature));
    $signedData = tsaDer(0x30, tsaInt(3).tsaDer(0x31, $sha256).tsaDer(0x30, tsaOid('1.2.840.113549.1.9.16.1.4').tsaDer(0xA0, tsaDer(0x04, $tst))).(($options['certificate'] ?? true) ? tsaDer(0xA0, $identity['der']) : '').tsaDer(0x31, $signer));
    $token = tsaDer(0x30, tsaOid('1.2.840.113549.1.7.2').tsaDer(0xA0, $signedData));

    return tsaDer(0x30, tsaDer(0x30, tsaInt(0)).$token);
}

/**
 * Fakes the TSA: every request is answered by tsaResponse() with the options.
 *
 * @param  array<string, mixed>  $options
 */
function tsaFake(array $identity, array $options = [], int $httpStatus = 200): void
{
    Http::fake([TSA_URL => fn (Request $request) => Http::response(tsaResponse($request->body(), $identity, $options), $httpStatus, ['Content-Type' => 'application/timestamp-reply'])]);
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    app()->instance(HostResolver::class, new StaticHostResolver);
    $this->tenant = complianceTenant();
    $this->admin = actingAsMember($this->tenant, 'owner');
    $this->identity = tsaIdentity();
});

afterEach(fn () => app(TenantContext::class)->clear());

test('the request has the SHA-256 imprint, a nonce and certReq', function (): void {
    $request = ASN1::decodeBER(Rfc3161Codec::buildRequest(hash('sha256', 'x', true), new BigInteger(123456789), true))[0]['content'];

    expect((int) $request[0]['content']->toString())->toBe(1)
        ->and(ASN1::getOID((string) $request[1]['content'][0]['content'][0]['content']))->toBe(Rfc3161Codec::OidSha256)
        ->and($request[1]['content'][1]['content'])->toBe(hash('sha256', 'x', true))
        ->and($request[2]['content']->toString())->toBe('123456789')
        ->and($request[3]['content'])->toBeTrue();
});

test('a package is time-stamped by the configured authority and the token is stored for validation', function (): void {
    tsaFake($this->identity);
    $username = 'tsa-'.Str::lower(Str::random(6));
    $secret = Str::random(20);
    $saved = $this->putJson(PE_BASE.'/preservation-settings', ['timestamp' => ['tsa_url' => TSA_URL, 'tsa_username' => $username, 'tsa_password' => $secret, 'tsa_provider_name' => 'Test TSA']])->assertOk()->json('data.timestamp');

    expect($saved)->toBe(['tsa_url' => TSA_URL, 'tsa_username_set' => true, 'tsa_password_set' => true, 'tsa_provider_name' => 'Test TSA', 'tsa_declared_qualified' => false])
        ->and(json_encode($this->getJson(PE_BASE.'/preservation-settings')->json()))->not->toContain($secret)->not->toContain($username)
        ->and(DB::table('preservation_settings')->value('tsa_password'))->not->toContain($secret);

    ['package' => $package] = peClosedPackage($this->tenant);
    $closed = PreservationPackage::findOrFail($package);

    expect($closed->timestamp_provider)->toBe('Test TSA')
        ->and($closed->timestamp_qualified)->toBeFalse()
        ->and($closed->timestamped_at->diffInSeconds(now(), true))->toBeLessThan(5)
        ->and(base64_decode((string) $closed->timestamp_token, true))->toStartWith("\x30");

    Http::assertSent(fn (Request $request): bool => $request->url() === TSA_URL
        && $request->hasHeader('Content-Type', 'application/timestamp-query')
        && $request->header('Authorization')[0] === 'Basic '.base64_encode("{$username}:{$secret}"));
});

test('the qualified flag is the tenant declaration, never assumed', function (): void {
    tsaFake($this->identity);
    $this->putJson(PE_BASE.'/preservation-settings', ['timestamp' => ['tsa_url' => TSA_URL, 'tsa_declared_qualified' => true]])->assertOk();

    ['package' => $package] = peClosedPackage($this->tenant);

    expect(PreservationPackage::findOrFail($package)->timestamp_qualified)->toBeTrue();
});

test('every invalid answer keeps the package open and is never replaced by the local clock', function (string $case, array $options, int $status): void {
    tsaFake($this->identity, $options, $status);
    $this->putJson(PE_BASE.'/preservation-settings', ['timestamp' => ['tsa_url' => TSA_URL]])->assertOk();
    $package = peOpenPackage();
    $this->postJson(PE_BASE."/preservation-packages/{$package}/items", ['source_id' => peInvoice($this->tenant)->id])->assertCreated();

    $this->postJson(PE_BASE."/preservation-packages/{$package}/close")->assertUnprocessable();

    expect(PreservationPackage::findOrFail($package))->status->toBe('open')->timestamp_provider->toBeNull();
})->with([
    'wrong nonce' => ['nonce', ['nonce' => 99], 200],
    'wrong imprint' => ['imprint', ['imprint' => str_repeat('ab', 32)], 200],
    'tampered signature' => ['signature', ['tamper' => true], 200],
    'no certificate' => ['certificate', ['certificate' => false], 200],
    'rejected status' => ['status', ['status' => 2], 200],
    'stale generation time' => ['time', ['offset' => -86400], 200],
    'server error' => ['http', [], 500],
]);

test('the codec rejects garbage and reports the reason', function (): void {
    $digest = hash('sha256', 'x', true);

    expect(fn () => Rfc3161Codec::parseResponse('not asn1', $digest, new BigInteger(1), 'x', false))->toThrow(DocumentTrustException::class);

    $request = Rfc3161Codec::buildRequest($digest, new BigInteger(5));
    $good = tsaResponse($request, $this->identity);
    $token = Rfc3161Codec::parseResponse($good, $digest, new BigInteger(5), 'Test TSA', false);

    expect($token->provider)->toBe('Test TSA')->and($token->qualified)->toBeFalse()->and($token->issuedAt->getTimestamp())->toBeGreaterThan(time() - 5)
        ->and(fn () => Rfc3161Codec::parseResponse($good, $digest, new BigInteger(6), 'x', false))->toThrow(DocumentTrustException::class, 'nonce')
        ->and(fn () => Rfc3161Codec::parseResponse($good, hash('sha256', 'y', true), new BigInteger(5), 'x', false))->toThrow(DocumentTrustException::class, 'does not match');
});

test('the authority must be an https public address, and a private one is never called', function (): void {
    $this->putJson(PE_BASE.'/preservation-settings', ['timestamp' => ['tsa_url' => 'http://tsa.example.org/tsr']])->assertUnprocessable();
    $this->putJson(PE_BASE.'/preservation-settings', ['timestamp' => ['tsa_url' => 'https://127.0.0.1/tsr']])->assertUnprocessable();
    $this->putJson(PE_BASE.'/preservation-settings', ['timestamp' => ['tsa_url' => 'https://tsa.example.org/tsr']])->assertOk();

    app()->instance(HostResolver::class, new StaticHostResolver(['tsa.example.org' => ['10.0.0.9']]));
    $package = peOpenPackage();
    $this->postJson(PE_BASE."/preservation-packages/{$package}/items", ['source_id' => peInvoice($this->tenant)->id])->assertCreated();

    $this->postJson(PE_BASE."/preservation-packages/{$package}/close")->assertUnprocessable();
    Http::assertNothingSent();
});

test('removing the authority clears its credentials, and only owners and administrators configure it', function (): void {
    $this->putJson(PE_BASE.'/preservation-settings', ['timestamp' => ['tsa_url' => TSA_URL, 'tsa_username' => 'u', 'tsa_password' => 'p', 'tsa_declared_qualified' => true]])->assertOk();
    $this->putJson(PE_BASE.'/preservation-settings', ['timestamp' => ['tsa_url' => null]])->assertOk()
        ->assertJsonPath('data.timestamp', ['tsa_url' => null, 'tsa_username_set' => false, 'tsa_password_set' => false, 'tsa_provider_name' => null, 'tsa_declared_qualified' => false]);

    peActAs($this->tenant, 'accountant');
    $this->putJson(PE_BASE.'/preservation-settings', ['timestamp' => ['tsa_url' => TSA_URL]])->assertForbidden();
});

test('the authority of one tenant is never used for another', function (): void {
    tsaFake($this->identity);
    $this->putJson(PE_BASE.'/preservation-settings', ['timestamp' => ['tsa_url' => TSA_URL, 'tsa_provider_name' => 'Test TSA']])->assertOk();

    $beta = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta Srl', 'vat_number' => '00000000019', 'country' => 'IT', 'default_currency' => 'EUR']);
    peActAs($beta, 'owner');

    expect($this->getJson(PE_BASE.'/preservation-settings')->json('data.timestamp.tsa_url'))->toBeNull();

    ['package' => $package] = peClosedPackage($beta);

    expect(PreservationPackage::findOrFail($package)->timestamp_provider)->toBe('local-clock');
    Http::assertNothingSent();
});
