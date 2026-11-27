<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\SwissEbill\Models\EbillSetting;
use Modules\SwissEbill\Models\EbillTransmission;
use Modules\SwissEbill\Models\EbillWebhookEvent;

require_once __DIR__.'/EbillSupport.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    ebSetUp($this);
    $this->invoice = ebInvoice(ebCustomer());
    $this->transmission = ebSend($this, $this->invoice);
    $this->txn = $this->transmission->six_transaction_id;
});

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function ebEvent(string $type, string $id, string $txn, array $extra = []): array
{
    return array_merge(['type' => $type, 'event_id' => $id, 'transaction_id' => $txn, 'occurred_at' => now()->toIso8601String()], $extra);
}

/**
 * @param  array<string, string>  $server
 */
function ebRawWebhook(object $test, string $slug, string $body, array $server = []): TestResponse
{
    return $test->call('POST', '/api/v1/webhooks/swiss-ebill/'.$slug, [], [], [], array_merge(['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $server), $body);
}

function ebJwsB64(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

test('a signed delivery receipt moves the transmission to the e-banking', function (): void {
    ebWebhook($this, $this->setting->refresh(), ebEvent('delivered', 'ev-1', $this->txn))->assertOk()
        ->assertJsonPath('data.events.0.event_id', 'ev-1')->assertJsonPath('data.events.0.outcome', 'applied');

    expect($this->transmission->refresh()->status->value)->toBe('delivered_to_ebanking')
        ->and($this->transmission->delivered_at)->not->toBeNull()
        ->and($this->invoice->refresh()->ebill_status)->toBe('delivered_to_ebanking');
});

test('a debtor approval stores the scheduled payment date', function (): void {
    ebWebhook($this, $this->setting->refresh(), ebEvent('approved', 'ev-2', $this->txn, ['scheduled_payment_date' => '2026-03-25']))->assertOk();

    expect($this->transmission->refresh()->status->value)->toBe('approved_by_debtor')
        ->and($this->transmission->scheduled_payment_date?->toDateString())->toBe('2026-03-25')
        ->and($this->invoice->refresh()->ebill_status)->toBe('approved_by_debtor');
});

test('a debtor rejection stores the bank code and the note, and accounting is notified', function (): void {
    ebWebhook($this, $this->setting->refresh(), ebEvent('rejected', 'ev-3', $this->txn, ['rejection_code' => 'PO_REFERENCE_MISSING', 'rejection_reason' => 'Please add our PO 4711']))->assertOk();

    $this->transmission->refresh();

    expect($this->transmission->status->value)->toBe('rejected_by_debtor')
        ->and($this->transmission->rejection_code)->toBe('PO_REFERENCE_MISSING')
        ->and($this->transmission->rejection_reason)->toBe('Please add our PO 4711')
        ->and($this->actor->notifications()->count())->toBe(1)
        ->and($this->actor->notifications()->first()->data['rejection_reason'])->toBe('Please add our PO 4711');
});

test('an unknown rejection code is kept as other', function (): void {
    ebWebhook($this, $this->setting->refresh(), ebEvent('rejected', 'ev-4', $this->txn, ['rejection_code' => 'BANK_SPECIFIC_77', 'rejection_reason' => 'x']))->assertOk();

    expect($this->transmission->refresh()->rejection_code)->toBe('OTHER');
});

test('a settlement confirmation closes the eBill as paid', function (): void {
    ebWebhook($this, $this->setting->refresh(), ebEvent('settled', 'ev-5', $this->txn, ['settlement_reference' => 'SETTLE-9']))->assertOk();

    $this->transmission->refresh();

    expect($this->transmission->status->value)->toBe('paid_reconciled')->and($this->transmission->camt054_entry_id)->toBe('SETTLE-9')->and($this->transmission->paid_at)->not->toBeNull();
});

test('a technical failure after the acceptance is recorded and can be retried', function (): void {
    ebWebhook($this, $this->setting->refresh(), ebEvent('failed', 'ev-6', $this->txn, ['error_code' => 'SCHEMA_REJECTED', 'error_details' => 'Line 3 invalid']))->assertOk();

    $this->transmission->refresh();

    expect($this->transmission->status->value)->toBe('failed_technical')
        ->and($this->transmission->error_code)->toBe('SCHEMA_REJECTED')
        ->and($this->transmission->error_details)->toBe('Line 3 invalid');
});

test('a replayed event is acknowledged and changes nothing', function (): void {
    $payload = ebEvent('delivered', 'ev-7', $this->txn);
    ebWebhook($this, $this->setting->refresh(), $payload)->assertOk()->assertJsonPath('data.events.0.outcome', 'applied');
    $events = $this->transmission->events()->count();

    ebWebhook($this, $this->setting, $payload)->assertOk()->assertJsonPath('data.events.0.outcome', 'duplicate');
    ebWebhook($this, $this->setting, ebEvent('delivered', 'ev-7', $this->txn, ['occurred_at' => now()->addHour()->toIso8601String()]))->assertOk()->assertJsonPath('data.events.0.outcome', 'duplicate');

    expect($this->transmission->events()->count())->toBe($events)->and(EbillWebhookEvent::query()->where('webhook_event_id', 'ev-7')->count())->toBe(1);
});

test('a replayed rejection does not notify twice', function (): void {
    $payload = ebEvent('rejected', 'ev-8', $this->txn, ['rejection_code' => 'DUPLICATE']);

    ebWebhook($this, $this->setting->refresh(), $payload)->assertOk();
    ebWebhook($this, $this->setting, $payload)->assertOk();
    ebWebhook($this, $this->setting, $payload)->assertOk();

    expect($this->actor->notifications()->count())->toBe(1);
});

test('a late delivery receipt after the approval is ignored', function (): void {
    ebWebhook($this, $this->setting->refresh(), ebEvent('approved', 'ev-9', $this->txn, ['scheduled_payment_date' => '2026-03-25']))->assertOk();
    ebWebhook($this, $this->setting, ebEvent('delivered', 'ev-10', $this->txn))->assertOk()->assertJsonPath('data.events.0.outcome', 'ignored_out_of_order');

    expect($this->transmission->refresh()->status->value)->toBe('approved_by_debtor')->and($this->transmission->events()->pluck('event_type')->all())->not->toContain('delivered_to_ebanking');
});

test('another event of the state the transmission is already in is acknowledged', function (): void {
    ebWebhook($this, $this->setting->refresh(), ebEvent('delivered', 'ev-11', $this->txn))->assertOk();
    ebWebhook($this, $this->setting, ebEvent('delivered', 'ev-12', $this->txn))->assertOk()->assertJsonPath('data.events.0.outcome', 'duplicate_state');

    expect($this->transmission->events()->where('event_type', 'delivered_to_ebanking')->count())->toBe(1);
});

test('a list of events is applied in order', function (): void {
    ebWebhook($this, $this->setting->refresh(), ['events' => [
        ebEvent('delivered', 'ev-13', $this->txn),
        ebEvent('approved', 'ev-14', $this->txn, ['scheduled_payment_date' => '2026-03-26']),
    ]])->assertOk()->assertJsonCount(2, 'data.events');

    expect($this->transmission->refresh()->status->value)->toBe('approved_by_debtor');
});

test('a bad signature is refused before anything is read', function (): void {
    ebWebhook($this, $this->setting->refresh(), ebEvent('rejected', 'ev-15', $this->txn), 'deadbeef')->assertStatus(401)->assertJsonPath('code', 'invalid_signature');

    expect($this->transmission->refresh()->status->value)->toBe('transmitted')->and(EbillWebhookEvent::query()->count())->toBe(0)->and($this->actor->notifications()->count())->toBe(0);
});

test('a missing signature header is refused', function (): void {
    ebRawWebhook($this, $this->setting->refresh()->webhook_slug, (string) json_encode(ebEvent('delivered', 'ev-16', $this->txn)))->assertStatus(401);
});

test('a captured request replayed an hour later is refused', function (): void {
    $setting = $this->setting->refresh();
    $body = (string) json_encode(ebEvent('delivered', 'ev-17', $this->txn));
    $stale = (string) (time() - 3600);

    ebRawWebhook($this, $setting->webhook_slug, $body, ['HTTP_X_EBILL_TIMESTAMP' => $stale, 'HTTP_X_EBILL_SIGNATURE' => hash_hmac('sha256', $stale.'.'.$body, (string) $setting->webhook_secret)])->assertStatus(401);
    expect($this->transmission->refresh()->status->value)->toBe('transmitted');
});

test('the secret of another company does not open this endpoint', function (): void {
    $setting = $this->setting->refresh();
    $body = (string) json_encode(ebEvent('delivered', 'ev-18', $this->txn));
    $timestamp = (string) time();

    ebRawWebhook($this, $setting->webhook_slug, $body, ['HTTP_X_EBILL_TIMESTAMP' => $timestamp, 'HTTP_X_EBILL_SIGNATURE' => hash_hmac('sha256', $timestamp.'.'.$body, 'the-secret-of-somebody-else')])->assertStatus(401);
});

test('an unknown slug and a disabled company answer the same 404', function (): void {
    ebWebhook($this, $this->setting->refresh(), ebEvent('delivered', 'ev-19', $this->txn))->assertOk();
    ebRawWebhook($this, str_repeat('a', 40), '{}')->assertStatus(404)->assertJsonPath('code', 'unknown_webhook');

    ebSettings(['ebill_enabled' => false]);
    ebWebhook($this, $this->setting->refresh(), ebEvent('approved', 'ev-20', $this->txn))->assertStatus(404)->assertJsonPath('code', 'unknown_webhook');
});

test('an event for a transaction we do not know leaves no trace so the gateway delivers it again', function (): void {
    ebWebhook($this, $this->setting->refresh(), ebEvent('approved', 'ev-21', 'SBX-NOPE'))->assertStatus(404)->assertJsonPath('code', 'unknown_transaction');

    expect(EbillWebhookEvent::query()->where('webhook_event_id', 'ev-21')->exists())->toBeFalse();
});

test('an impossible move answers 409 and is not recorded either', function (): void {
    ebWebhook($this, $this->setting->refresh(), ebEvent('failed', 'ev-22a', $this->txn, ['error_code' => 'SCHEMA_REJECTED']))->assertOk();

    ebWebhook($this, $this->setting, ebEvent('delivered', 'ev-22', $this->txn))->assertStatus(409)->assertJsonPath('code', 'invalid_transition');

    expect(EbillWebhookEvent::query()->where('webhook_event_id', 'ev-22')->exists())->toBeFalse()->and($this->transmission->refresh()->status->value)->toBe('failed_technical');
});

test('a notification older than a final state is ignored', function (): void {
    $this->postJson('/api/v1/swiss-ebill/transmissions/'.$this->transmission->id.'/cancel')->assertOk();

    ebWebhook($this, $this->setting->refresh(), ebEvent('delivered', 'ev-22b', $this->txn))->assertOk()->assertJsonPath('data.events.0.outcome', 'ignored_out_of_order');

    expect($this->transmission->refresh()->status->value)->toBe('cancelled');
});

test('an unreadable body is refused after the signature check', function (): void {
    ebWebhook($this, $this->setting->refresh(), ['type' => 'teleported', 'event_id' => 'ev-23', 'transaction_id' => $this->txn])->assertStatus(422)->assertJsonPath('code', 'payload_unreadable');
    ebWebhook($this, $this->setting, ['hello' => 'world'])->assertStatus(422);
});

test('a JWS signed webhook is accepted in JWS mode and refused with the wrong key', function (): void {
    ebSettings(['webhook_auth_mode' => 'jws_hs256']);
    $setting = EbillSetting::query()->first();
    $body = (string) json_encode(ebEvent('delivered', 'ev-24', $this->txn));
    $header = ebJwsB64('{"alg":"HS256","typ":"JWT"}');
    $sign = fn (string $key): string => ebJwsB64(hash_hmac('sha256', $header.'.'.ebJwsB64($body), $key, true));

    ebRawWebhook($this, $setting->webhook_slug, $body, ['HTTP_X_EBILL_JWS' => $header.'.'.ebJwsB64($body).'.'.$sign('wrong-key-of-no-use')])->assertStatus(401);
    ebRawWebhook($this, $setting->webhook_slug, $body, ['HTTP_X_EBILL_JWS' => $header.'..'.$sign((string) $setting->webhook_secret)])->assertOk();

    expect($this->transmission->refresh()->status->value)->toBe('delivered_to_ebanking');
});

test('mutual TLS mode needs the verified handshake and the pinned certificate', function (): void {
    ebSettings(['webhook_auth_mode' => 'mutual_tls', 'webhook_mtls_fingerprint' => 'AA:BB:CC:DD']);
    $setting = EbillSetting::query()->first();
    $body = (string) json_encode(ebEvent('delivered', 'ev-25', $this->txn));

    ebRawWebhook($this, $setting->webhook_slug, $body)->assertStatus(401);
    ebRawWebhook($this, $setting->webhook_slug, $body, ['HTTP_X_SSL_CLIENT_VERIFY' => 'SUCCESS', 'HTTP_X_SSL_CLIENT_FINGERPRINT' => '11:22:33:44'])->assertStatus(401);
    ebRawWebhook($this, $setting->webhook_slug, $body, ['HTTP_X_SSL_CLIENT_VERIFY' => 'SUCCESS', 'HTTP_X_SSL_CLIENT_FINGERPRINT' => 'aabbccdd'])->assertOk();

    expect($this->transmission->refresh()->status->value)->toBe('delivered_to_ebanking');
});

test('the webhook of one company cannot touch the transmission of another', function (): void {
    app(TenantContext::class)->setTenant($this->otherTenant);
    $other = ebSettings();
    app(TenantContext::class)->setTenant($this->tenant);

    ebWebhook($this, $other->refresh(), ebEvent('delivered', 'ev-26', $this->txn))->assertStatus(404);

    expect($this->transmission->refresh()->status->value)->toBe('transmitted');
});

test('a webhook processed through the endpoint is stamped with the company and the gateway source', function (): void {
    ebWebhook($this, $this->setting->refresh(), ebEvent('delivered', 'ev-27', $this->txn))->assertOk();

    $ledger = EbillWebhookEvent::query()->where('webhook_event_id', 'ev-27')->firstOrFail();
    $event = $this->transmission->events()->where('event_type', 'delivered_to_ebanking')->firstOrFail();

    expect($ledger->tenant_id)->toBe($this->tenant->id)
        ->and($ledger->transmission_id)->toBe($this->transmission->id)
        ->and($ledger->outcome)->toBe('applied')
        ->and($ledger->payload_hash)->toHaveLength(64)
        ->and($event->source)->toBe('gateway_webhook')
        ->and($event->gateway_event_id)->toBe('ev-27')
        ->and(EbillTransmission::query()->count())->toBe(1);
});
