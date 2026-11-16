<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Communication\Models\CommunicationChannel;
use Modules\Communication\Models\CommunicationConversation;
use Modules\Communication\Models\DeliveryLog;
use Modules\CRM\Models\Consent;
use Modules\CRM\Models\Segment;

require_once __DIR__.'/../Compliance/ComplianceHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = complianceTenant('Hook '.Str::random(4));
    $this->admin = actingAsMember($this->tenant);
    app(TenantContext::class)->setTenant($this->tenant);
    $this->secret = Str::random(40);
    $this->channel = CommunicationChannel::create(['tenant_id' => $this->tenant->id, 'channel' => 'email', 'driver' => 'local', 'is_enabled' => true, 'credentials' => ['inbound_secret' => $this->secret]]);
});

afterEach(fn () => app(TenantContext::class)->clear());

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, string>
 */
function signedHeaders(array $payload, string $secret, ?int $timestamp = null, ?string $signature = null): array
{
    $timestamp ??= time();
    $body = json_encode($payload);

    return ['X-Timestamp' => (string) $timestamp, 'X-Signature' => $signature ?? hash_hmac('sha256', $timestamp.'.'.$body, $secret), 'Content-Type' => 'application/json'];
}

test('a signed inbound email creates a conversation and a second one joins it', function (): void {
    $payload = ['from' => 'Buyer@Example.com', 'from_name' => 'Buyer', 'subject' => 'Order', 'text' => 'Where is it?'];
    $url = '/api/v1/webhooks/communication/email/'.$this->channel->id;

    $this->call('POST', $url, [], [], [], serverFromHeaders(signedHeaders($payload, $this->secret)), json_encode($payload))->assertCreated();
    $second = [...$payload, 'text' => 'Hello?'];
    $this->call('POST', $url, [], [], [], serverFromHeaders(signedHeaders($second, $this->secret)), json_encode($second))->assertCreated();

    expect(CommunicationConversation::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count())->toBe(1);
    $conversation = CommunicationConversation::withoutGlobalScopes()->first();
    expect($conversation->counterparty_address)->toBe('buyer@example.com')->and($conversation->unread_count)->toBe(2);
});

test('a bad signature, a stale timestamp or an unknown channel is refused', function (): void {
    $payload = ['from' => 'buyer@example.com', 'text' => 'Hi'];
    $url = '/api/v1/webhooks/communication/email/'.$this->channel->id;
    $post = fn (string $target, array $headers) => $this->call('POST', $target, [], [], [], serverFromHeaders($headers), json_encode($payload));

    $post($url, signedHeaders($payload, $this->secret.'x'))->assertUnauthorized();
    $post($url, signedHeaders($payload, $this->secret, time() - 3600))->assertUnauthorized();
    $post($url, signedHeaders($payload, $this->secret, null, 'not-a-signature'))->assertUnauthorized();
    $post('/api/v1/webhooks/communication/email/'.Str::uuid(), signedHeaders($payload, $this->secret))->assertNotFound();
    expect(CommunicationConversation::withoutGlobalScopes()->count())->toBe(0);
});

test('a channel without an inbound secret does not accept webhooks', function (): void {
    $this->channel->credentials = null;
    $this->channel->save();
    $payload = ['from' => 'buyer@example.com', 'text' => 'Hi'];

    $this->call('POST', '/api/v1/webhooks/communication/email/'.$this->channel->id, [], [], [], serverFromHeaders(signedHeaders($payload, '')), json_encode($payload))->assertNotFound();
});

test('campaign texts accept the extended placeholders', function (): void {
    $contact = Contact::factory()->create(['tenant_id' => $this->tenant->id, 'email' => 'ok@example.com', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'company_name' => null]);
    Consent::create(['tenant_id' => $this->tenant->id, 'contact_id' => $contact->id, 'channel' => 'email', 'purpose' => 'marketing', 'status' => 'granted', 'source' => 'api', 'captured_at' => now()]);
    $segment = Segment::create(['tenant_id' => $this->tenant->id, 'name' => 'All', 'entity' => 'contacts', 'filters' => [], 'is_active' => true]);

    $id = $this->postJson('/api/v1/communication/campaigns', ['name' => 'Spring', 'channel' => 'email', 'segment_id' => (string) $segment->id, 'subject' => '{{ first_name }} {{last_name}} - {{ campaign_name }} {{ unknown }}', 'body' => '{{ email }}'])
        ->assertCreated()->json('data.id');
    $this->postJson("/api/v1/communication/campaigns/{$id}/send")->assertOk()->assertJsonPath('data.sent_count', 1);

    expect(DeliveryLog::query()->where('message_type', 'campaign')->value('subject'))->toBe('Ada Lovelace - Spring {{ unknown }}');
});

/**
 * @param  array<string, string>  $headers
 * @return array<string, string>
 */
function serverFromHeaders(array $headers): array
{
    $server = [];

    foreach ($headers as $name => $value) {
        $server[$name === 'Content-Type' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return $server;
}

test('the assignees endpoint lists the members of the company only', function (): void {
    $ids = collect($this->getJson('/api/v1/communication/assignees')->assertOk()->json('data'))->pluck('id');

    expect($ids)->toContain((string) $this->admin->id);
});
