<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\SwissEbill\Application\Dto\GatewaySubmission;
use Modules\SwissEbill\Application\Dto\ParticipantLookupCriteria;
use Modules\SwissEbill\Domain\Enums\EbillChannelType;
use Modules\SwissEbill\Domain\Enums\EbillNotificationType;
use Modules\SwissEbill\Domain\Enums\EbillParticipantStatus;
use Modules\SwissEbill\Domain\Enums\EbillProvider;
use Modules\SwissEbill\Domain\Exceptions\EbillGatewayException;
use Modules\SwissEbill\Infrastructure\Gateways\EbillGatewayFactory;
use Modules\SwissEbill\Infrastructure\Gateways\HttpEbillGateway;
use Modules\SwissEbill\Infrastructure\Gateways\SandboxEbillGateway;
use Modules\SwissEbill\Models\EbillSetting;

require_once __DIR__.'/EbillSupport.php';

uses(RefreshDatabase::class);

const EBILL_HTTP_BASE = 'https://ebill.example.test';

beforeEach(function (): void {
    ebSetUp($this, 'accountant', false);
    Cache::flush();
    $this->setting = ebSettings(['provider' => 'six_paynet_direct', 'api_base_url' => EBILL_HTTP_BASE, 'client_id' => 'client-1', 'client_secret' => 'secret-1']);
    $this->gateway = app(HttpEbillGateway::class);
});

function ebHttpSubmission(string $xml = '<EbillInvoice/>'): GatewaySubmission
{
    return new GatewaySubmission('inv-key-1', ebBillerId(), '41000000000000001', EbillChannelType::B2bEnterprise, 'INV-1', $xml, hash('sha256', $xml));
}

function ebFakeToken(): void
{
    Http::fake([EBILL_HTTP_BASE.'/oauth/token' => Http::response(['access_token' => 'tok-123', 'expires_in' => 600])]);
}

test('the connection test authenticates with client credentials and reports the latency', function (): void {
    Http::fake([
        EBILL_HTTP_BASE.'/oauth/token' => Http::response(['access_token' => 'tok-123', 'expires_in' => 600]),
        EBILL_HTTP_BASE.'/v1/ping' => Http::response(['message' => 'pong', 'environment' => 'production']),
    ]);

    $result = $this->gateway->testConnection($this->setting);

    expect($result->isConnected())->toBeTrue()->and($result->message)->toBe('pong')->and($result->environment)->toBe('production')->and($result->latencyMs)->toBeGreaterThanOrEqual(0);

    Http::assertSent(fn (Request $request): bool => $request->url() === EBILL_HTTP_BASE.'/oauth/token' && $request['grant_type'] === 'client_credentials' && $request['client_id'] === 'client-1' && $request['client_secret'] === 'secret-1');
    Http::assertSent(fn (Request $request): bool => $request->url() === EBILL_HTTP_BASE.'/v1/ping' && $request->hasHeader('Authorization', 'Bearer tok-123'));
});

test('the access token is cached between calls', function (): void {
    Http::fake([
        EBILL_HTTP_BASE.'/oauth/token' => Http::response(['access_token' => 'tok-123', 'expires_in' => 600]),
        EBILL_HTTP_BASE.'/v1/ping' => Http::response(['message' => 'pong']),
    ]);

    $this->gateway->testConnection($this->setting);
    $this->gateway->testConnection($this->setting);

    Http::assertSentCount(3);
});

test('refused credentials are reported, not thrown, by the connection test', function (): void {
    Http::fake([EBILL_HTTP_BASE.'/oauth/token' => Http::response(['error' => 'invalid_client'], 401)]);

    $result = $this->gateway->testConnection($this->setting);

    expect($result->isConnected())->toBeFalse()->and($result->reachable)->toBeTrue()->and($result->authenticated)->toBeFalse()->and($result->message)->toContain('credentials');
});

test('an unreachable gateway is reported as not reachable', function (): void {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    $result = $this->gateway->testConnection($this->setting);

    expect($result->reachable)->toBeFalse()->and($result->authenticated)->toBeFalse();
});

test('the settings endpoint stores a connected verdict of a real gateway test', function (): void {
    Http::fake([
        EBILL_HTTP_BASE.'/oauth/token' => Http::response(['access_token' => 'tok-123', 'expires_in' => 600]),
        EBILL_HTTP_BASE.'/v1/ping' => Http::response(['message' => 'pong']),
    ]);

    $this->postJson('/api/v1/swiss-ebill/settings/test-connection')->assertOk()->assertJsonPath('data.settings.connection_status', 'connected')->assertJsonPath('data.result.message', 'pong');
});

test('the settings endpoint stores a failed verdict when the credentials are refused', function (): void {
    Http::fake([EBILL_HTTP_BASE.'/oauth/token' => Http::response([], 401)]);

    $this->postJson('/api/v1/swiss-ebill/settings/test-connection')->assertOk()->assertJsonPath('data.result.authenticated', false)->assertJsonPath('data.settings.connection_status', 'failed');
});

test('a directory lookup is mapped and a 404 means not enrolled', function (): void {
    Http::fake([
        EBILL_HTTP_BASE.'/oauth/token' => Http::response(['access_token' => 'tok-123', 'expires_in' => 600]),
        EBILL_HTTP_BASE.'/v1/participants*' => Http::sequence()
            ->push(['participant' => ['participantId' => '41000000000000001', 'status' => 'enrolled', 'channelType' => 'B2B', 'name' => 'Muster AG', 'uid' => 'CHE-100.000.012']])
            ->push(['message' => 'not found'], 404),
    ]);

    $found = $this->gateway->lookupParticipant($this->setting, ParticipantLookupCriteria::email('info@muster.example.com'));
    $missing = $this->gateway->lookupParticipant($this->setting, ParticipantLookupCriteria::email('nobody@muster.example.com'));

    expect($found->participantId)->toBe('41000000000000001')->and($found->status)->toBe(EbillParticipantStatus::Enrolled)->and($found->channelType)->toBe(EbillChannelType::B2bEnterprise)->and($missing)->toBeNull();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/v1/participants') && $request['email'] === 'info@muster.example.com');
});

test('the invoice is posted as signed XML with the idempotency key', function (): void {
    $key = @openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

    if ($key === false) {
        $this->markTestSkipped('OpenSSL cannot generate a key on this machine (no openssl.cnf).');
    }

    openssl_pkey_export($key, $pem);
    $public = openssl_pkey_get_details($key)['key'];
    $this->setting = ebSettings(['private_key_pem' => $pem]);

    Http::fake([
        EBILL_HTTP_BASE.'/oauth/token' => Http::response(['access_token' => 'tok-123', 'expires_in' => 600]),
        EBILL_HTTP_BASE.'/v1/invoices' => Http::response(['transactionId' => 'TX-100', 'submittedAt' => '2026-03-05T10:00:00Z'], 201),
    ]);

    $receipt = $this->gateway->submit($this->setting, ebHttpSubmission('<EbillInvoice>x</EbillInvoice>'));

    expect($receipt->sixTransactionId)->toBe('TX-100')->and($receipt->submittedAt->toIso8601String())->toBe('2026-03-05T10:00:00+00:00');

    Http::assertSent(function (Request $request) use ($public): bool {
        if ($request->url() !== EBILL_HTTP_BASE.'/v1/invoices') {
            return false;
        }

        return $request->body() === '<EbillInvoice>x</EbillInvoice>'
            && $request->hasHeader('Idempotency-Key', 'inv-key-1')
            && $request->hasHeader('X-Biller-Id', ebBillerId())
            && $request->hasHeader('X-Recipient-Id', '41000000000000001')
            && $request->hasHeader('X-Payload-Sha256', hash('sha256', '<EbillInvoice>x</EbillInvoice>'))
            && openssl_verify('<EbillInvoice>x</EbillInvoice>', (string) base64_decode($request->header('X-Payload-Signature')[0]), $public, OPENSSL_ALGO_SHA256) === 1;
    });
});

test('a gateway answer without a transaction id is an error', function (): void {
    Http::fake([EBILL_HTTP_BASE.'/oauth/token' => Http::response(['access_token' => 't', 'expires_in' => 600]), EBILL_HTTP_BASE.'/v1/invoices' => Http::response(['ok' => true])]);

    expect(fn () => $this->gateway->submit($this->setting, ebHttpSubmission()))->toThrow(EbillGatewayException::class, 'transaction id');
});

test('HTTP 429 and 5xx are transient, other refusals are permanent', function (int $status, bool $transient): void {
    Http::fake([EBILL_HTTP_BASE.'/oauth/token' => Http::response(['access_token' => 't', 'expires_in' => 600]), EBILL_HTTP_BASE.'/v1/invoices' => Http::response(['code' => 'some_code', 'message' => 'nope'], $status)]);

    try {
        $this->gateway->submit($this->setting, ebHttpSubmission());
    } catch (EbillGatewayException $e) {
        expect($e->isTransient())->toBe($transient)->and($e->gatewayStatus)->toBe($status)->and($e->errorCode())->toBe('some_code')->and($e->getMessage())->toContain('nope');

        return;
    }

    throw new RuntimeException('The refusal should have thrown.');
})->with([[429, true], [500, true], [503, true], [400, false], [403, false], [422, false]]);

test('a network failure is transient', function (): void {
    Http::fake(fn () => throw new ConnectionException('connection reset'));

    try {
        $this->gateway->submit($this->setting, ebHttpSubmission());
    } catch (EbillGatewayException $e) {
        expect($e->isTransient())->toBeTrue()->and($e->errorCode())->toBe('network_error');

        return;
    }

    throw new RuntimeException('The failure should have thrown.');
});

test('an unconfigured gateway address is a permanent error', function (): void {
    $setting = ebSettings(['api_base_url' => null]);
    $setting->forceFill(['api_base_url' => null])->save();

    expect(fn () => $this->gateway->submit($setting, ebHttpSubmission()))->toThrow(EbillGatewayException::class, 'not configured');
});

test('the status poll returns the events in order and skips the ones it does not know', function (): void {
    Http::fake([
        EBILL_HTTP_BASE.'/oauth/token' => Http::response(['access_token' => 't', 'expires_in' => 600]),
        EBILL_HTTP_BASE.'/v1/invoices/TX-100/events' => Http::response(['events' => [
            ['type' => 'approved', 'eventId' => 'e2', 'occurredAt' => '2026-03-05T12:00:00Z', 'scheduledPaymentDate' => '2026-03-20'],
            ['type' => 'delivered', 'eventId' => 'e1', 'occurredAt' => '2026-03-05T11:00:00Z'],
            ['type' => 'teleported', 'eventId' => 'e3', 'occurredAt' => '2026-03-05T13:00:00Z'],
        ]]),
    ]);

    $events = $this->gateway->fetchStatus($this->setting, 'TX-100');

    expect($events)->toHaveCount(2)
        ->and($events[0]->type)->toBe(EbillNotificationType::Delivered)
        ->and($events[1]->type)->toBe(EbillNotificationType::Approved)
        ->and($events[1]->scheduledPaymentDate?->toDateString())->toBe('2026-03-20')
        ->and($events[1]->sixTransactionId)->toBe('TX-100');
});

test('an invoice is recalled with a POST to its recall address', function (): void {
    Http::fake([EBILL_HTTP_BASE.'/oauth/token' => Http::response(['access_token' => 't', 'expires_in' => 600]), EBILL_HTTP_BASE.'/v1/invoices/TX-100/recall' => Http::response([], 204)]);

    $this->gateway->recall($this->setting, 'TX-100');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === EBILL_HTTP_BASE.'/v1/invoices/TX-100/recall');
});

/**
 * @param  string  $operation  e.g. SubmitInvoiceResponse
 */
function ebSoap(string $operation, string $inner): string
{
    return '<?xml version="1.0" encoding="UTF-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:eb="urn:swiss-ebill:gateway:1"><soap:Body>'
        .'<eb:'.$operation.'>'.$inner.'</eb:'.$operation.'></soap:Body></soap:Envelope>';
}

test('the SOAP channel wraps the invoice in an envelope and reads the transaction id', function (): void {
    $setting = ebSettings(['channel_protocol' => 'soap_ebinterface']);
    Http::fake([EBILL_HTTP_BASE.'/soap' => Http::response(ebSoap('SubmitInvoiceResponse', '<eb:transactionId>TX-S1</eb:transactionId><eb:submittedAt>2026-03-05T10:00:00Z</eb:submittedAt>'), 200, ['Content-Type' => 'text/xml'])]);

    $receipt = $this->gateway->submit($setting, ebHttpSubmission('<EbillInvoice>soap</EbillInvoice>'));

    expect($receipt->sixTransactionId)->toBe('TX-S1');

    Http::assertSent(fn (Request $request): bool => $request->url() === EBILL_HTTP_BASE.'/soap'
        && $request->hasHeader('SOAPAction', 'SubmitInvoice')
        && str_contains($request->body(), '<eb:SubmitInvoice>')
        && str_contains($request->body(), '<Invoice encoding="base64">'.base64_encode('<EbillInvoice>soap</EbillInvoice>').'</Invoice>')
        && str_contains($request->body(), '<eb:ClientId>client-1</eb:ClientId>'));
});

test('the SOAP channel reads one event or a list of events', function (): void {
    $setting = ebSettings(['channel_protocol' => 'soap_ebinterface']);
    $event = fn (string $type, string $id, string $at): string => '<eb:event><eb:type>'.$type.'</eb:type><eb:eventId>'.$id.'</eb:eventId><eb:occurredAt>'.$at.'</eb:occurredAt></eb:event>';

    Http::fake([EBILL_HTTP_BASE.'/soap' => Http::sequence()
        ->push(ebSoap('GetInvoiceEventsResponse', '<eb:events>'.$event('delivered', 'e1', '2026-03-05T11:00:00Z').'</eb:events>'))
        ->push(ebSoap('GetInvoiceEventsResponse', '<eb:events>'.$event('delivered', 'e1', '2026-03-05T11:00:00Z').$event('rejected', 'e2', '2026-03-05T12:00:00Z').'</eb:events>')),
    ]);

    expect($this->gateway->fetchStatus($setting, 'TX-S1'))->toHaveCount(1)
        ->and($this->gateway->fetchStatus($setting, 'TX-S1'))->toHaveCount(2);
});

test('the SOAP channel reads a participant and an unreadable answer is an error', function (): void {
    $setting = ebSettings(['channel_protocol' => 'soap_ebinterface']);
    Http::fake([EBILL_HTTP_BASE.'/soap' => Http::sequence()
        ->push(ebSoap('FindParticipantResponse', '<eb:participantId>41000000000000001</eb:participantId><eb:status>enrolled</eb:status><eb:channelType>B2C</eb:channelType>'))
        ->push('this is not xml'),
    ]);

    $participant = $this->gateway->lookupParticipant($setting, ParticipantLookupCriteria::iban('CH9300762011623852957'));

    expect($participant->participantId)->toBe('41000000000000001')->and($participant->channelType)->toBe(EbillChannelType::B2cPrivate)
        ->and(fn () => $this->gateway->lookupParticipant($setting, ParticipantLookupCriteria::iban('CH9300762011623852957')))->toThrow(EbillGatewayException::class, 'unreadable');
});

test('the provider decides between the simulator and the HTTP client', function (): void {
    $factory = app(EbillGatewayFactory::class);
    $sandbox = new EbillSetting;
    $sandbox->provider = EbillProvider::SandboxSimulator;
    $real = new EbillSetting;
    $real->provider = EbillProvider::PostfinanceEbill;

    expect($factory->for($sandbox))->toBeInstanceOf(SandboxEbillGateway::class)->and($factory->for($real))->toBeInstanceOf(HttpEbillGateway::class);
});
